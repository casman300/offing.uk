<?php

use App\Features\Calendar as CalendarFeature;
use App\Features\Crews;
use App\Models\AuditLog;
use App\Models\Calendar;
use App\Models\Crew;
use App\Models\CrewMember;
use App\Models\Event as CalendarEvent;
use App\Models\FeedSubscription;
use App\Models\User;
use App\Support\Calendar\Ics\FeedSync;
use App\Support\Feeds\FeedFetcher;
use App\Support\Feeds\HostResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Tests\Support\FakeHostResolver;

const SECRET_URL = 'https://calendar.google.com/calendar/ical/me%40example.com/private-0123456789abcdef/basic.ics';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 12:00', 'Europe/London'));
    // The SSRF guard is what's under test: never inherit a developer's local override.
    config(['offing.feeds.allow_private_hosts' => false]);
    $this->dns = new FakeHostResolver([
        'calendar.google.com' => ['142.250.180.14'],
        'example.org' => ['93.184.215.14'],
        'cdn.example.org' => ['93.184.215.15'],
        'evil.example' => ['127.0.0.1'],
        'intranet.example' => ['93.184.215.16', '10.0.0.5'],
        'metadata.example' => ['169.254.169.254'],
        'v6.example' => ['::1'],
        'mapped.example' => ['::ffff:192.168.1.1'],
    ]);
    $this->app->instance(HostResolver::class, $this->dns);
});

function subscriber(array $attributes = []): User
{
    $user = User::factory()->create(['timezone' => 'Europe/London'] + $attributes);
    Feature::for($user)->activate([CalendarFeature::class, Crews::class]);

    return $user;
}

function googleFixture(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/ics/google.ics'));
}

function subscribeVia(User $user, string $url = SECRET_URL, string $name = 'Work'): FeedSubscription
{
    test()->actingAs($user)->post('/settings/subscriptions', ['url' => $url, 'name' => $name, 'colour' => Calendar::COLOURS[2]])
        ->assertRedirect('/settings/subscriptions');

    return FeedSubscription::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
}

function feedEvents(FeedSubscription $subscription): Collection
{
    return CalendarEvent::query()->where('feed_subscription_id', $subscription->id)->orderBy('id')->get()->keyBy('title');
}

// --- Subscribing --------------------------------------------------------

it('subscribes, fetches straight away and imports the events read-only', function () {
    Http::fake(['calendar.google.com/*' => Http::response(googleFixture(), 200, ['ETag' => '"v1"'])]);
    $me = subscriber();

    $subscription = subscribeVia($me);

    expect($subscription->status)->toBe(FeedSubscription::OK)
        ->and($subscription->host)->toBe('calendar.google.com')
        ->and($subscription->etag)->toBe('"v1"')
        ->and($subscription->event_count)->toBe(4)
        ->and($subscription->calendar->isFeed())->toBeTrue()
        ->and($subscription->calendar->owner_id)->toBe($me->id)
        ->and($subscription->calendar->colour)->toBe(Calendar::COLOURS[2]);

    $events = feedEvents($subscription);
    $series = $events['Standup'];

    expect($events->keys()->sort()->values()->all())->toBe(['One-to-one', 'Standup', 'Standup (moved to Tuesday)', 'Winter conference'])
        ->and($series->source)->toBe('feed')
        ->and($series->external_uid)->toBe('standup-2026@google.com')
        ->and($series->uid)->not->toBe('standup-2026@google.com')
        ->and($series->raw_ical)->toContain('X-GOOGLE-CONFERENCE')
        // The cancelled 30 Nov date is skipped, as is the source's own EXDATE.
        ->and($series->exdates)->toBe(['2026-11-16T09:30:00', '2026-11-30T09:30:00'])
        ->and($events['Standup (moved to Tuesday)']->series_id)->toBe($series->id)
        ->and($events['Standup (moved to Tuesday)']->recurrence_id)->toBe('2026-11-23T09:30:00')
        ->and($events['One-to-one']->visibility)->toBe('private')
        ->and($events['Winter conference']->transparency)->toBe('free');

    Http::assertSent(fn (HttpRequest $r) => $r->url() === SECRET_URL && ! $r->hasHeader('Cookie') && ! $r->hasHeader('Authorization'));
});

it('accepts webcal:// as https://', function () {
    Http::fake(['calendar.google.com/*' => Http::response(googleFixture())]);

    $subscription = subscribeVia(subscriber(), str_replace('https://', 'webcal://', SECRET_URL));

    expect($subscription->url)->toBe(SECRET_URL);
    Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://calendar.google.com/'));
});

it('treats the URL as a secret: encrypted, never shown, never audited', function () {
    Http::fake(['calendar.google.com/*' => Http::response(googleFixture())]);
    $me = subscriber();
    $subscription = subscribeVia($me);

    $stored = DB::table('feed_subscriptions')->where('id', $subscription->id)->value('url');
    expect($stored)->not->toContain('private-0123456789abcdef');

    $page = $this->actingAs($me)->get('/settings/subscriptions')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/subscriptions')
            ->where('subscriptions.0.host', 'calendar.google.com')
            ->missing('subscriptions.0.url'));
    expect($page->getContent())->not->toContain('private-0123456789abcdef');

    $added = AuditLog::query()->where('action', 'feed_subscription.added')->sole();
    expect($added->meta)->toBe(['host' => 'calendar.google.com', 'name' => 'Work'])
        ->and(json_encode($added->meta))->not->toContain('private');

    $this->actingAs($me)->delete("/settings/subscriptions/{$subscription->id}")->assertRedirect();
    $removed = AuditLog::query()->where('action', 'feed_subscription.removed')->sole();
    expect($removed->meta)->toBe(['host' => 'calendar.google.com', 'name' => 'Work']);
});

it('won’t subscribe to the same calendar twice, or to more than the limit', function () {
    Http::fake(['*' => Http::response(googleFixture())]);
    $me = subscriber();
    subscribeVia($me);

    $this->actingAs($me)->post('/settings/subscriptions', ['url' => SECRET_URL, 'name' => 'Again', 'colour' => Calendar::COLOURS[0]])
        ->assertSessionHasErrors('url');

    for ($i = 1; $i < FeedSubscription::MAX_PER_USER; $i++) {
        FeedSubscription::subscribe($me, "https://example.org/{$i}.ics", 'example.org', "Cal {$i}", Calendar::COLOURS[0]);
    }

    $this->actingAs($me)->post('/settings/subscriptions', ['url' => 'https://example.org/more.ics', 'name' => 'More', 'colour' => Calendar::COLOURS[0]])
        ->assertSessionHasErrors(['url' => 'You can subscribe to up to 10 calendars. Remove one first.']);
});

it('is behind the calendar flag', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/subscriptions')->assertNotFound();
    $this->actingAs($user)->post('/settings/subscriptions', ['url' => SECRET_URL, 'name' => 'W', 'colour' => Calendar::COLOURS[0]])->assertNotFound();
    Http::assertNothingSent();
});

// --- SSRF ---------------------------------------------------------------

it('refuses addresses that point inside the network', function (string $url, string $message) {
    Http::fake();

    $this->actingAs(subscriber())->post('/settings/subscriptions', ['url' => $url, 'name' => 'Nope', 'colour' => Calendar::COLOURS[0]])
        ->assertSessionHasErrors(['url' => $message]);

    Http::assertNothingSent();
    expect(FeedSubscription::count())->toBe(0);
})->with([
    'loopback literal' => ['http://127.0.0.1/cal.ics', 'That address points at a private network.'],
    'loopback, other spelling' => ['http://127.1/cal.ics', 'That address’s server name isn’t valid.'],
    'loopback as an integer' => ['http://2130706433/cal.ics', 'That address’s server name isn’t valid.'],
    'loopback in hex' => ['http://0x7f000001/cal.ics', 'That address’s server name isn’t valid.'],
    'private literal' => ['https://192.168.1.10/cal.ics', 'That address points at a private network.'],
    'CGNAT literal' => ['https://100.100.100.200/cal.ics', 'That address points at a private network.'],
    'metadata literal' => ['http://169.254.169.254/latest/meta-data/', 'That address points at a private network.'],
    'IPv6 loopback' => ['http://[::1]/cal.ics', 'That address points at a private network.'],
    'IPv6 unique-local' => ['http://[fd00:ec2::254]/cal.ics', 'That address points at a private network.'],
    'IPv4-mapped IPv6' => ['http://[::ffff:127.0.0.1]/cal.ics', 'That address points at a private network.'],
    'a name that resolves to loopback' => ['https://evil.example/cal.ics', 'That address points at a private network.'],
    'a name with one private address among public ones' => ['https://intranet.example/cal.ics', 'That address points at a private network.'],
    'a name that resolves to metadata' => ['https://metadata.example/cal.ics', 'That address points at a private network.'],
    'a name that resolves to ::1' => ['https://v6.example/cal.ics', 'That address points at a private network.'],
    'a name that resolves to mapped private' => ['https://mapped.example/cal.ics', 'That address points at a private network.'],
    'single-label host' => ['http://localhost/cal.ics', 'That address’s server name isn’t valid.'],
    'unknown name' => ['https://nowhere.example/cal.ics', 'Couldn’t find that server — check the address.'],
    'ftp' => ['ftp://example.org/cal.ics', 'Only http, https and webcal addresses can be subscribed to.'],
    'file' => ['file:///etc/passwd', 'That isn’t a web address.'],
    'credentials in the URL' => ['https://user:pass@example.org/cal.ics', 'Addresses with a username or password in them aren’t supported.'],
    'an unusual port' => ['https://example.org:6379/cal.ics', 'That address uses a port Offing doesn’t fetch from.'],
]);

it('checks every redirect hop again, and limits them', function () {
    $me = subscriber();
    $subscription = FeedSubscription::subscribe($me, 'https://example.org/cal.ics', 'example.org', 'Hops', Calendar::COLOURS[0]);

    // Redirect into the private network: refused before it's fetched.
    Http::fake([
        'example.org/cal.ics' => Http::response('', 302, ['Location' => 'https://evil.example/steal']),
        'evil.example/*' => Http::response(googleFixture()),
    ]);
    app(FeedSync::class)->run($subscription);

    expect($subscription->status)->toBe(FeedSubscription::ERROR)
        ->and($subscription->last_error)->toBe('That address points at a private network.');
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'evil.example'));
});

it('follows a few redirects to a public host, but not endlessly or to http', function () {
    $me = subscriber();

    Http::fake([
        'example.org/cal.ics' => Http::response('', 301, ['Location' => '/moved.ics']),
        'example.org/moved.ics' => Http::response('', 302, ['Location' => 'https://cdn.example.org/final.ics']),
        'cdn.example.org/final.ics' => Http::response(googleFixture()),
    ]);
    $ok = FeedSubscription::subscribe($me, 'https://example.org/cal.ics', 'example.org', 'Ok', Calendar::COLOURS[0]);
    app(FeedSync::class)->run($ok);
    expect($ok->status)->toBe(FeedSubscription::OK);

    Http::fake(['example.org/loop.ics' => Http::response('', 302, ['Location' => 'https://example.org/loop.ics'])]);
    $loop = FeedSubscription::subscribe($me, 'https://example.org/loop.ics', 'example.org', 'Loop', Calendar::COLOURS[0]);
    app(FeedSync::class)->run($loop);
    expect($loop->last_error)->toBe('The calendar’s server redirected too many times.');
    Http::assertSentCount(1 + FeedFetcher::MAX_REDIRECTS);

    Http::fake(['example.org/down.ics' => Http::response('', 302, ['Location' => 'http://example.org/plain.ics'])]);
    $down = FeedSubscription::subscribe($me, 'https://example.org/down.ics', 'example.org', 'Down', Calendar::COLOURS[0]);
    app(FeedSync::class)->run($down);
    expect($down->last_error)->toBe('The calendar’s server redirected from https to an insecure address.');
});

it('allows a loopback fixture server only when the config flag is on', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(googleFixture())]);
    $me = subscriber();

    $this->actingAs($me)->post('/settings/subscriptions', ['url' => 'http://127.0.0.1:8123/cal.ics', 'name' => 'Local', 'colour' => Calendar::COLOURS[0]])
        ->assertSessionHasErrors('url');

    config(['offing.feeds.allow_private_hosts' => true]);

    expect(subscribeVia($me, 'http://127.0.0.1:8123/cal.ics', 'Local')->status)->toBe(FeedSubscription::OK);
});

// --- Fetching: conditional GET, hash, back-off, limits ----------------------

it('sends the ETag and leaves everything alone on 304', function () {
    Http::fake(['calendar.google.com/*' => Http::sequence()
        ->push(googleFixture(), 200, ['ETag' => '"v1"'])
        ->push('', 304)]);
    $subscription = subscribeVia(subscriber());
    $before = feedEvents($subscription)->map->updated_at->map->toIso8601String()->all();
    $changedAt = $subscription->last_changed_at;

    $this->travel(2)->hours();
    app(FeedSync::class)->run($subscription);

    Http::assertSent(fn (HttpRequest $r) => $r->header('If-None-Match') === ['"v1"']);
    expect($subscription->status)->toBe(FeedSubscription::OK)
        ->and($subscription->last_changed_at->equalTo($changedAt))->toBeTrue()
        ->and($subscription->last_fetched_at->greaterThan($changedAt))->toBeTrue()
        ->and(feedEvents($subscription->fresh())->map->updated_at->map->toIso8601String()->all())->toBe($before);
});

it('skips parsing when the body hasn’t changed', function () {
    Http::fake(['calendar.google.com/*' => Http::response(googleFixture())]);
    $subscription = subscribeVia(subscriber());
    $changedAt = $subscription->last_changed_at;

    // A local edit would be overwritten by a real re-parse; an unchanged body doesn't touch it.
    CalendarEvent::query()->where('feed_subscription_id', $subscription->id)->where('title', 'Standup')->update(['title' => 'Untouched']);

    $this->travel(2)->hours();
    app(FeedSync::class)->run($subscription);

    expect($subscription->last_changed_at->equalTo($changedAt))->toBeTrue()
        ->and(feedEvents($subscription)->has('Untouched'))->toBeTrue();
});

it('updates by UID and removes what the source dropped', function () {
    $updated = str_replace(['SUMMARY:Winter conference', "SUMMARY:One-to-one\r\n"], ['SUMMARY:Winter summit', "SUMMARY:One-to-one\r\nX-GONE:1\r\n"], googleFixture());
    // Drop the moved date: the series gets its 23 Nov back.
    $updated = preg_replace('/BEGIN:VEVENT\r\n(?:(?!END:VEVENT).)*moved to Tuesday(?:(?!END:VEVENT).)*END:VEVENT\r\n/s', '', $updated);

    Http::fake(['calendar.google.com/*' => Http::sequence()->push(googleFixture())->push($updated)]);
    $subscription = subscribeVia(subscriber());
    $before = feedEvents($subscription);

    app(FeedSync::class)->run($subscription);
    $after = feedEvents($subscription);

    expect($after->keys()->sort()->values()->all())->toBe(['One-to-one', 'Standup', 'Winter summit'])
        ->and($after['Winter summit']->id)->toBe($before['Winter conference']->id)
        ->and($after['Standup']->id)->toBe($before['Standup']->id)
        ->and($after['One-to-one']->raw_ical)->toContain('X-GONE')
        ->and(CalendarEvent::withTrashed()->find($before['Standup (moved to Tuesday)']->id))->toBeNull()
        ->and($subscription->event_count)->toBe(3);
});

it('removes events a METHOD:CANCEL or STATUS:CANCELLED feed cancels', function () {
    $cancelled = str_replace("SUMMARY:Winter conference\r\n", "SUMMARY:Winter conference\r\nSTATUS:CANCELLED\r\n", str_replace('STATUS:CONFIRMED', '', googleFixture()));

    Http::fake(['calendar.google.com/*' => Http::sequence()->push(googleFixture())->push($cancelled)->push(file_get_contents(base_path('tests/Fixtures/ics/cancel.ics')))]);
    $subscription = subscribeVia(subscriber());

    app(FeedSync::class)->run($subscription);
    expect(feedEvents($subscription)->has('Winter conference'))->toBeFalse()
        ->and(feedEvents($subscription)->has('Standup'))->toBeTrue();

    app(FeedSync::class)->run($subscription);
    expect(feedEvents($subscription))->toBeEmpty();
});

it('backs off after failures and shows why', function () {
    $healthy = false;
    Http::fake(['calendar.google.com/*' => function () use (&$healthy) {
        return $healthy ? Http::response(googleFixture()) : Http::response('nope', 500);
    }]);
    $me = subscriber();
    $subscription = subscribeVia($me);

    expect($subscription->status)->toBe(FeedSubscription::ERROR)
        ->and($subscription->failures)->toBe(1)
        ->and($subscription->last_error)->toBe('The calendar’s server answered with an error (HTTP 500).')
        ->and($subscription->next_fetch_at->diffInMinutes(now()->toImmutable(), true))->toEqual(15);

    app(FeedSync::class)->run($subscription);
    app(FeedSync::class)->run($subscription);
    expect($subscription->failures)->toBe(3)
        ->and($subscription->next_fetch_at->diffInMinutes(now()->toImmutable(), true))->toEqual(60);

    for ($i = 0; $i < 10; $i++) {
        app(FeedSync::class)->run($subscription);
    }
    expect($subscription->next_fetch_at->diffInMinutes(now()->toImmutable(), true))->toEqual(1440);

    $this->actingAs($me)->get('/settings/subscriptions')->assertInertia(fn (Assert $page) => $page
        ->where('subscriptions.0.status', 'error')
        ->where('subscriptions.0.last_error', 'The calendar’s server answered with an error (HTTP 500).'));

    // Recovery resets it.
    $healthy = true;
    app(FeedSync::class)->run($subscription);
    expect($subscription->status)->toBe(FeedSubscription::OK)
        ->and($subscription->failures)->toBe(0)
        ->and($subscription->last_error)->toBeNull();
});

it('fails clearly on a gone feed, a web page, or one that’s too big', function (int $status, string $body, array $headers, string $message) {
    Http::fake(['calendar.google.com/*' => Http::response($body, $status, $headers)]);
    config(['offing.feeds.max_bytes' => 1024]);

    $subscription = subscribeVia(subscriber());

    expect($subscription->status)->toBe(FeedSubscription::ERROR)
        ->and($subscription->last_error)->toBe($message);
})->with([
    'gone' => [404, '', [], 'The calendar wasn’t found there any more (HTTP 404). It may have been deleted or its link reset.'],
    'forbidden' => [403, '', [], 'The calendar’s server refused access (HTTP 403). If it’s a secret address, it may have been reset.'],
    'html' => [200, '<html>Sign in</html>', [], 'That address didn’t return a calendar. Check it’s the iCal (.ics) link, not a web page.'],
    'too big' => [200, 'BEGIN:VCALENDAR'.str_repeat('x', 2000), [], 'The calendar is too big to import (over 1 KB).'],
    'says it’s too big' => [200, 'BEGIN:VCALENDAR', ['Content-Length' => '999999999'], 'The calendar is too big to import (over 1 KB).'],
]);

it('unpacks gzip within the size cap', function () {
    Http::fake(['calendar.google.com/*' => Http::response(gzencode(googleFixture()), 200, ['Content-Encoding' => 'gzip'])]);

    expect(subscribeVia(subscriber())->event_count)->toBe(4);

    config(['offing.feeds.max_bytes' => 1024]);
    Http::fake(['example.org/*' => Http::response(gzencode('BEGIN:VCALENDAR'.str_repeat('A', 100000)), 200, ['Content-Encoding' => 'gzip'])]);
    $bomb = FeedSubscription::subscribe(subscriber(), 'https://example.org/bomb.ics', 'example.org', 'Bomb', Calendar::COLOURS[0]);
    app(FeedSync::class)->run($bomb);
    expect($bomb->status)->toBe(FeedSubscription::ERROR);
});

it('keeps the good events and notes the bad ones', function () {
    Http::fake(['calendar.google.com/*' => Http::response(file_get_contents(base_path('tests/Fixtures/ics/broken.ics')))]);

    $subscription = subscribeVia(subscriber());

    expect($subscription->status)->toBe(FeedSubscription::OK)
        ->and($subscription->event_count)->toBe(1)
        ->and($subscription->last_error)->toStartWith('Some events couldn’t be read and were skipped');
});

it('fetches only what’s due from the scheduler, and only for people with the calendar on', function () {
    Http::fake(['*' => Http::response(googleFixture())]);
    $me = subscriber();
    $due = FeedSubscription::subscribe($me, 'https://example.org/due.ics', 'example.org', 'Due', Calendar::COLOURS[0]);
    $later = FeedSubscription::subscribe($me, 'https://example.org/later.ics', 'example.org', 'Later', Calendar::COLOURS[0]);
    $later->forceFill(['next_fetch_at' => now()->addMinutes(30)])->save();

    $off = User::factory()->create();
    $dark = FeedSubscription::subscribe($off, 'https://example.org/dark.ics', 'example.org', 'Dark', Calendar::COLOURS[0]);

    $this->artisan('feeds:sync')->assertSuccessful();

    expect($due->fresh()->status)->toBe(FeedSubscription::OK)
        ->and($due->fresh()->next_fetch_at->diffInMinutes(now()->toImmutable(), true))->toEqual(60)
        ->and($later->fresh()->status)->toBe(FeedSubscription::PENDING)
        ->and($dark->fresh()->status)->toBe(FeedSubscription::PENDING);
    Http::assertSentCount(1);
});

it('is on the schedule', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('feeds:sync');
});

// --- Settings: rename, recolour, refresh, remove ---------------------------

it('renames and recolours the subscription and its calendar together', function () {
    Http::fake(['*' => Http::response(googleFixture())]);
    $me = subscriber();
    $subscription = subscribeVia($me);

    $this->actingAs($me)->patch("/settings/subscriptions/{$subscription->id}", ['name' => 'Office', 'colour' => Calendar::COLOURS[5]])->assertRedirect();

    expect($subscription->fresh()->name)->toBe('Office')
        ->and($subscription->calendar->fresh()->name)->toBe('Office')
        ->and($subscription->calendar->fresh()->colour)->toBe(Calendar::COLOURS[5]);
});

it('refreshes on demand, but not twice in a minute', function () {
    Http::fake(['*' => Http::response(googleFixture())]);
    $me = subscriber();
    $subscription = subscribeVia($me);

    $this->actingAs($me)->post("/settings/subscriptions/{$subscription->id}/refresh")->assertSessionHas('status', '“Work” was refreshed moments ago.');
    Http::assertSentCount(1);

    $this->travel(2)->minutes();
    $this->actingAs($me)->post("/settings/subscriptions/{$subscription->id}/refresh")->assertSessionHas('status', 'Refreshed “Work”.');
    Http::assertSentCount(2);
});

it('removes the subscription, its calendar and every event from it', function () {
    Http::fake(['*' => Http::response(googleFixture())]);
    $me = subscriber();
    $subscription = subscribeVia($me);
    $calendarId = $subscription->calendar->id;

    $this->actingAs($me)->delete("/settings/subscriptions/{$subscription->id}")->assertRedirect();

    expect(FeedSubscription::count())->toBe(0)
        ->and(Calendar::withTrashed()->find($calendarId))->toBeNull()
        ->and(CalendarEvent::withTrashed()->where('calendar_id', $calendarId)->count())->toBe(0);
});

// --- Read-only and private --------------------------------------------------

it('shows feed events in the views, in the subscription’s colour, read-only', function () {
    Http::fake(['*' => Http::response(googleFixture())]);
    $me = subscriber();
    $subscription = subscribeVia($me);

    $this->actingAs($me)->get('/calendar')->assertInertia(fn (Assert $page) => $page
        ->where('calendars', fn ($calendars) => collect($calendars)->contains(fn ($c) => $c['is_feed'] && $c['name'] === 'Work' && ! $c['can_edit'])));

    $items = collect($this->actingAs($me)->getJson('/calendar/occurrences?start=2026-11-23&end=2026-12-08')->assertOk()->json());
    $standups = $items->where('calendar_id', $subscription->calendar->id);

    expect($standups->pluck('title')->all())->toContain('Standup (moved to Tuesday)', 'One-to-one')
        ->and($standups->pluck('start')->all())->not->toContain('2026-11-23T09:30:00', '2026-11-30T09:30:00')
        ->and($standups->pluck('start')->all())->toContain('2026-11-24T11:00:00', '2026-12-07T09:30:00')
        ->and($standups->every(fn ($i) => $i['colour'] === Calendar::COLOURS[2] && $i['can_edit'] === false))->toBeTrue()
        // The subscriber's own private event: details visible to them.
        ->and($standups->firstWhere('title', 'One-to-one')['busy_block'])->toBeFalse();
});

it('refuses every edit to a feed event', function () {
    Http::fake(['*' => Http::response(googleFixture())]);
    $me = subscriber();
    $subscription = subscribeVia($me);
    $events = feedEvents($subscription);
    $series = $events['Standup'];
    $oneOff = $events['One-to-one'];
    $calendar = $subscription->calendar;

    $this->actingAs($me)->get("/calendar/events/{$series->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('event.can_edit', false));
    $this->actingAs($me)->get("/calendar/events/{$series->id}/edit")->assertForbidden();
    $this->actingAs($me)->get("/calendar/events/{$series->id}/one/edit?at=2026-12-07T09:30:00")->assertForbidden();
    $this->actingAs($me)->put("/calendar/events/{$oneOff->id}", ['title' => 'Mine now'])->assertForbidden();
    $this->actingAs($me)->post("/calendar/events/{$series->id}/one", ['occurrence' => '2026-12-07T09:30:00'])->assertForbidden();
    $this->actingAs($me)->post("/calendar/events/{$oneOff->id}/move", ['start' => '2026-11-28T10:00:00', 'end' => '2026-11-28T11:00:00', 'all_day' => false, 'scope' => 'one'])->assertForbidden();
    $this->actingAs($me)->post("/calendar/events/{$series->id}/skip", ['occurrence' => '2026-12-07T09:30:00'])->assertForbidden();
    $this->actingAs($me)->delete("/calendar/events/{$oneOff->id}")->assertForbidden();
    $this->actingAs($me)->post('/calendar/events', [
        'calendar_id' => $calendar->id, 'title' => 'Sneaky', 'all_day' => false, 'tzid' => 'Europe/London',
        'start_date' => '2026-11-30', 'start_time' => '12:00', 'end_date' => '2026-11-30', 'end_time' => '13:00',
        'repeat' => ['freq' => 'none'],
    ])->assertSessionHasErrors('calendar_id');
    $this->actingAs($me)->patch("/calendar/calendars/{$calendar->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->actingAs($me)->delete("/calendar/calendars/{$calendar->id}")->assertForbidden();

    expect($oneOff->fresh()->title)->toBe('One-to-one')
        ->and($oneOff->fresh()->trashed())->toBeFalse()
        ->and($series->fresh()->exdates)->toBe(['2026-11-16T09:30:00', '2026-11-30T09:30:00'])
        ->and($calendar->events()->count())->toBe(4);
});

it('can’t import a file into a subscribed calendar', function () {
    Http::fake(['*' => Http::response(googleFixture())]);
    $me = subscriber();
    $subscription = subscribeVia($me);

    $this->actingAs($me)->post('/calendar/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('a.ics', googleFixture()),
        'calendar_id' => $subscription->calendar->id,
    ])->assertForbidden();
});

it('shows a subscription to nobody but the subscriber — not even their crew', function () {
    Http::fake(['*' => Http::response(googleFixture())]);
    $me = subscriber();
    $mate = subscriber();
    $stranger = subscriber();
    $crew = Crew::create(['name' => 'Home']);
    $crew->members()->create(['user_id' => $me->id, 'role' => CrewMember::CAPTAIN, 'joined_at' => now()]);
    $crew->members()->create(['user_id' => $mate->id, 'role' => CrewMember::CREW, 'joined_at' => now()]);

    $subscription = subscribeVia($me);
    $event = feedEvents($subscription)['Standup'];

    foreach ([$mate, $stranger] as $other) {
        $this->actingAs($other)->get("/calendar/events/{$event->id}")->assertNotFound();
        $this->actingAs($other)->get("/calendar/events/{$event->id}/edit")->assertNotFound();
        $this->actingAs($other)->delete("/calendar/events/{$event->id}")->assertNotFound();
        $this->actingAs($other)->patch("/settings/subscriptions/{$subscription->id}", ['name' => 'Mine', 'colour' => Calendar::COLOURS[0]])->assertNotFound();
        $this->actingAs($other)->post("/settings/subscriptions/{$subscription->id}/refresh")->assertNotFound();
        $this->actingAs($other)->delete("/settings/subscriptions/{$subscription->id}")->assertNotFound();

        $items = $this->actingAs($other)->getJson('/calendar/occurrences?start=2026-11-23&end=2026-12-07')->json();
        expect(collect($items)->pluck('calendar_id')->all())->not->toContain($subscription->calendar->id);

        $this->actingAs($other)->get('/settings/subscriptions')->assertInertia(fn (Assert $p) => $p->where('subscriptions', []));
        $this->actingAs($other)->get('/calendar')->assertInertia(fn (Assert $p) => $p
            ->where('calendars', fn ($cs) => ! collect($cs)->contains('id', $subscription->calendar->id)));
    }

    expect($subscription->fresh()->name)->toBe('Work');
});

it('goes when the subscriber deletes their account', function () {
    Http::fake(['*' => Http::response(googleFixture())]);
    $me = subscriber();
    $subscription = subscribeVia($me);
    $calendarId = $subscription->calendar->id;

    $me->delete();

    expect(FeedSubscription::count())->toBe(0)
        ->and(Calendar::withTrashed()->find($calendarId))->toBeNull()
        ->and(CalendarEvent::withTrashed()->where('calendar_id', $calendarId)->count())->toBe(0);
});

it('never makes a feed calendar someone’s default calendar', function () {
    $me = subscriber();
    FeedSubscription::subscribe($me, 'https://example.org/a.ics', 'example.org', 'Feed', Calendar::COLOURS[0]);

    $personal = Calendar::ensurePersonal($me);

    expect($personal->isFeed())->toBeFalse()
        ->and($personal->name)->toBe('My calendar');
});

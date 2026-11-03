<?php

use App\Models\AuditLog;
use App\Models\Event as CalendarEvent;
use App\Models\FeedToken;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'Europe/London'));
});

/** Mint a token through the real settings flow and return its plaintext URL path. */
function mintFeed(User $user, string $name = 'My iPhone'): string
{
    test()->actingAs($user)->post('/settings/feeds', ['name' => $name])->assertRedirect('/settings/feeds');

    $url = session('feedUrl')['url'];
    app('auth')->forgetGuards();

    return parse_url($url, PHP_URL_PATH);
}

function parseFeed(string $ics): VCalendar
{
    $calendar = Reader::read($ics);
    expect($calendar)->toBeInstanceOf(VCalendar::class);

    return $calendar;
}

describe('settings', function () {
    it('mints a link, shows it once, and audits it', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/feeds', ['name' => 'My iPhone'])
            ->assertRedirect('/settings/feeds')
            ->assertSessionHas('feedUrl');

        $url = session('feedUrl')['url'];
        expect($url)->toMatch('#/feed/[A-Za-z0-9]{40}\.ics$#');

        $token = FeedToken::sole();
        expect($token->subject->is($user))->toBeTrue()
            ->and($token->name)->toBe('My iPhone')
            ->and($token->token_hash)->not->toContain(basename($url, '.ics'))
            ->and(AuditLog::where('action', 'feed_token.created')->where('subject_id', $token->id)->exists())->toBeTrue();

        // The listing never carries the token or its hash.
        $this->actingAs($user)->get('/settings/feeds')
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/feeds')
                ->has('tokens', 1)
                ->where('tokens.0.name', 'My iPhone')
                ->missing('tokens.0.token_hash')
                ->where('maxActive', FeedToken::MAX_ACTIVE));
    });

    it('caps active links per user', function () {
        $user = User::factory()->create();

        foreach (range(1, FeedToken::MAX_ACTIVE) as $i) {
            $this->actingAs($user)->post('/settings/feeds', ['name' => "Link {$i}"])->assertSessionHasNoErrors();
        }

        $this->actingAs($user)->post('/settings/feeds', ['name' => 'One too many'])->assertSessionHasErrors('name');
        expect(FeedToken::count())->toBe(FeedToken::MAX_ACTIVE);

        // Revoking frees a slot.
        $this->actingAs($user)->delete('/settings/feeds/'.FeedToken::first()->id);
        $this->actingAs($user)->post('/settings/feeds', ['name' => 'Replacement'])->assertSessionHasNoErrors();
    });

    it('requires a name', function () {
        $this->actingAs(User::factory()->create())->post('/settings/feeds', ['name' => ''])->assertSessionHasErrors('name');
        expect(FeedToken::count())->toBe(0);
    });

    it('revokes my own link and audits it, but 404s on someone else’s', function () {
        $me = User::factory()->create();
        $them = User::factory()->create();
        [$mine] = FeedToken::mint($me, 'Mine');
        [$theirs] = FeedToken::mint($them, 'Theirs');

        $this->actingAs($me)->delete("/settings/feeds/{$theirs->id}")->assertNotFound();
        expect($theirs->fresh()->revoked_at)->toBeNull();

        $this->actingAs($me)->delete("/settings/feeds/{$mine->id}")->assertRedirect('/settings/feeds');
        expect($mine->fresh()->revoked_at)->not->toBeNull()
            ->and(AuditLog::where('action', 'feed_token.revoked')->where('subject_id', $mine->id)->exists())->toBeTrue();

        $this->actingAs($me)->get('/settings/feeds')->assertInertia(fn (Assert $page) => $page->has('tokens', 0));
    });

    it('is not behind the birthday-engine flag', function () {
        $this->actingAs(User::factory()->create())->get('/settings/feeds')->assertOk();
    });

    it('redirects guests to login', function () {
        $this->get('/settings/feeds')->assertRedirect('/login');
    });
});

describe('feed', function () {
    it('serves valid iCalendar with one yearly all-day event per dated birthday', function () {
        $user = User::factory()->create();
        $sue = Person::factory()->for($user, 'owner')->create(['name' => 'Sue', 'birth_day' => 14, 'birth_month' => 3, 'birth_year' => 1983]);
        Person::factory()->for($user, 'owner')->create(['name' => 'Bob', 'birth_day' => 2, 'birth_month' => 11, 'birth_year' => null]);
        Person::factory()->for($user, 'owner')->create(['name' => 'Gran', 'birth_day' => null, 'birth_month' => null, 'birth_year' => 1950]);
        Person::factory()->for($user, 'owner')->create(['name' => 'Gone', 'birth_day' => 1, 'birth_month' => 1])->delete();
        Person::factory()->for(User::factory(), 'owner')->create(['name' => 'Stranger', 'birth_day' => 5, 'birth_month' => 5]);

        $response = $this->get(mintFeed($user))->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('text/calendar; charset=utf-8')
            ->and($response->headers->getCookies())->toBe([]);

        $calendar = parseFeed($response->getContent());
        expect($calendar->validate())->toBe([]);

        $events = collect($calendar->VEVENT);
        expect($events->map(fn ($e) => (string) $e->SUMMARY)->sort()->values()->all())
            ->toBe(["Bob's birthday 🎂", "Sue's birthday 🎂"]);

        $event = $events->first(fn ($e) => str_starts_with((string) $e->SUMMARY, 'Sue'));
        expect((string) $event->RRULE)->toBe('FREQ=YEARLY')
            ->and($event->DTSTART->hasTime())->toBeFalse()
            ->and((string) $event->DTSTART)->toBe('19830314')
            ->and((string) $event->DTEND)->toBe('19830315')
            ->and((string) $event->TRANSP)->toBe('TRANSPARENT')
            ->and((string) $event->DESCRIPTION)->toBe('Born 1983.')
            ->and((string) $event->UID)->toBe("person-{$sue->id}-birthday@".parse_url(config('app.url'), PHP_URL_HOST));

        // Default reminder rule: 20:00 the evening before, 08:00 on the day.
        expect(collect($event->VALARM)->map(fn ($a) => (string) $a->TRIGGER)->all())->toBe(['-PT240M', 'PT480M']);

        $bob = $events->first(fn ($e) => str_starts_with((string) $e->SUMMARY, 'Bob'));
        expect($bob->DESCRIPTION)->toBeNull();
    });

    it('carries the user’s events: UTC times, plain dates, and both flags', function () {
        $user = User::factory()->create();
        $f = fn () => CalendarEvent::factory()->ownedBy($user);
        $dentist = $f()->at('2026-10-20 09:00', '2026-10-20 09:30')->create(['title' => 'Dentist', 'location' => 'The Surgery', 'description' => 'Bring the form']);
        $f()->allDay('2026-10-24', '2026-10-31')->create(['title' => 'Cornwall']);
        $f()->private()->create(['title' => 'Pick up flowers']);
        $f()->free()->create(['title' => 'Gift shopping']);
        $f()->create(['title' => 'Cancelled'])->delete();
        CalendarEvent::factory()->ownedBy(User::factory()->create())->create(['title' => 'Stranger’s']);

        $dentist->forceFill(['sequence' => 2])->save();

        $calendar = parseFeed($this->get(mintFeed($user))->assertOk()->getContent());
        expect($calendar->validate())->toBe([])
            ->and($calendar->VTIMEZONE)->toBeNull();

        $events = collect($calendar->VEVENT)->keyBy(fn ($e) => (string) $e->SUMMARY);
        expect($events->keys()->sort()->values()->all())->toBe(['Cornwall', 'Dentist', 'Gift shopping', 'Pick up flowers']);

        $event = $events['Dentist'];
        expect((string) $event->DTSTART)->toBe('20261020T080000Z')
            ->and((string) $event->DTEND)->toBe('20261020T083000Z')
            ->and((string) $event->LOCATION)->toBe('The Surgery')
            ->and((string) $event->DESCRIPTION)->toBe('Bring the form')
            ->and((string) $event->SEQUENCE)->toBe('2')
            ->and((string) $event->UID)->toBe($dentist->uid)
            ->and($event->CLASS)->toBeNull()
            ->and($event->TRANSP === null || (string) $event->TRANSP === 'OPAQUE')->toBeTrue();

        expect($events['Cornwall']->DTSTART->hasTime())->toBeFalse()
            ->and((string) $events['Cornwall']->DTSTART)->toBe('20261024')
            ->and((string) $events['Cornwall']->DTEND)->toBe('20261101');

        expect((string) $events['Pick up flowers']->CLASS)->toBe('PRIVATE')
            ->and((string) $events['Gift shopping']->TRANSP)->toBe('TRANSPARENT');
    });

    it('observes 29 February birthdays on 28 February in non-leap years, like the app', function () {
        $user = User::factory()->create();
        $leapling = Person::factory()->for($user, 'owner')->create(['name' => 'Leo', 'birth_day' => 29, 'birth_month' => 2, 'birth_year' => 1996]);

        $calendar = parseFeed($this->get(mintFeed($user))->getContent())
            ->expand(new DateTime('2026-01-01'), new DateTime('2029-01-01'));

        $dates = collect($calendar->VEVENT)->map(fn ($e) => $e->DTSTART->getDateTime()->format('Y-m-d'))->all();
        $app = collect([2026, 2027, 2028])->map(fn ($y) => $leapling->birthday()->observedIn($y)->toDateString())->all();

        expect($dates)->toBe(['2026-02-28', '2027-02-28', '2028-02-29'])->toBe($app);
    });

    it('answers a matching If-None-Match with 304 and changes ETag when a birthday changes', function () {
        $user = User::factory()->create();
        $sue = Person::factory()->for($user, 'owner')->create(['name' => 'Sue', 'birth_day' => 14, 'birth_month' => 3]);
        $path = mintFeed($user);

        $etag = $this->get($path)->headers->get('ETag');
        $this->travel(10)->minutes();
        expect($this->get($path)->headers->get('ETag'))->toBe($etag);

        $this->get($path, ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');

        $this->travel(1)->minutes();
        $sue->update(['birth_day' => 15]);
        $this->get($path, ['If-None-Match' => $etag])->assertOk();
    });

    it('serves regardless of the birthday-engine flag', function () {
        $user = User::factory()->create();
        Person::factory()->for($user, 'owner')->create(['birth_day' => 1, 'birth_month' => 6]);

        $this->get(mintFeed($user))->assertOk()->assertSee('BEGIN:VEVENT');
    });

    it('404s for unknown, malformed and revoked tokens', function () {
        $user = User::factory()->create();
        $path = mintFeed($user);

        $this->get('/feed/'.str_repeat('a', 40).'.ics')->assertNotFound();
        $this->get('/feed/short.ics')->assertNotFound();

        FeedToken::sole()->forceFill(['revoked_at' => now()])->save();
        $this->get($path)->assertNotFound();
    });

    it('records when and by what each link was last fetched, without hammering the row', function () {
        $path = mintFeed(User::factory()->create());

        $this->get($path, ['User-Agent' => 'Google-Calendar-Importer']);
        $token = FeedToken::sole();
        expect($token->last_used_at->equalTo(now()))->toBeTrue()
            ->and($token->last_user_agent)->toBe('Google-Calendar-Importer');

        $updatedAt = $token->updated_at;
        $this->travel(2)->minutes();
        $this->get($path, ['User-Agent' => 'Google-Calendar-Importer']);
        expect(FeedToken::sole()->last_used_at->equalTo($token->last_used_at))->toBeTrue();

        $this->travel(FeedToken::USAGE_RESOLUTION_MINUTES)->minutes();
        $this->get($path, ['User-Agent' => 'Google-Calendar-Importer']);
        $fresh = FeedToken::sole();
        expect($fresh->last_used_at->equalTo(now()))->toBeTrue()
            ->and($fresh->updated_at->equalTo($updatedAt))->toBeTrue();
    });
});

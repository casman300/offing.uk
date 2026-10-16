<?php

use App\Features\BirthdayEngine;
use App\Features\Reminders;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\Person;
use App\Models\User;
use App\Support\Reminders\OutboxDrainer;
use App\Support\Reminders\ReminderScanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/** A user with both flags and reminders switched on at $since (London time). */
function reminderUser(string $since = '2026-01-01 00:00', array $attributes = []): User
{
    $user = User::factory()->create(['email' => 'me@example.com', ...$attributes]);
    Feature::for($user)->activate([BirthdayEngine::class, Reminders::class]);
    $user->reminderSlots();
    $user->forceFill(['reminders_enabled_at' => CarbonImmutable::parse($since, $user->timezone ?? 'Europe/London')])->save();

    return $user->fresh();
}

function at(string $local, string $tz = 'Europe/London'): CarbonImmutable
{
    return CarbonImmutable::parse($local, $tz)->utc();
}

function scan(string $local, string $tz = 'Europe/London'): array
{
    test()->travelTo(at($local, $tz));

    return (new ReminderScanner)->scan(at($local, $tz));
}

function drain(string $local, string $tz = 'Europe/London'): array
{
    test()->travelTo(at($local, $tz));

    return (new OutboxDrainer)->drain(at($local, $tz));
}

/** Messages captured by the real `array` mail transport. */
function sentMail(): array
{
    return iterator_to_array(app('mailer')->getSymfonyTransport()->messages());
}

beforeEach(function () {
    config(['mail.default' => 'array']);
    $this->travelTo(at('2026-10-16 12:00'));
});

describe('scanner', function () {
    it('does nothing while reminders are off (the default)', function () {
        $user = User::factory()->create();
        Feature::for($user)->activate([BirthdayEngine::class, Reminders::class]);
        Person::factory()->for($user, 'owner')->birthday(17, 10, 1983)->create();

        expect(scan('2026-10-16 20:00'))->toBe(['queued' => 0, 'skipped' => 0])
            ->and(OutboxMessage::count())->toBe(0);
    });

    it('queues the day-before reminder at 20:00 local, once', function () {
        $user = reminderUser();
        $sue = Person::factory()->for($user, 'owner')->birthday(17, 10, 1983)->create(['name' => 'Sue']);

        expect(scan('2026-10-16 19:59')['queued'])->toBe(0)
            ->and(scan('2026-10-16 20:00')['queued'])->toBe(1)
            ->and(scan('2026-10-16 20:05')['queued'])->toBe(0);

        $row = OutboxMessage::sole();
        expect($row->status)->toBe('pending')
            // 20:00 BST is 19:00 UTC.
            ->and($row->send_at->toIso8601String())->toBe('2026-10-16T19:00:00+00:00')
            ->and($row->dedupe_key)->toBe("rule:{$user->reminderRules()->where('slot', 'day_before')->value('id')}|person:{$sue->id}|2026-10-17")
            ->and($row->payload)->toBe(['person_id' => $sue->id, 'date' => '2026-10-17', 'turns' => 43, 'slot' => 'day_before', 'days_before' => 1]);
    });

    it('queues the on-the-day reminder at 08:00 local across BST and GMT', function () {
        $user = reminderUser();
        Person::factory()->for($user, 'owner')->birthday(1, 7, null)->create();
        Person::factory()->for($user, 'owner')->birthday(1, 12, null)->create();

        scan('2027-07-01 08:00');
        scan('2027-12-01 08:00');

        expect(OutboxMessage::where('payload->slot', 'on_the_day')->orderBy('send_at')->pluck('send_at')->map->toIso8601String()->all())
            ->toBe(['2027-07-01T07:00:00+00:00', '2027-12-01T08:00:00+00:00']);
    });

    it('sends up to two hours late, records anything later as skipped', function () {
        $user = reminderUser();
        Person::factory()->for($user, 'owner')->birthday(17, 10, null)->create();
        Person::factory()->for($user, 'owner')->birthday(18, 10, null)->create();

        // Cron was down: first scan is 1h after the 17 Oct day-before (16th 20:00) and 25h after... nothing for the 18th yet.
        expect(scan('2026-10-16 21:55'))->toBe(['queued' => 1, 'skipped' => 0]);

        // Down again until the 17th 22:30: the 18th's day-before (17th 20:00) is 2.5h late.
        expect(scan('2026-10-17 22:30'))->toBe(['queued' => 0, 'skipped' => 2]);
        expect(OutboxMessage::where('status', 'skipped')->count())->toBe(2)
            ->and(OutboxMessage::where('status', 'skipped')->first()->last_error)->toContain('late');
    });

    it('never fires reminders that came due before you switched them on', function () {
        $user = reminderUser('2026-10-16 21:00');
        Person::factory()->for($user, 'owner')->birthday(17, 10, null)->create();

        expect(scan('2026-10-16 21:05'))->toBe(['queued' => 0, 'skipped' => 0]);
    });

    it('respects the flags, slot switches and custom times', function () {
        $user = reminderUser();
        Person::factory()->for($user, 'owner')->birthday(17, 10, null)->create();
        $rules = $user->reminderSlots();
        $rules['day_before']->update(['enabled' => false]);
        $rules['on_the_day']->update(['offset_def' => ['days_before' => 0, 'time' => '06:45']]);

        expect(scan('2026-10-16 20:00')['queued'])->toBe(0)
            ->and(scan('2026-10-17 06:44')['queued'])->toBe(0)
            ->and(scan('2026-10-17 06:45')['queued'])->toBe(1);

        Feature::for($user)->deactivate(Reminders::class);
        Feature::flushCache();
        Person::factory()->for($user, 'owner')->birthday(18, 10, null)->create();

        expect(scan('2026-10-18 06:45')['queued'])->toBe(0);
    });

    it('ignores people without a birthday date', function () {
        $user = reminderUser();
        Person::factory()->for($user, 'owner')->withoutBirthday()->create();
        Person::factory()->for($user, 'owner')->create(['birth_day' => null, 'birth_month' => null, 'birth_year' => 1950]);

        expect(scan('2026-10-16 20:00')['queued'])->toBe(0);
    });

    it('reminds leaplings on 28 February in non-leap years', function () {
        $user = reminderUser();
        Person::factory()->for($user, 'owner')->birthday(29, 2, 2000)->create();

        expect(scan('2027-02-27 20:00')['queued'])->toBe(1)
            ->and(OutboxMessage::sole()->payload['date'])->toBe('2027-02-28')
            ->and(OutboxMessage::sole()->payload['turns'])->toBe(27);
    });

    it('uses each user’s own timezone', function () {
        $user = reminderUser('2026-01-01', ['timezone' => 'America/Los_Angeles']);
        Person::factory()->for($user, 'owner')->birthday(17, 10, null)->create();

        expect(scan('2026-10-16 19:59', 'America/Los_Angeles')['queued'])->toBe(0)
            ->and(scan('2026-10-16 20:00', 'America/Los_Angeles')['queued'])->toBe(1)
            ->and(OutboxMessage::sole()->send_at->toIso8601String())->toBe('2026-10-17T03:00:00+00:00');
    });
});

describe('drainer and email', function () {
    it('sends one email per send time listing everyone, with unsubscribe headers and no notes', function () {
        $user = reminderUser();
        Person::factory()->for($user, 'owner')->birthday(17, 10, 1983)->create(['name' => 'Auntie Sue', 'notes' => 'Gate code 1234']);
        Person::factory()->for($user, 'owner')->birthday(17, 10, null)->create(['name' => 'Tom [work]']);
        scan('2026-10-16 20:00');

        expect(drain('2026-10-16 20:01'))->toBe(['sent' => 2, 'skipped' => 0, 'failed' => 0, 'retrying' => 0]);

        $mail = sentMail();
        expect($mail)->toHaveCount(1);
        $message = $mail[0]->getOriginalMessage();
        $html = $message->getHtmlBody();
        $text = $message->getTextBody();

        expect($message->getSubject())->toBe('Tomorrow: 2 birthdays')
            ->and($message->getTo()[0]->getAddress())->toBe('me@example.com')
            ->and($html)->toContain('Auntie Sue')->toContain('turns 43')->toContain('Saturday 17 October')
            ->and($html)->toContain('Tom [work]')->toContain('has a birthday')
            ->and($html.$text)->not->toContain('Gate code')
            ->and($html)->toContain('/reminders/off/'.$user->id)
            ->and($message->getHeaders()->get('List-Unsubscribe')->getBodyAsString())->toContain('/reminders/off/'.$user->id)
            ->and($message->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString())->toBe('List-Unsubscribe=One-Click');

        expect(OutboxMessage::pluck('status')->unique()->all())->toBe(['sent']);
        expect(drain('2026-10-16 20:02')['sent'])->toBe(0);
    });

    it('words a single on-the-day reminder', function () {
        $user = reminderUser();
        Person::factory()->for($user, 'owner')->birthday(17, 10, 1983)->create(['name' => 'Sue']);
        scan('2026-10-17 08:00');
        drain('2026-10-17 08:00');

        expect(sentMail()[0]->getOriginalMessage()->getSubject())->toBe('Today: Sue’s birthday');
    });

    it('drops people deleted since, and skips if nobody is left or reminders were turned off', function () {
        $user = reminderUser();
        $sue = Person::factory()->for($user, 'owner')->birthday(17, 10, null)->create();
        scan('2026-10-16 20:00');
        $sue->delete();

        expect(drain('2026-10-16 20:01'))->toMatchArray(['sent' => 0, 'skipped' => 1])
            ->and(sentMail())->toHaveCount(0);

        Person::factory()->for($user, 'owner')->birthday(18, 10, null)->create();
        scan('2026-10-17 20:00');
        $user->forceFill(['reminders_enabled_at' => null])->save();

        expect(drain('2026-10-17 20:01'))->toMatchArray(['skipped' => 1])
            ->and(OutboxMessage::latest('id')->first()->last_error)->toContain('turned off');
    });

    it('retries with backoff, then gives up', function () {
        $user = reminderUser();
        Person::factory()->for($user, 'owner')->birthday(17, 10, null)->create();
        scan('2026-10-16 20:00');
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

        expect(drain('2026-10-16 20:00')['retrying'])->toBe(1);
        $row = OutboxMessage::sole();
        expect($row->attempts)->toBe(1)
            ->and($row->next_attempt_at->toIso8601String())->toBe(at('2026-10-16 20:05')->toIso8601String())
            ->and($row->last_error)->toContain('SMTP down');

        // Not before the backoff.
        expect(drain('2026-10-16 20:04')['retrying'])->toBe(0);

        drain('2026-10-16 20:05');   // attempt 2 → +15
        drain('2026-10-16 20:20');   // attempt 3 → +30
        drain('2026-10-16 20:50');   // attempt 4 → +60
        expect(drain('2026-10-16 21:50')['failed'])->toBe(1);

        expect(OutboxMessage::sole()->only(['status', 'attempts']))->toBe(['status' => 'failed', 'attempts' => 5]);
    });
});

describe('settings', function () {
    function settingsUser(): User
    {
        $user = User::factory()->create();
        Feature::for($user)->activate([BirthdayEngine::class, Reminders::class]);

        return $user;
    }

    it('needs both flags', function () {
        $user = User::factory()->create();
        Feature::for($user)->activate(BirthdayEngine::class);

        $this->actingAs($user)->get('/settings/reminders')->assertNotFound();
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('features.reminders', false));
    });

    it('starts off, with the default slots', function () {
        $this->actingAs(settingsUser())->get('/settings/reminders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/reminders')
                ->where('enabled', false)
                ->where('slots.day_before', ['enabled' => true, 'time' => '20:00'])
                ->where('slots.on_the_day', ['enabled' => true, 'time' => '08:00']));
    });

    it('switches on and off with custom times, audited', function () {
        $user = settingsUser();

        $this->actingAs($user)->patch('/settings/reminders', [
            'enabled' => true,
            'slots' => ['day_before' => ['enabled' => true, 'time' => '19:30'], 'on_the_day' => ['enabled' => false, 'time' => '08:00']],
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $slots = $user->reminderSlots();
        expect($user->remindersOn())->toBeTrue()
            ->and($slots['day_before']->time())->toBe('19:30')
            ->and($slots['on_the_day']->enabled)->toBeFalse()
            ->and(AuditLog::where('action', 'reminders.enabled')->where('actor_id', $user->id)->exists())->toBeTrue();

        $this->actingAs($user)->patch('/settings/reminders', ['enabled' => false, 'slots' => []]);
        expect($user->refresh()->remindersOn())->toBeFalse()
            ->and(AuditLog::where('action', 'reminders.disabled')->exists())->toBeTrue();
    });

    it('keeps the original switch-on time when saving again', function () {
        $user = settingsUser();
        $payload = ['enabled' => true, 'slots' => []];

        $this->actingAs($user)->patch('/settings/reminders', $payload);
        $first = $user->refresh()->reminders_enabled_at;
        $this->travel(1)->hour();
        $this->actingAs($user)->patch('/settings/reminders', $payload);

        expect($user->refresh()->reminders_enabled_at->equalTo($first))->toBeTrue()
            ->and(AuditLog::where('action', 'reminders.enabled')->count())->toBe(1);
    });

    it('only accepts quarter-hour times', function (string $time) {
        $this->actingAs(settingsUser())->patch('/settings/reminders', [
            'enabled' => true,
            'slots' => ['day_before' => ['enabled' => true, 'time' => $time]],
        ])->assertSessionHasErrors('slots.day_before.time');
    })->with(['20:10', '24:00', '8:00', 'soon']);

    it('sends a test reminder for your next birthdays, audited', function () {
        $user = settingsUser();
        Person::factory()->for($user, 'owner')->birthday(2, 11, 1983)->create(['name' => 'Sue']);

        $this->actingAs($user)->post('/settings/reminders/test')->assertSessionHas('status', 'reminders-test-sent');

        expect(sentMail()[0]->getOriginalMessage()->getSubject())->toBe('[Test] Coming up: Sue’s birthday')
            ->and(AuditLog::where('action', 'reminders.test_sent')->exists())->toBeTrue();
    });

    it('says so when there is nobody to test with', function () {
        $this->actingAs(settingsUser())->post('/settings/reminders/test')->assertSessionHas('status', 'reminders-test-empty');

        expect(sentMail())->toHaveCount(0);
    });
});

describe('unsubscribe link', function () {
    it('shows a page on GET without changing anything', function () {
        $user = reminderUser();
        $url = URL::signedRoute('reminders.unsubscribe', ['user' => $user->id]);

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('reminders/unsubscribe')->where('on', true));

        expect($user->refresh()->remindersOn())->toBeTrue();
    });

    it('turns reminders off on a one-click POST without a session or CSRF token, audited', function () {
        $user = reminderUser();
        $url = URL::signedRoute('reminders.unsubscribe', ['user' => $user->id]);

        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();

        expect($user->refresh()->remindersOn())->toBeFalse()
            ->and(AuditLog::where('action', 'reminders.disabled')->sole()->meta)->toBe(['via' => 'unsubscribe_link']);
    });

    it('can turn them back on', function () {
        $user = reminderUser();
        $user->forceFill(['reminders_enabled_at' => null])->save();

        $this->post(URL::signedRoute('reminders.resubscribe', ['user' => $user->id]))->assertRedirect();

        expect($user->refresh()->remindersOn())->toBeTrue();
    });

    it('rejects unsigned or tampered links', function () {
        $user = reminderUser();
        $other = User::factory()->create();
        $url = URL::signedRoute('reminders.unsubscribe', ['user' => $user->id]);

        $this->get("/reminders/off/{$user->id}")->assertForbidden();
        $this->post(str_replace("/off/{$user->id}", "/off/{$other->id}", $url))->assertForbidden();

        expect($user->refresh()->remindersOn())->toBeTrue();
    });
});

describe('admin outbox', function () {
    beforeEach(fn () => config(['offing.admin_emails' => ['boss@example.com']]));

    it('lists messages with counts and no people’s names, admins only', function () {
        $user = reminderUser();
        Person::factory()->for($user, 'owner')->birthday(17, 10, null)->create(['name' => 'Secret Sue']);
        scan('2026-10-16 20:00');
        $boss = User::factory()->create(['email' => 'boss@example.com']);

        $response = $this->actingAs($boss)->get('/admin/outbox')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('admin/outbox')
            ->where('counts.pending', 1)
            ->where('messages.0.user', 'me@example.com')
            ->where('messages.0.birthday', '2026-10-17')
            ->where('messages.0.slot', 'day_before'));
        expect($response->getContent())->not->toContain('Secret Sue');

        $this->actingAs($user)->get('/admin/outbox')->assertNotFound();
    });
});

describe('heartbeat', function () {
    it('pings HEALTHCHECK_URL every scheduler run, only when set', function () {
        Http::fake();

        config(['offing.healthcheck_url' => null]);
        $this->artisan('schedule:run')->assertSuccessful();
        Http::assertNothingSent();

        config(['offing.healthcheck_url' => 'https://hc-ping.com/test-uuid']);
        $this->artisan('schedule:run')->assertSuccessful();
        Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.com/test-uuid');
    });
});

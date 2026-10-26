<?php

use App\Features\Barometer;
use App\Mail\BarometerCheckInMail;
use App\Mcp\OffingServer;
use App\Mcp\Tools\LogReading;
use App\Models\AiGrant;
use App\Models\AuditLog;
use App\Models\BarometerReading;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\Barometer\CheckInDrainer;
use App\Support\Push\PushResult;
use App\Support\Push\PushSender;
use App\Support\Push\PushStatus;
use App\Support\Reminders\OutboxDrainer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    // Thursday 22 October 2026, 14:00 in London.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 14:00', 'Europe/London'));
});

function checkInUser(array $attributes = []): User
{
    $user = User::factory()->create(['timezone' => 'Europe/London']);
    $user->forceFill($attributes)->save();
    Feature::for($user)->activate(Barometer::class);

    return $user;
}

/** A reading logged just now, with no second rating. */
function freshReading(User $user, array $attributes = []): BarometerReading
{
    return BarometerReading::factory()->for($user)->at('2026-10-22 13:55')->create($attributes + ['rating_after' => null]);
}

/** A push sender that records what it sent and answers as scripted. */
function checkInPush(bool $ready = true, ?PushResult $result = null): object
{
    $fake = new class(new PushStatus(true, $ready, $ready ? null : 'no keys'), $result) implements PushSender
    {
        /** @var list<array<string, string>> */
        public array $sent = [];

        public function __construct(private PushStatus $state, private ?PushResult $result) {}

        public function status(): PushStatus
        {
            return $this->state;
        }

        public function send(Collection $devices, array $payload): PushResult
        {
            $this->sent[] = $payload;

            return $this->result ?? new PushResult(accepted: $devices->count());
        }
    };

    app()->instance(PushSender::class, $fake);

    return $fake;
}

function withDevice(User $user): void
{
    $endpoint = "https://web.push.apple.com/device-{$user->id}";
    $user->devices()->create([
        'name' => 'iPhone',
        'endpoint_hash' => UserDevice::hashEndpoint($endpoint),
        'push_subscription' => ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'x', 'auth' => 'y']],
    ]);
}

function drainCheckIns(string $at): array
{
    return app(CheckInDrainer::class)->drain(CarbonImmutable::parse($at, 'Europe/London')->utc());
}

describe('queueing', function () {
    it('queues one check-in 30 minutes after a reading taken in the app, holding only its id', function () {
        $user = checkInUser();

        $this->actingAs($user)->post('/barometer', ['date' => '2026-10-22', 'time' => '13:50', 'rating_before' => 8, 'thoughts' => 'Everyone is staring']);

        $message = OutboxMessage::sole();
        $reading = $user->barometerReadings()->sole();
        expect($message->kind)->toBe(OutboxMessage::KIND_BAROMETER_CHECK_IN)
            ->and($message->status)->toBe(OutboxMessage::PENDING)
            ->and($message->payload)->toBe(['reading_id' => $reading->id])
            ->and($message->send_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-22 14:30', 'Europe/London')->utc()->toIso8601String());

        // Saving it again (an edit) doesn't queue another.
        $this->actingAs($user)->patch("/barometer/{$reading->id}", ['date' => '2026-10-22', 'time' => '13:50', 'rating_before' => 7]);
        expect(OutboxMessage::count())->toBe(1);
    });

    it('queues one for a reading logged by an AI assistant too', function () {
        $user = checkInUser();
        app()->instance(AiGrant::class, new AiGrant(['level' => 'write-new', 'include_private' => false, 'barometer' => true]));

        OffingServer::actingAs($user)->tool(LogReading::class, ['rating' => 6])->assertOk();

        expect(OutboxMessage::where('kind', OutboxMessage::KIND_BAROMETER_CHECK_IN)->count())->toBe(1);
    });

    it('doesn’t queue one when there’s nothing to check in about', function (Closure $make) {
        $make();

        expect(OutboxMessage::count())->toBe(0);
    })->with([
        'already has a second rating' => [fn () => freshReading(checkInUser(), ['rating_after' => 4])],
        'time not recorded' => [fn () => BarometerReading::factory()->for(checkInUser())->untimed('2026-10-22')->create(['rating_after' => null])],
        'a backfill from earlier' => [fn () => BarometerReading::factory()->for(checkInUser())->at('2026-10-22 12:30')->create(['rating_after' => null])],
        'check-ins switched off' => [fn () => freshReading(checkInUser(['barometer_check_ins' => false]))],
        'Barometer switched off' => [fn () => freshReading(User::factory()->create())],
    ]);
});

describe('sending', function () {
    it('waits until 30 minutes after it was saved, then emails a check-in that says nothing about the reading', function () {
        Mail::fake();
        checkInPush(ready: false);
        $user = checkInUser();
        $reading = freshReading($user, ['thoughts' => 'Everyone is staring']);

        expect(drainCheckIns('2026-10-22 14:29')['sent'])->toBe(0);

        expect(drainCheckIns('2026-10-22 14:31'))->toMatchArray(['sent' => 1]);

        Mail::assertSent(BarometerCheckInMail::class, fn (BarometerCheckInMail $mail) => $mail->hasTo($user->email) && $mail->readingId === $reading->id);
        $message = OutboxMessage::sole();
        expect($message->status)->toBe(OutboxMessage::SENT)->and($message->delivered_via)->toBe('email');
    });

    it('renders an email with a link to the second rating and an off switch, and no details', function () {
        $user = checkInUser();
        $reading = freshReading($user, ['thoughts' => 'Everyone is staring', 'situation' => 'Crowded bus']);
        $mail = new BarometerCheckInMail($user, $reading->id);

        $html = $mail->render();

        expect($mail->envelope()->subject)->toBe('Time for a quick check-in')
            ->and($html)->toContain('Time for a quick check-in')
            ->toContain(e(route('barometer.edit', $reading->id).'#second-rating'))
            ->toContain('Turn off check-ins')
            ->toContain(e($mail->offUrl))
            ->not->toContain('staring')
            ->not->toContain('Crowded')
            ->not->toContain('nxi'); // anxiety / anxious
        expect($mail->headers()->text['List-Unsubscribe'])->toBe("<{$mail->offUrl}>");
    });

    it('pushes instead when the user has a device, with neutral words', function () {
        Mail::fake();
        $push = checkInPush();
        $user = checkInUser();
        withDevice($user);
        $reading = freshReading($user);

        drainCheckIns('2026-10-22 14:31');

        expect($push->sent)->toBe([[
            'title' => 'Offing',
            'body' => 'Time for a quick check-in.',
            'url' => "/barometer/{$reading->id}/edit#second-rating",
            'tag' => "check-in-{$reading->id}",
        ]]);
        Mail::assertNothingSent();
        expect(OutboxMessage::sole()->delivered_via)->toBe('push');
    });

    it('falls back to email straight away if push fails', function () {
        Mail::fake();
        checkInPush(result: new PushResult(failed: 1, errors: ['503 from the push service']));
        $user = checkInUser();
        withDevice($user);
        freshReading($user);

        drainCheckIns('2026-10-22 14:31');

        Mail::assertSent(BarometerCheckInMail::class);
        $message = OutboxMessage::sole();
        expect($message->delivered_via)->toBe('email')->and($message->last_error)->toContain('503 from the push service');
    });

    it('skips it if things changed before it was due', function (Closure $change, string $reason) {
        Mail::fake();
        checkInPush(ready: false);
        $user = checkInUser();
        $reading = freshReading($user);

        $change($user, $reading);
        drainCheckIns('2026-10-22 14:31');

        Mail::assertNothingSent();
        $message = OutboxMessage::sole();
        expect($message->status)->toBe(OutboxMessage::SKIPPED)->and($message->last_error)->toBe($reason);
    })->with([
        'second rating added' => [fn (User $u, BarometerReading $r) => $r->update(['rating_after' => 3]), 'The second rating was already added.'],
        'reading deleted' => [fn (User $u, BarometerReading $r) => $r->delete(), 'The reading was deleted before sending.'],
        'check-ins turned off' => [fn (User $u) => $u->forceFill(['barometer_check_ins' => false])->save(), 'Check-ins were turned off before sending.'],
        'Barometer turned off' => [fn (User $u) => Feature::for($u)->deactivate(Barometer::class), 'Check-ins were turned off before sending.'],
    ]);

    it('gives up if cron was down so long it would arrive hours late', function () {
        Mail::fake();
        freshReading(checkInUser());

        drainCheckIns('2026-10-22 16:45');

        Mail::assertNothingSent();
        expect(OutboxMessage::sole()->status)->toBe(OutboxMessage::SKIPPED);
    });

    it('is drained by reminders:drain, and the birthday drainer leaves it alone', function () {
        Mail::fake();
        checkInPush(ready: false);
        freshReading(checkInUser());
        $this->travelTo(CarbonImmutable::parse('2026-10-22 14:31', 'Europe/London'));

        app(OutboxDrainer::class)->drain(CarbonImmutable::now('UTC'));
        expect(OutboxMessage::sole()->status)->toBe(OutboxMessage::PENDING);

        $this->artisan('reminders:drain')->expectsOutputToContain('Check-ins: sent 1')->assertSuccessful();
        Mail::assertSent(BarometerCheckInMail::class);
    });
});

describe('switching off', function () {
    it('has a switch on the Barometer page, audited', function () {
        $user = checkInUser();

        $this->actingAs($user)->get('/barometer')->assertInertia(fn (Assert $page) => $page->where('checkIns', true));

        $this->actingAs($user)->patch('/barometer/check-ins', ['on' => false])->assertRedirect();

        expect($user->fresh()->barometer_check_ins)->toBeFalse()
            ->and(AuditLog::where('action', 'barometer.check_ins.disabled')->sole()->meta)->toBe(['via' => 'settings']);
        $this->actingAs($user)->get('/barometer')->assertInertia(fn (Assert $page) => $page->where('checkIns', false));
    });

    it('can be turned off from the email without signing in — but only by the button', function () {
        $user = checkInUser();
        $url = URL::signedRoute('barometer.check-ins.off', ['user' => $user->id]);

        // Link scanners and previews fetch GETs: that only shows the page.
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('barometer/check-ins')->where('on', true));
        expect($user->fresh()->barometer_check_ins)->toBeTrue();

        // RFC 8058 one-click: a plain POST with no CSRF token.
        $this->post($url)->assertOk();
        expect($user->fresh()->barometer_check_ins)->toBeFalse()
            ->and(AuditLog::where('action', 'barometer.check_ins.disabled')->sole()->meta)->toBe(['via' => 'unsubscribe_link']);

        $this->post(URL::signedRoute('barometer.check-ins.on', ['user' => $user->id]))->assertRedirect();
        expect($user->fresh()->barometer_check_ins)->toBeTrue();
    });

    it('refuses an unsigned or tampered link', function () {
        $user = checkInUser();
        $url = URL::signedRoute('barometer.check-ins.off', ['user' => $user->id]);

        $this->post("/barometer/check-ins/off/{$user->id}")->assertForbidden();
        $someoneElse = checkInUser();
        $this->post(str_replace("/off/{$user->id}", "/off/{$someoneElse->id}", $url))->assertForbidden();

        expect($user->fresh()->barometer_check_ins)->toBeTrue()
            ->and($someoneElse->fresh()->barometer_check_ins)->toBeTrue();
    });

    it('keeps the in-app switch behind the flag', function () {
        $this->actingAs(User::factory()->create())->patch('/barometer/check-ins', ['on' => false])->assertNotFound();
    });
});

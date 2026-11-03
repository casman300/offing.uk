<?php

use App\Features\Calendar;
use App\Models\AuditLog;
use App\Models\Event as CalendarEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

function calendarUser(string $timezone = 'Europe/London'): User
{
    $user = User::factory()->create(['timezone' => $timezone]);
    Feature::for($user)->activate(Calendar::class);

    return $user;
}

/** A valid form submission, overridable per test. */
function eventForm(array $overrides = []): array
{
    return [
        'title' => 'Dentist',
        'location' => '',
        'description' => '',
        'all_day' => false,
        'tzid' => 'Europe/London',
        'start_date' => '2026-10-20',
        'start_time' => '09:00',
        'end_date' => '2026-10-20',
        'end_time' => '09:30',
        'private' => false,
        'busy' => true,
        ...$overrides,
    ];
}

/** Agenda props, by title. */
function agenda(User $user): array
{
    $events = [];
    test()->actingAs($user)->get('/calendar')
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$events) {
            $page->component('calendar/index');
            $events = $page->toArray()['props']['events'];
        });

    return $events;
}

beforeEach(function () {
    // A Friday in BST; the clocks go back on Sunday 25 October.
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'Europe/London'));
});

describe('access', function () {
    it('is behind the calendar flag', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/calendar')->assertNotFound();
        $this->actingAs($user)->post('/calendar/events', eventForm())->assertNotFound();
        $this->actingAs(calendarUser())->get('/calendar')->assertOk();
    });

    it('shares the flag for the sidebar', function () {
        $this->actingAs(calendarUser())->get('/calendar')
            ->assertInertia(fn (Assert $page) => $page->where('features.calendar', true));
    });

    it('404s on someone else’s event', function () {
        $event = CalendarEvent::factory()->ownedBy(calendarUser())->create();
        $me = calendarUser();

        $this->actingAs($me)->get("/calendar/events/{$event->id}/edit")->assertNotFound();
        $this->actingAs($me)->patch("/calendar/events/{$event->id}", eventForm())->assertNotFound();
        $this->actingAs($me)->delete("/calendar/events/{$event->id}")->assertNotFound();

        $event->delete();
        $this->actingAs($me)->post("/calendar/events/{$event->id}/restore")->assertNotFound();

        expect($event->fresh()->trashed())->toBeTrue()
            ->and($event->fresh()->title)->not->toBe('Dentist');
    });
});

describe('creating', function () {
    it('stores a timed event in UTC with its own timezone', function () {
        $user = calendarUser();

        $this->actingAs($user)->post('/calendar/events', eventForm(['location' => ' The Surgery ']))
            ->assertRedirect('/calendar')
            ->assertSessionHas('status', 'event-created');

        $event = $user->events()->sole();
        expect($event->starts_at->toIso8601String())->toBe('2026-10-20T08:00:00+00:00')
            ->and($event->ends_at->toIso8601String())->toBe('2026-10-20T08:30:00+00:00')
            ->and($event->tzid)->toBe('Europe/London')
            ->and($event->all_day)->toBeFalse()
            ->and($event->location)->toBe('The Surgery')
            ->and($event->description)->toBeNull()
            ->and($event->uid)->toEndWith('@'.parse_url(config('app.url'), PHP_URL_HOST))
            ->and($event->created_by)->toBe($user->id)
            ->and($event->calendar->name)->toBe('Personal')
            ->and($event->calendar->owner->is($user))->toBeTrue();
    });

    it('converts from the event’s timezone, across a DST change', function () {
        $user = calendarUser();

        // 25 October: London is back on GMT, Paris on CET.
        $this->actingAs($user)->post('/calendar/events', eventForm([
            'tzid' => 'Europe/Paris',
            'start_date' => '2026-10-26', 'start_time' => '20:00',
            'end_date' => '2026-10-26', 'end_time' => '22:00',
        ]))->assertSessionHasNoErrors();

        $event = $user->events()->sole();
        expect($event->starts_at->toIso8601String())->toBe('2026-10-26T19:00:00+00:00');

        $row = agenda($user)[0];
        expect($row['start_date'])->toBe('2026-10-26')
            ->and($row['start_time'])->toBe('19:00')
            ->and($row['end_time'])->toBe('21:00')
            ->and($row['origin'])->toBe(['time' => '20:00', 'date' => '2026-10-26', 'tzid' => 'Europe/Paris']);
    });

    it('stores all-day events as dates, with an exclusive end', function () {
        $user = calendarUser('America/Los_Angeles');

        $this->actingAs($user)->post('/calendar/events', eventForm([
            'title' => 'Cornwall',
            'all_day' => true,
            'start_date' => '2026-10-24', 'start_time' => '',
            'end_date' => '2026-10-31', 'end_time' => '',
        ]))->assertSessionHasNoErrors();

        $event = $user->events()->sole();
        expect($event->starts_at->toIso8601String())->toBe('2026-10-24T00:00:00+00:00')
            ->and($event->ends_at->toIso8601String())->toBe('2026-11-01T00:00:00+00:00');

        // Dates never move into the viewer's timezone.
        $row = agenda($user)[0];
        expect($row['start_date'])->toBe('2026-10-24')
            ->and($row['end_date'])->toBe('2026-10-31')
            ->and($row['start_time'])->toBeNull()
            ->and($row['origin'])->toBeNull();
    });

    it('keeps private and free as separate flags', function (bool $private, bool $busy, string $visibility, string $transparency) {
        $user = calendarUser();

        $this->actingAs($user)->post('/calendar/events', eventForm(['private' => $private, 'busy' => $busy]))->assertSessionHasNoErrors();

        $event = $user->events()->sole();
        expect($event->visibility)->toBe($visibility)->and($event->transparency)->toBe($transparency);
    })->with([
        'private + busy' => [true, true, 'private', 'busy'],
        'private + free' => [true, false, 'private', 'free'],
        'normal + free' => [false, false, 'normal', 'free'],
    ]);

    it('rejects bad input with readable messages', function (array $overrides, string $field, ?string $message = null) {
        $response = $this->actingAs(calendarUser())->post('/calendar/events', eventForm($overrides));

        $message === null ? $response->assertSessionHasErrors($field) : $response->assertSessionHasErrors([$field => $message]);
        expect(CalendarEvent::count())->toBe(0);
    })->with([
        'no title' => [['title' => ''], 'title'],
        'end before start' => [['end_time' => '08:00'], 'end_time', 'The end has to be after the start.'],
        'zero length' => [['end_time' => '09:00'], 'end_time', 'The end has to be after the start.'],
        'end date first' => [['end_date' => '2026-10-19'], 'end_date', 'The end date can’t be before the start.'],
        'missing time' => [['start_time' => ''], 'start_time'],
        'bad timezone' => [['tzid' => 'Mars/Olympus'], 'tzid', 'Pick a timezone from the list.'],
        'nonsense date' => [['start_date' => '2026-02-30'], 'start_date'],
    ]);

    it('says so when the event is already over', function () {
        $user = calendarUser();

        $this->actingAs($user)->post('/calendar/events', eventForm(['start_date' => '2026-10-16', 'end_date' => '2026-10-16']))
            ->assertSessionHas('status', 'event-saved-past');
        $this->actingAs($user)->post('/calendar/events', eventForm(['all_day' => true, 'start_date' => '2026-10-16', 'end_date' => '2026-10-16']))
            ->assertSessionHas('status', 'event-created');
    });

    it('allows a one-day all-day event and an overnight timed one', function () {
        $user = calendarUser();

        $this->actingAs($user)->post('/calendar/events', eventForm(['all_day' => true, 'start_time' => '', 'end_time' => '']))->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/calendar/events', eventForm(['start_time' => '22:00', 'end_date' => '2026-10-21', 'end_time' => '02:00']))->assertSessionHasNoErrors();

        expect($user->events()->count())->toBe(2);
    });
});

describe('editing', function () {
    it('shows the form in the event’s own timezone', function () {
        $user = calendarUser();
        $event = CalendarEvent::factory()->ownedBy($user)->at('2026-10-30 20:00', '2026-10-30 21:00', 'America/New_York')->create(['title' => 'Call']);

        $this->actingAs($user)->get("/calendar/events/{$event->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('calendar/edit')
                ->where('event.start_time', '20:00')
                ->where('event.tzid', 'America/New_York')
                ->where('timezone', 'Europe/London')
                ->has('timezones'));
    });

    it('saves changes and bumps the sequence only when something changed', function () {
        $user = calendarUser();
        $event = CalendarEvent::factory()->ownedBy($user)->at('2026-10-20 09:00', '2026-10-20 09:30')->create(['title' => 'Dentist']);

        $this->actingAs($user)->patch("/calendar/events/{$event->id}", eventForm())->assertRedirect('/calendar');
        expect($event->fresh()->sequence)->toBe(0);

        $this->actingAs($user)->patch("/calendar/events/{$event->id}", eventForm(['start_time' => '10:00', 'end_time' => '11:00']))
            ->assertSessionHas('status', 'event-updated');
        expect($event->fresh()->sequence)->toBe(1)
            ->and($event->fresh()->starts_at->toIso8601String())->toBe('2026-10-20T09:00:00+00:00');
    });
});

describe('deleting', function () {
    it('soft-deletes with undo, and audits both', function () {
        $user = calendarUser();
        $event = CalendarEvent::factory()->ownedBy($user)->create(['title' => 'Dentist']);

        $this->actingAs($user)->delete("/calendar/events/{$event->id}")
            ->assertRedirect('/calendar')
            ->assertSessionHas('deleted', ['id' => $event->id, 'name' => 'Dentist']);

        expect($event->fresh()->trashed())->toBeTrue()
            ->and(agenda($user))->toBeEmpty();

        $this->actingAs($user)->get('/calendar')
            ->assertInertia(fn (Assert $page) => $page->has('recentlyDeleted', 1)->where('recentlyDeleted.0.title', 'Dentist'));

        $this->actingAs($user)->post("/calendar/events/{$event->id}/restore")->assertSessionHas('status', 'event-restored');

        expect($event->fresh()->trashed())->toBeFalse()
            ->and(AuditLog::where('subject_id', $event->id)->pluck('action')->all())->toBe(['event.deleted', 'event.restored']);
    });
});

describe('agenda', function () {
    it('lists what’s still to come, in order, in the viewer’s timezone', function () {
        $user = calendarUser();
        $f = fn () => CalendarEvent::factory()->ownedBy($user);

        $f()->at('2026-10-15 09:00', '2026-10-15 10:00')->create(['title' => 'Yesterday']);
        $f()->at('2026-10-16 09:00', '2026-10-16 10:00')->create(['title' => 'This morning']);
        $f()->at('2026-10-16 11:00', '2026-10-16 14:00')->create(['title' => 'Going on now']);
        $f()->at('2026-10-17 08:00', '2026-10-17 09:00')->create(['title' => 'Tomorrow early']);
        $f()->allDay('2026-10-17')->create(['title' => 'Tomorrow all day']);
        $f()->allDay('2026-10-16')->create(['title' => 'Today all day']);
        $f()->allDay('2026-10-10', '2026-10-15')->create(['title' => 'Finished trip']);
        $f()->allDay('2026-10-14', '2026-10-18')->create(['title' => 'Ongoing trip']);
        CalendarEvent::factory()->ownedBy(calendarUser())->create(['title' => 'Someone else’s']);

        expect(array_column(agenda($user), 'title'))->toBe([
            'Ongoing trip',
            'Today all day',
            'Going on now',
            'Tomorrow all day',
            'Tomorrow early',
        ]);
    });

    it('uses the viewer’s date for all-day events', function () {
        // 01:00 on the 17th in Auckland is still the 16th in London.
        $user = calendarUser('Pacific/Auckland');
        CalendarEvent::factory()->ownedBy($user)->allDay('2026-10-16')->create(['title' => 'London Friday']);

        $this->travelTo(CarbonImmutable::parse('2026-10-17 01:00', 'Pacific/Auckland'));

        expect(agenda($user))->toBeEmpty();
    });
});

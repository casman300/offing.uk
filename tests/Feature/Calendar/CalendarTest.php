<?php

use App\Features\BirthdayEngine;
use App\Features\Calendar as CalendarFeature;
use App\Features\Crews;
use App\Models\AuditLog;
use App\Models\Calendar;
use App\Models\Crew;
use App\Models\CrewMember;
use App\Models\Event as CalendarEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    // Thursday 26 November 2026, GMT.
    $this->travelTo(CarbonImmutable::parse('2026-11-26 12:00', 'Europe/London'));
});

function diarist(array $attributes = []): User
{
    $user = User::factory()->create(['timezone' => 'Europe/London'] + $attributes);
    Feature::for($user)->activate([CalendarFeature::class, Crews::class]);

    return $user;
}

/** Home crew: $captain and $mate aboard, with a shared calendar. */
function crewCalendar(): array
{
    $captain = diarist(['name' => 'Charlie']);
    $mate = diarist(['name' => 'Sam']);
    $crew = Crew::create(['name' => 'Home']);
    $crew->members()->create(['user_id' => $captain->id, 'role' => CrewMember::CAPTAIN, 'joined_at' => now()]);
    $crew->members()->create(['user_id' => $mate->id, 'role' => CrewMember::CREW, 'joined_at' => now()]);

    $calendar = Calendar::make(['name' => 'Home', 'colour' => Calendar::COLOURS[1]]);
    $calendar->owner()->associate($crew)->save();

    return [$captain, $mate, $crew, $calendar];
}

function eventPayload(Calendar $calendar, array $overrides = []): array
{
    return array_replace_recursive([
        'calendar_id' => $calendar->id,
        'title' => 'Dentist',
        'all_day' => false,
        'start_date' => '2026-11-30',
        'start_time' => '09:00',
        'end_date' => '2026-11-30',
        'end_time' => '09:30',
        'tzid' => 'Europe/London',
        'location' => '',
        'description' => '',
        'private' => false,
        'free' => false,
        'repeat' => ['freq' => 'none', 'interval' => 1, 'days' => [], 'ends' => 'never', 'count' => null, 'until' => null],
    ], $overrides);
}

/** @return list<array<string, mixed>> */
function occurrencesFor(User $user, string $start, string $end): array
{
    return test()->actingAs($user)->getJson("/calendar/occurrences?start={$start}&end={$end}")->assertOk()->json();
}

describe('access', function () {
    it('is behind the calendar flag', function () {
        $this->actingAs(User::factory()->create())->get('/calendar')->assertNotFound();
        $this->actingAs(diarist())->get('/calendar')->assertOk();
    });

    it('gives everyone a calendar of their own on the way in', function () {
        $me = diarist();

        $this->actingAs($me)->get('/calendar')->assertInertia(fn (Assert $page) => $page
            ->component('calendar/index')
            ->has('calendars', 1)
            ->where('calendars.0.name', 'My calendar')
            ->where('calendars.0.can_edit', true));

        $this->actingAs($me)->get('/calendar');
        expect(Calendar::count())->toBe(1);
    });

    it('keeps a private calendar and its events to its owner', function () {
        $me = diarist();
        $calendar = Calendar::ensurePersonal($me);
        $this->actingAs($me)->post('/calendar/events', eventPayload($calendar))->assertRedirect();
        $event = CalendarEvent::sole();

        $stranger = diarist();
        $this->actingAs($stranger)->get("/calendar/events/{$event->id}")->assertNotFound();
        $this->actingAs($stranger)->put("/calendar/events/{$event->id}", eventPayload($calendar))->assertNotFound();
        expect(occurrencesFor($stranger, '2026-11-30T00:00:00', '2026-12-01T00:00:00'))->toBe([]);

        $this->actingAs($stranger)->post('/calendar/events', eventPayload($calendar))->assertSessionHasErrors('calendar_id');
    });

    it('shares a crew calendar with everyone aboard, and honours the edit switch', function () {
        [$captain, $mate, , $calendar] = crewCalendar();

        $this->actingAs($mate)->post('/calendar/events', eventPayload($calendar, ['title' => 'Bins']))->assertRedirect();
        expect(occurrencesFor($captain, '2026-11-30T00:00:00', '2026-12-01T00:00:00'))->toHaveCount(1);

        $this->actingAs($mate)->patch("/calendar/calendars/{$calendar->id}", ['editors' => 'captains'])->assertForbidden();
        $this->actingAs($captain)->patch("/calendar/calendars/{$calendar->id}", ['editors' => 'captains'])->assertRedirect();
        expect(AuditLog::where('action', 'calendar.editors_changed')->exists())->toBeTrue();

        $event = CalendarEvent::sole();
        $this->actingAs($mate)->put("/calendar/events/{$event->id}", eventPayload($calendar, ['title' => 'Recycling']))->assertForbidden();
        $this->actingAs($mate)->get("/calendar/events/{$event->id}")->assertOk();
    });

    it('stops showing a crew calendar to someone who leaves', function () {
        [, $mate, $crew, $calendar] = crewCalendar();
        $calendar->events()->create(['title' => 'Bins', 'starts_at' => '2026-11-30 09:00', 'ends_at' => '2026-11-30 10:00', 'tzid' => 'Europe/London']);

        $crew->members()->where('user_id', $mate->id)->first()->leave();

        expect(occurrencesFor($mate, '2026-11-30T00:00:00', '2026-12-01T00:00:00'))->toBe([]);
    });
});

describe('creating and editing', function () {
    it('stores times in UTC with the origin zone, and shows them in the viewer’s', function () {
        $me = diarist();
        $calendar = Calendar::ensurePersonal($me);

        // 9am in New York is 2pm in London (both outside DST on 30 Nov).
        $this->actingAs($me)->post('/calendar/events', eventPayload($calendar, ['tzid' => 'America/New_York']))->assertRedirect('/calendar?date=2026-11-30');

        $event = CalendarEvent::sole();
        expect($event->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-30 14:00')
            ->and($event->tzid)->toBe('America/New_York')
            ->and($event->uid)->toEndWith('@'.parse_url(config('app.url'), PHP_URL_HOST));

        expect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-01T00:00:00')[0])
            ->toMatchArray(['title' => 'Dentist', 'start' => '2026-11-30T14:00:00', 'end' => '2026-11-30T14:30:00', 'all_day' => false]);
    });

    it('stores all-day events as dates with an exclusive end', function () {
        $me = diarist();
        $calendar = Calendar::ensurePersonal($me);

        $this->actingAs($me)->post('/calendar/events', eventPayload($calendar, [
            'title' => 'Cornwall', 'all_day' => true, 'start_date' => '2026-12-01', 'end_date' => '2026-12-03', 'start_time' => null, 'end_time' => null,
        ]))->assertRedirect();

        expect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-07T00:00:00')[0])
            ->toMatchArray(['start' => '2026-12-01', 'end' => '2026-12-04', 'all_day' => true]);
    });

    it('refuses an event that ends before it starts', function () {
        $me = diarist();
        $this->actingAs($me)->post('/calendar/events', eventPayload(Calendar::ensurePersonal($me), ['end_time' => '08:00']))
            ->assertSessionHasErrors('end_date');
    });

    it('bumps the sequence on every edit', function () {
        $me = diarist();
        $calendar = Calendar::ensurePersonal($me);
        $this->actingAs($me)->post('/calendar/events', eventPayload($calendar));
        $event = CalendarEvent::sole();

        $this->actingAs($me)->put("/calendar/events/{$event->id}", eventPayload($calendar, ['title' => 'Dentist (moved)', 'start_time' => '10:00', 'end_time' => '10:30']))
            ->assertRedirect("/calendar/events/{$event->id}");

        expect($event->fresh()->title)->toBe('Dentist (moved)')->and($event->fresh()->sequence)->toBe(1);
    });
});

describe('repeating', function () {
    it('keeps a weekly event at the same local time across the clocks changing', function () {
        $me = diarist();
        $calendar = Calendar::ensurePersonal($me);

        // Mondays 09:00 London from 16 March 2026: BST starts 29 March.
        $calendar->events()->create([
            'title' => 'Standup', 'starts_at' => CarbonImmutable::parse('2026-03-16 09:00', 'Europe/London')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-03-16 09:15', 'Europe/London')->utc(), 'tzid' => 'Europe/London', 'rrule' => 'FREQ=WEEKLY',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00', 'Europe/London'));

        expect(collect(occurrencesFor($me, '2026-03-16T00:00:00', '2026-04-13T00:00:00'))->pluck('start')->all())
            ->toBe(['2026-03-16T09:00:00', '2026-03-23T09:00:00', '2026-03-30T09:00:00', '2026-04-06T09:00:00']);
    });

    it('builds the RRULE from the form, weekdays and all', function () {
        $me = diarist();
        $calendar = Calendar::ensurePersonal($me);

        // 30 Nov 2026 is a Monday.
        $this->actingAs($me)->post('/calendar/events', eventPayload($calendar, [
            'title' => 'Swim', 'repeat' => ['freq' => 'weekly', 'interval' => 1, 'days' => ['TH', 'MO'], 'ends' => 'count', 'count' => 4],
        ]))->assertRedirect();

        expect(CalendarEvent::sole()->rrule)->toBe('FREQ=WEEKLY;BYDAY=MO,TH;COUNT=4');
        expect(collect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-31T00:00:00'))->pluck('start')->all())
            ->toBe(['2026-11-30T09:00:00', '2026-12-03T09:00:00', '2026-12-07T09:00:00', '2026-12-10T09:00:00']);
    });

    it('ends on the chosen day, inclusive', function () {
        $me = diarist();
        $this->actingAs($me)->post('/calendar/events', eventPayload(Calendar::ensurePersonal($me), [
            'repeat' => ['freq' => 'daily', 'ends' => 'until', 'until' => '2026-12-02'],
        ]));

        expect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-07T00:00:00'))->toHaveCount(3);

        $this->actingAs($me)->get('/calendar/events/'.CalendarEvent::sole()->id.'/edit')->assertInertia(fn (Assert $page) => $page
            ->where('values.repeat.freq', 'daily')
            ->where('values.repeat.ends', 'until')
            ->where('values.repeat.until', '2026-12-02'));
    });

    it('drops just one date from a series, and a new pattern forgets skipped dates', function () {
        $me = diarist();
        $calendar = Calendar::ensurePersonal($me);
        $this->actingAs($me)->post('/calendar/events', eventPayload($calendar, ['repeat' => ['freq' => 'daily']]));
        $event = CalendarEvent::sole();

        $this->actingAs($me)->get("/calendar/events/{$event->id}?at=2026-12-01T09:00:00")
            ->assertInertia(fn (Assert $page) => $page->where('occurrence.start', '2026-12-01T09:00:00'));
        $this->actingAs($me)->post("/calendar/events/{$event->id}/skip", ['occurrence' => '2026-12-01T09:00:00'])->assertRedirect();

        expect(collect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-03T00:00:00'))->pluck('start')->all())
            ->toBe(['2026-11-30T09:00:00', '2026-12-02T09:00:00']);

        $this->actingAs($me)->put("/calendar/events/{$event->id}", eventPayload($calendar, ['start_time' => '10:00', 'end_time' => '10:30', 'repeat' => ['freq' => 'daily']]));
        expect($event->fresh()->exdates)->toBeNull();
    });

    it('keeps a pattern the form can’t show', function () {
        $me = diarist();
        $calendar = Calendar::ensurePersonal($me);
        $event = $calendar->events()->create([
            'title' => 'Book club', 'starts_at' => '2026-11-26 19:00', 'ends_at' => '2026-11-26 21:00', 'tzid' => 'Europe/London',
            'rrule' => 'FREQ=MONTHLY;BYDAY=-1TH', 'created_by_user_id' => $me->id,
        ]);

        $this->actingAs($me)->get("/calendar/events/{$event->id}/edit")->assertInertia(fn (Assert $page) => $page->where('values.repeat.custom', 'FREQ=MONTHLY;BYDAY=-1TH'));
        $this->actingAs($me)->put("/calendar/events/{$event->id}", eventPayload($calendar, [
            'title' => 'Book club', 'start_date' => '2026-11-26', 'end_date' => '2026-11-26', 'start_time' => '19:00', 'end_time' => '21:00', 'repeat' => ['freq' => 'custom'],
        ]))->assertRedirect();

        expect($event->fresh()->rrule)->toBe('FREQ=MONTHLY;BYDAY=-1TH');
        expect(collect(occurrencesFor($me, '2026-12-01T00:00:00', '2027-01-01T00:00:00'))->pluck('start')->all())->toBe(['2026-12-31T19:00:00']);
    });
});

describe('private and free', function () {
    it('shows someone else’s private event as a detail-free Busy block, and private + free not at all', function () {
        [$captain, $mate, , $calendar] = crewCalendar();

        $this->actingAs($captain)->post('/calendar/events', eventPayload($calendar, ['title' => 'Pick up flowers', 'location' => 'Florist', 'private' => true]));
        $this->actingAs($captain)->post('/calendar/events', eventPayload($calendar, ['title' => 'Gift shopping', 'private' => true, 'free' => true, 'start_time' => '20:00', 'end_time' => '21:00']));

        $mine = occurrencesFor($captain, '2026-11-30T00:00:00', '2026-12-01T00:00:00');
        expect(collect($mine)->pluck('title')->all())->toBe(['Pick up flowers', 'Gift shopping']);

        $theirs = occurrencesFor($mate, '2026-11-30T00:00:00', '2026-12-01T00:00:00');
        expect($theirs)->toHaveCount(1)
            ->and($theirs[0])->toMatchArray(['title' => 'Busy', 'location' => null, 'event_id' => null, 'busy_block' => true]);

        $flowers = CalendarEvent::where('title', 'Pick up flowers')->sole();
        $this->actingAs($mate)->get("/calendar/events/{$flowers->id}")->assertNotFound();
        $this->actingAs($mate)->delete("/calendar/events/{$flowers->id}")->assertNotFound();
    });
});

describe('deleting', function () {
    it('soft-deletes with an undo', function () {
        $me = diarist();
        $this->actingAs($me)->post('/calendar/events', eventPayload(Calendar::ensurePersonal($me)));
        $event = CalendarEvent::sole();

        $this->actingAs($me)->delete("/calendar/events/{$event->id}")
            ->assertRedirect('/calendar?date=2026-11-30')
            ->assertSessionHas('eventDeleted', ['id' => $event->id, 'title' => 'Dentist']);
        expect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-01T00:00:00'))->toBe([]);

        $this->actingAs($me)->post("/calendar/events/{$event->id}/restore")->assertRedirect();
        expect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-01T00:00:00'))->toHaveCount(1);
    });

    it('won’t delete your last calendar of your own', function () {
        $me = diarist();
        $calendar = Calendar::ensurePersonal($me);

        $this->actingAs($me)->delete("/calendar/calendars/{$calendar->id}")->assertStatus(422);

        $this->actingAs($me)->post('/calendar/calendars', ['name' => 'Work', 'colour' => Calendar::COLOURS[2], 'owner' => 'me'])->assertRedirect();
        $this->actingAs($me)->delete("/calendar/calendars/{$calendar->id}")->assertRedirect();
        expect(Calendar::where('owner_id', $me->id)->pluck('name')->all())->toBe(['Work']);
    });

    it('lets only captains add-and-audit or delete a crew calendar', function () {
        [$captain, $mate, $crew, $calendar] = crewCalendar();

        $this->actingAs($mate)->post('/calendar/calendars', ['name' => 'Club', 'colour' => Calendar::COLOURS[3], 'owner' => (string) $crew->id])->assertRedirect();
        expect(AuditLog::where('action', 'calendar.created')->exists())->toBeTrue();

        $this->actingAs($mate)->delete("/calendar/calendars/{$calendar->id}")->assertForbidden();
        $this->actingAs($captain)->delete("/calendar/calendars/{$calendar->id}")->assertRedirect();
        expect(AuditLog::where('action', 'calendar.deleted')->exists())->toBeTrue();

        $this->actingAs(diarist())->post('/calendar/calendars', ['name' => 'Sneaky', 'colour' => Calendar::COLOURS[3], 'owner' => (string) $crew->id])->assertNotFound();
    });

    it('takes a user’s calendars and events with their account', function () {
        $me = diarist();
        $this->actingAs($me)->post('/calendar/events', eventPayload(Calendar::ensurePersonal($me)));

        $me->delete();

        expect(Calendar::withTrashed()->count())->toBe(0)->and(CalendarEvent::withTrashed()->count())->toBe(0);
    });
});

describe('the occurrences endpoint', function () {
    it('refuses silly ranges', function () {
        $me = diarist();
        $this->actingAs($me)->getJson('/calendar/occurrences?start=2026-01-01&end=2027-01-01')->assertStatus(422);
        $this->actingAs($me)->getJson('/calendar/occurrences?start=nope&end=2027-01-01')->assertStatus(422);
    });
});

describe('editing one date of a series', function () {
    it('edits just that date, and deleting it deletes the date', function () {
        $me = diarist();
        $calendar = Calendar::ensurePersonal($me);
        $this->actingAs($me)->post('/calendar/events', eventPayload($calendar, ['title' => 'Standup', 'repeat' => ['freq' => 'daily']]));
        $series = CalendarEvent::sole();

        $this->actingAs($me)->get("/calendar/events/{$series->id}/one/edit?at=2026-12-01T09:00:00")
            ->assertInertia(fn (Assert $page) => $page->where('values.start_date', '2026-12-01')->where('event.single', true));
        $this->actingAs($me)->get("/calendar/events/{$series->id}/one/edit?at=2026-12-01T10:00:00")->assertNotFound();

        $this->actingAs($me)->post("/calendar/events/{$series->id}/one?at=2026-12-01T09:00:00", eventPayload($calendar, [
            'title' => 'Standup (late)', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'start_time' => '11:00', 'end_time' => '11:15',
            'repeat' => ['freq' => 'weekly'],
        ]))->assertRedirect();

        $override = CalendarEvent::where('series_id', $series->id)->sole();
        expect($override->rrule)->toBeNull()->and($override->recurrence_id)->toBe('2026-12-01T09:00:00');

        $titles = fn () => collect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-03T00:00:00'))->map(fn ($o) => substr($o['start'], 5).' '.$o['title'])->all();
        expect($titles())->toBe(['11-30T09:00:00 Standup', '12-01T11:00:00 Standup (late)', '12-02T09:00:00 Standup']);

        $this->actingAs($me)->delete("/calendar/events/{$override->id}");
        expect($titles())->toBe(['11-30T09:00:00 Standup', '12-02T09:00:00 Standup']);

        $this->actingAs($me)->post("/calendar/events/{$override->id}/restore");
        expect($titles())->toBe(['11-30T09:00:00 Standup', '12-01T11:00:00 Standup (late)', '12-02T09:00:00 Standup']);
    });

    it('drops edited dates when the pattern changes, and hides them with a deleted series', function () {
        $me = diarist();
        $calendar = Calendar::ensurePersonal($me);
        $this->actingAs($me)->post('/calendar/events', eventPayload($calendar, ['repeat' => ['freq' => 'daily']]));
        $series = CalendarEvent::sole();
        $this->actingAs($me)->post("/calendar/events/{$series->id}/one?at=2026-12-01T09:00:00", eventPayload($calendar, ['start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'title' => 'Moved']));

        $this->actingAs($me)->delete("/calendar/events/{$series->id}");
        expect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-03T00:00:00'))->toBe([]);
        $this->actingAs($me)->post("/calendar/events/{$series->id}/restore");
        expect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-03T00:00:00'))->toHaveCount(3);

        $this->actingAs($me)->put("/calendar/events/{$series->id}", eventPayload($calendar, ['start_time' => '08:00', 'end_time' => '08:30', 'repeat' => ['freq' => 'daily']]));
        expect(CalendarEvent::where('series_id', $series->id)->count())->toBe(0);
    });
});

describe('dragging', function () {
    it('moves a one-off', function () {
        $me = diarist();
        $this->actingAs($me)->post('/calendar/events', eventPayload(Calendar::ensurePersonal($me)));
        $event = CalendarEvent::sole();

        $this->actingAs($me)->post("/calendar/events/{$event->id}/move", ['start' => '2026-12-01T14:00:00', 'end' => '2026-12-01T15:00:00', 'all_day' => false])->assertRedirect();

        expect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-03T00:00:00')[0])->toMatchArray(['start' => '2026-12-01T14:00:00', 'end' => '2026-12-01T15:00:00']);
    });

    it('moves one date of a series on its own, or every date’s time on the same day', function () {
        $me = diarist();
        $this->actingAs($me)->post('/calendar/events', eventPayload(Calendar::ensurePersonal($me), ['title' => 'Standup', 'repeat' => ['freq' => 'daily']]));
        $series = CalendarEvent::sole();
        $this->actingAs($me)->post("/calendar/events/{$series->id}/skip", ['occurrence' => '2026-12-03T09:00:00']);

        $this->actingAs($me)->post("/calendar/events/{$series->id}/move", [
            'start' => '2026-12-02T16:00:00', 'end' => '2026-12-02T16:30:00', 'all_day' => false, 'occurrence' => '2026-12-01T09:00:00', 'scope' => 'one',
        ])->assertRedirect();

        // Every one: a new time on the same day — but not a new day.
        $this->actingAs($me)->post("/calendar/events/{$series->id}/move", [
            'start' => '2026-12-02T10:00:00', 'end' => '2026-12-02T11:00:00', 'all_day' => false, 'occurrence' => '2026-11-30T09:00:00', 'scope' => 'all',
        ])->assertSessionHasErrors('start');
        $this->actingAs($me)->post("/calendar/events/{$series->id}/move", [
            'start' => '2026-11-30T10:00:00', 'end' => '2026-11-30T11:00:00', 'all_day' => false, 'occurrence' => '2026-11-30T09:00:00', 'scope' => 'all',
        ])->assertRedirect();

        expect(collect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-05T00:00:00'))->map(fn ($o) => $o['start'].'–'.substr($o['end'], 11, 5))->all())
            ->toBe(['2026-11-30T10:00:00–11:00', '2026-12-02T10:00:00–11:00', '2026-12-02T16:00:00–16:30', '2026-12-04T10:00:00–11:00']);
    });

    it('won’t move someone else’s event on a captains-only calendar', function () {
        [$captain, $mate, , $calendar] = crewCalendar();
        $this->actingAs($captain)->post('/calendar/events', eventPayload($calendar));
        $calendar->update(['editors' => 'captains']);

        $this->actingAs($mate)->post('/calendar/events/'.CalendarEvent::sole()->id.'/move', ['start' => '2026-12-01T14:00:00', 'end' => '2026-12-01T15:00:00', 'all_day' => false])->assertForbidden();
        expect(occurrencesFor($mate, '2026-11-30T00:00:00', '2026-12-03T00:00:00')[0]['can_edit'])->toBeFalse();
    });
});

describe('birthdays', function () {
    it('shows your people’s birthdays when the birthday engine is on', function () {
        $me = diarist();
        $me->people()->create(['name' => 'Sam', 'birth_day' => 1, 'birth_month' => 12, 'birth_year' => 1990]);
        $me->people()->create(['name' => 'Leap', 'birth_day' => 29, 'birth_month' => 2]);
        diarist()->people()->create(['name' => 'Stranger', 'birth_day' => 2, 'birth_month' => 12]);

        expect(occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-07T00:00:00'))->toBe([]);

        Feature::for($me)->activate(BirthdayEngine::class);
        $items = occurrencesFor($me, '2026-11-30T00:00:00', '2026-12-07T00:00:00');
        expect($items)->toHaveCount(1)
            ->and($items[0])->toMatchArray(['title' => "Sam's birthday 🎂 (36)", 'start' => '2026-12-01', 'end' => '2026-12-02', 'all_day' => true, 'calendar_id' => -1, 'can_edit' => false]);

        expect(collect(occurrencesFor($me, '2027-02-01T00:00:00', '2027-03-01T00:00:00'))->pluck('start')->all())->toBe(['2027-02-28']);
    });
});

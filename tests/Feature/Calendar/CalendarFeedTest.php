<?php

use App\Features\Calendar as CalendarFeature;
use App\Features\Crews;
use App\Feeds\UserFeed;
use App\Models\Calendar;
use App\Models\Crew;
use App\Models\CrewMember;
use App\Models\Event as CalendarEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 12:00', 'Europe/London'));
});

function feedUser(): User
{
    $user = User::factory()->create(['timezone' => 'Europe/London']);
    Feature::for($user)->activate([CalendarFeature::class, Crews::class]);

    return $user;
}

function feedFor(User $user): VCalendar
{
    $calendar = Reader::read((new UserFeed($user))->render());
    expect($calendar)->toBeInstanceOf(VCalendar::class)
        ->and($calendar->validate())->toBe([]);

    return $calendar;
}

function seriesOn(Calendar $calendar, User $by, array $attributes = []): CalendarEvent
{
    return $calendar->events()->create(array_merge([
        'title' => 'Standup',
        'starts_at' => CarbonImmutable::parse('2026-11-30 09:00', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-30 09:15', 'Europe/London')->utc(),
        'tzid' => 'Europe/London',
        'rrule' => 'FREQ=DAILY;COUNT=5',
        'created_by_user_id' => $by->id,
    ], $attributes))->fresh();
}

it('carries events as series in their own zone, with a VTIMEZONE', function () {
    $me = feedUser();
    seriesOn(Calendar::ensurePersonal($me), $me, ['location' => 'Kitchen', 'exdates' => ['2026-12-01T09:00:00']]);

    $feed = feedFor($me);
    $event = $feed->VEVENT[0];

    expect((string) $event->SUMMARY)->toBe('Standup')
        ->and((string) $event->DTSTART['TZID'])->toBe('Europe/London')
        ->and((string) $event->RRULE)->toBe('FREQ=DAILY;COUNT=5')
        ->and((string) $event->EXDATE)->toBe('20261201T090000')
        ->and((string) $event->LOCATION)->toBe('Kitchen')
        ->and((string) $event->TRANSP)->toBe('OPAQUE')
        ->and((string) $feed->VTIMEZONE->TZID)->toBe('Europe/London');

    // Every London transition from 2026 onwards: BST starts on 29 March 2026 at 01:00 GMT.
    $daylight = $feed->VTIMEZONE->select('DAYLIGHT')[0];
    expect((string) $daylight->DTSTART)->toBe('20260329T010000')
        ->and((string) $daylight->TZOFFSETTO)->toBe('+0100');

    $expanded = $feed->expand(new DateTime('2026-11-30'), new DateTime('2026-12-10'));
    expect(collect($expanded->VEVENT)->map(fn ($e) => $e->DTSTART->getDateTime()->format('Y-m-d H:i'))->all())
        ->toBe(['2026-11-30 09:00', '2026-12-02 09:00', '2026-12-03 09:00', '2026-12-04 09:00']);
});

it('sends a date edited on its own as a RECURRENCE-ID override', function () {
    $me = feedUser();
    $calendar = Calendar::ensurePersonal($me);
    $series = seriesOn($calendar, $me);
    $override = $calendar->events()->create([
        'title' => 'Standup (late)',
        'starts_at' => CarbonImmutable::parse('2026-12-02 11:00', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-12-02 11:15', 'Europe/London')->utc(),
        'tzid' => 'Europe/London',
    ]);
    $override->series()->associate($series);
    $override->recurrence_id = '2026-12-02T09:00:00';
    $override->save();

    $feed = feedFor($me);
    expect($feed->VEVENT)->toHaveCount(2)
        ->and((string) $feed->VEVENT[1]->UID)->toBe($series->uid)
        ->and((string) $feed->VEVENT[1]->{'RECURRENCE-ID'})->toBe('20261202T090000')
        ->and(isset($feed->VEVENT[0]->EXDATE))->toBeFalse();

    $expanded = $feed->expand(new DateTime('2026-11-30'), new DateTime('2026-12-10'));
    expect(collect($expanded->VEVENT)->map(fn ($e) => $e->DTSTART->getDateTime()->format('m-d H:i').' '.$e->SUMMARY)->all())
        ->toContain('12-02 11:00 Standup (late)')
        ->not->toContain('12-02 09:00 Standup');
});

it('shows crewmates’ private events as Busy, and private + free not at all', function () {
    $me = feedUser();
    $mate = feedUser();
    $crew = Crew::create(['name' => 'Home']);
    $crew->members()->create(['user_id' => $me->id, 'role' => CrewMember::CAPTAIN, 'joined_at' => now()]);
    $crew->members()->create(['user_id' => $mate->id, 'role' => CrewMember::CREW, 'joined_at' => now()]);
    $shared = Calendar::make(['name' => 'Home', 'colour' => Calendar::COLOURS[1]]);
    $shared->owner()->associate($crew)->save();

    seriesOn($shared, $mate, ['title' => 'Flowers', 'location' => 'Florist', 'visibility' => 'private', 'rrule' => null]);
    seriesOn($shared, $mate, ['title' => 'Gift shopping', 'visibility' => 'private', 'transparency' => 'free', 'rrule' => null]);

    $events = feedFor($me)->VEVENT;
    expect($events)->toHaveCount(1)
        ->and((string) $events[0]->SUMMARY)->toBe('Busy')
        ->and(isset($events[0]->LOCATION))->toBeFalse()
        ->and((string) $events[0]->CLASS)->toBe('PRIVATE');
});

it('sends all-day events as dates', function () {
    $me = feedUser();
    Calendar::ensurePersonal($me)->events()->create([
        'title' => 'Cornwall', 'starts_at' => '2026-12-04 00:00', 'ends_at' => '2026-12-07 00:00', 'tzid' => 'Europe/London', 'all_day' => true,
    ]);

    $event = feedFor($me)->VEVENT[0];
    expect((string) $event->DTSTART)->toBe('20261204')
        ->and((string) $event->DTSTART['VALUE'])->toBe('DATE')
        ->and((string) $event->DTEND)->toBe('20261207');
});

it('leaves events out while the calendar is off for the subscriber, and is byte-stable', function () {
    $me = feedUser();
    seriesOn(Calendar::ensurePersonal($me), $me);

    $first = (new UserFeed($me))->render();
    $this->travel(3)->hours();
    expect((new UserFeed($me))->render())->toBe($first);

    Feature::for($me)->deactivate(CalendarFeature::class);
    expect((new UserFeed($me))->render())->not->toContain('Standup');
});

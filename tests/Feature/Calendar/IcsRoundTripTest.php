<?php

use App\Features\Calendar as CalendarFeature;
use App\Features\Crews;
use App\Feeds\UserFeed;
use App\Models\Calendar;
use App\Models\Crew;
use App\Models\CrewMember;
use App\Models\FeedSubscription;
use App\Models\User;
use App\Support\Calendar\Ics\FeedSync;
use App\Support\Calendar\Ics\FileImport;
use App\Support\Calendar\Ics\IcsParser;
use App\Support\Feeds\HostResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Tests\Support\FakeHostResolver;

/**
 * Spec §5: what comes in through an import or subscription goes back out
 * through the user's feed with nothing we didn't understand lost.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 12:00', 'Europe/London'));
});

function roundTripper(): User
{
    $user = User::factory()->create(['timezone' => 'Europe/London']);
    Feature::for($user)->activate([CalendarFeature::class, Crews::class]);

    return $user;
}

function importFixture(User $user, string $fixture, ?Calendar $calendar = null): Calendar
{
    $calendar ??= Calendar::ensurePersonal($user);
    $parsed = IcsParser::parse((string) file_get_contents(base_path("tests/Fixtures/ics/{$fixture}")), 'Europe/London');
    FileImport::import($parsed, $calendar, $user);

    return $calendar;
}

/** @return array<string, VEvent> keyed by SUMMARY (+ RECURRENCE-ID) */
function feedEventsOf(User $user): array
{
    $calendar = Reader::read((new UserFeed($user))->render());
    expect($calendar)->toBeInstanceOf(VCalendar::class)
        ->and($calendar->validate())->toBe([]);

    $events = [];

    foreach ($calendar->select('VEVENT') as $event) {
        $events[(string) $event->SUMMARY.(isset($event->{'RECURRENCE-ID'}) ? ' @'.$event->{'RECURRENCE-ID'} : '')] = $event;
    }

    return $events;
}

it('re-emits what Google sent that Offing doesn’t model', function () {
    $me = roundTripper();
    importFixture($me, 'google.ics');

    $events = feedEventsOf($me);
    $series = $events['Standup'];

    expect((string) $series->UID)->toBe('standup-2026@google.com')
        ->and((string) $series->RRULE)->toBe('FREQ=WEEKLY;BYDAY=MO')
        ->and((string) $series->DTSTART['TZID'])->toBe('Europe/London')
        ->and(array_map('strval', iterator_to_array($series->EXDATE)))->toBe(['20261116T093000', '20261130T093000'])
        ->and((string) $series->{'X-GOOGLE-CONFERENCE'})->toBe('https://meet.google.com/abc-defg-hij')
        ->and((string) $series->ATTENDEE)->toBe('mailto:sam@example.com')
        ->and((string) $series->ATTENDEE['CN'])->toBe('Sam')
        ->and((string) $series->CREATED)->toBe('20261001T090000Z')
        ->and((string) $series->STATUS)->toBe('CONFIRMED')
        ->and(count($series->select('VALARM')))->toBe(1)
        // Passed through exactly as Google wrote it.
        ->and((string) $series->VALARM->TRIGGER)->toBe('-P0DT0H10M0S')
        // Ours, not doubled up from the original.
        ->and(count($series->select('SUMMARY')))->toBe(1)
        ->and(count($series->select('DTSTART')))->toBe(1)
        ->and(count($series->select('UID')))->toBe(1);

    $moved = $events['Standup (moved to Tuesday) @20261123T093000'];
    expect((string) $moved->UID)->toBe('standup-2026@google.com')
        ->and((string) $moved->{'RECURRENCE-ID'}['TZID'])->toBe('Europe/London');

    $conference = $events['Winter conference'];
    expect((string) $conference->DTSTART)->toBe('20261218')
        ->and((string) $conference->DTEND)->toBe('20261222')
        ->and((string) $conference->ATTACH)->toBe('https://example.com/agenda.pdf')
        ->and((string) $conference->ATTACH['FILENAME'])->toBe('agenda.pdf')
        ->and((string) $conference->TRANSP)->toBe('TRANSPARENT');
});

it('re-emits Outlook’s own properties and keeps its Windows-zoned times', function () {
    $me = roundTripper();
    importFixture($me, 'outlook.ics');

    $events = feedEventsOf($me);

    expect((string) $events['Board meeting']->DTSTART['TZID'])->toBe('Europe/London')
        ->and((string) $events['Board meeting']->DTSTART)->toBe('20261203T090000')
        ->and((string) $events['Board meeting']->{'X-MICROSOFT-CDO-BUSYSTATUS'})->toBe('BUSY')
        ->and((string) $events['Board meeting']->PRIORITY)->toBe('5')
        ->and((string) $events['Berlin call']->DTSTART['TZID'])->toBe('Europe/Berlin')
        ->and((string) $events['Berlin call']->DTSTART)->toBe('20261201T140000')
        ->and((string) $events['Berlin call']->CLASS)->toBe('PRIVATE')
        ->and(count($events['Berlin call']->select('CLASS')))->toBe(1);
});

it('re-emits Apple’s structured location, and a floating time in the importer’s zone', function () {
    $me = roundTripper();
    importFixture($me, 'apple.ics');

    $events = feedEventsOf($me);

    expect((string) $events['Dinner in New York']->{'X-APPLE-STRUCTURED-LOCATION'})->toBe('geo:40.7,-74.0')
        ->and((string) $events['Dinner in New York']->DTSTART['TZID'])->toBe('America/New_York')
        ->and((string) $events['Morning swim']->DTSTART)->toBe('20261210T073000')
        ->and((string) $events['Morning swim']->DTSTART['TZID'])->toBe('Europe/London')
        ->and((string) $events["Christmas at Gran's"]->DTEND)->toBe('20261227');
});

it('carries subscribed events in the subscriber’s feed too', function () {
    $this->app->instance(HostResolver::class, new FakeHostResolver(['calendar.google.com' => ['142.250.180.14']]));
    Http::fake(['*' => Http::response(file_get_contents(base_path('tests/Fixtures/ics/google.ics')))]);
    $me = roundTripper();
    $subscription = FeedSubscription::subscribe($me, 'https://calendar.google.com/x.ics', 'calendar.google.com', 'Work', Calendar::COLOURS[0]);
    app(FeedSync::class)->run($subscription);

    $events = feedEventsOf($me);

    expect((string) $events['Standup']->{'X-GOOGLE-CONFERENCE'})->toBe('https://meet.google.com/abc-defg-hij')
        ->and($events)->toHaveKey('Standup (moved to Tuesday) @20261123T093000');
});

it('copies nothing from the original into a crewmate’s Busy block', function () {
    $me = roundTripper();
    $mate = roundTripper();
    $crew = Crew::create(['name' => 'Home']);
    $crew->members()->create(['user_id' => $me->id, 'role' => CrewMember::CAPTAIN, 'joined_at' => now()]);
    $crew->members()->create(['user_id' => $mate->id, 'role' => CrewMember::CREW, 'joined_at' => now()]);
    $shared = new Calendar(['name' => 'Home', 'colour' => Calendar::COLOURS[1]]);
    $shared->owner()->associate($crew)->save();

    importFixture($me, 'google.ics', $shared);
    $shared->events()->where('title', 'Standup')->update(['visibility' => 'private']);

    $events = feedEventsOf($mate);

    expect($events)->toHaveKey('Busy')
        ->and(isset($events['Busy']->{'X-GOOGLE-CONFERENCE'}))->toBeFalse()
        ->and(isset($events['Busy']->ATTENDEE))->toBeFalse()
        ->and(isset($events['Busy']->VALARM))->toBeFalse()
        ->and(isset($events['Busy']->DESCRIPTION))->toBeFalse();
});

it('stays byte-stable between renders', function () {
    $me = roundTripper();
    importFixture($me, 'google.ics');

    expect((new UserFeed($me))->render())->toBe((new UserFeed($me))->render());
});

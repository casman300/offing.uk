<?php

use App\Support\Calendar\Ics\IcsParser;
use App\Support\Calendar\Ics\ParsedEvent;
use App\Support\Feeds\FeedException;

function icsFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/ics/{$name}"));
}

/** @return array<string, ParsedEvent> keyed by title */
function parsedByTitle(string $fixture, string $tz = 'Europe/London'): array
{
    $result = IcsParser::parse(icsFixture($fixture), $tz);

    return collect($result->events)->keyBy(fn (ParsedEvent $e) => $e->title.($e->cancelled ? ' [cancelled]' : ''))->all();
}

it('reads a Google calendar: series, EXDATE, a moved date, a cancelled date, all-day and private', function () {
    $result = IcsParser::parse(icsFixture('google.ics'), 'Europe/London');
    $events = parsedByTitle('google.ics');

    expect($result->name)->toBe('Work')
        ->and($result->errors)->toBe([]);

    $series = $events['Standup'];
    expect($series->uid)->toBe('standup-2026@google.com')
        ->and($series->tzid)->toBe('Europe/London')
        ->and($series->startsAt->toIso8601String())->toBe('2026-11-02T09:30:00+00:00')
        ->and($series->rrule)->toBe('FREQ=WEEKLY;BYDAY=MO')
        ->and($series->exdates)->toBe(['2026-11-16T09:30:00'])
        ->and($series->sequence)->toBe(2)
        ->and($series->description)->toBe('Weekly standup, bring updates')
        ->and($series->raw)->toContain('X-GOOGLE-CONFERENCE');

    $moved = $events['Standup (moved to Tuesday)'];
    expect($moved->isOverride())->toBeTrue()
        ->and($moved->rrule)->toBeNull()
        ->and($moved->recurrenceKey(false, 'Europe/London', $series->startsAt))->toBe('2026-11-23T09:30:00');

    expect($events['Standup [cancelled]']->cancelled)->toBeTrue()
        ->and($events['Standup [cancelled]']->isOverride())->toBeTrue();

    $conference = $events['Winter conference'];
    expect($conference->allDay)->toBeTrue()
        ->and($conference->startsAt->toDateString())->toBe('2026-12-18')
        ->and($conference->endsAt->toDateString())->toBe('2026-12-22')
        ->and($conference->free)->toBeTrue();

    expect($events['One-to-one']->private)->toBeTrue()
        ->and($events['One-to-one']->tzid)->toBe('UTC');
});

it('resolves Outlook’s Windows zone names to IANA ones', function () {
    $events = parsedByTitle('outlook.ics');

    expect($events['Board meeting']->tzid)->toBe('Europe/London')
        ->and($events['Board meeting']->startsAt->toIso8601String())->toBe('2026-12-03T09:00:00+00:00')
        ->and($events['Board meeting']->rrule)->toBe('FREQ=MONTHLY;UNTIL=20270301T090000Z;INTERVAL=1;BYDAY=1TH')
        ->and($events['Board meeting']->exdates)->toBe(['2027-01-07T09:00:00'])
        ->and($events['Berlin call']->tzid)->toBe('Europe/Berlin')
        // 14:00 in Berlin is 13:00 UTC in December.
        ->and($events['Berlin call']->startsAt->toIso8601String())->toBe('2026-12-01T13:00:00+00:00')
        ->and($events['Berlin call']->private)->toBeTrue()
        ->and($events['Berlin call']->free)->toBeTrue();
});

it('reads an Apple calendar: a named zone, a floating time with DURATION, multi-day all-day', function () {
    $events = parsedByTitle('apple.ics', 'Europe/London');

    expect($events['Dinner in New York']->tzid)->toBe('America/New_York')
        ->and($events['Dinner in New York']->startsAt->toIso8601String())->toBe('2026-12-05T23:00:00+00:00')
        ->and($events['Dinner in New York']->location)->toBe("Joe's New York");

    // Floating: 07:30 wherever the reader is.
    expect($events['Morning swim']->tzid)->toBe('Europe/London')
        ->and($events['Morning swim']->startsAt->toIso8601String())->toBe('2026-12-10T07:30:00+00:00')
        ->and($events['Morning swim']->endsAt->toIso8601String())->toBe('2026-12-10T08:15:00+00:00');

    $inNewYork = parsedByTitle('apple.ics', 'America/New_York')['Morning swim'];
    expect($inNewYork->tzid)->toBe('America/New_York')
        ->and($inNewYork->startsAt->toIso8601String())->toBe('2026-12-10T12:30:00+00:00');

    expect($events['Christmas at Gran\'s']->allDay)->toBeTrue()
        ->and($events['Christmas at Gran\'s']->endsAt->diffInDays($events['Christmas at Gran\'s']->startsAt, true))->toEqual(3);
});

it('flags everything in a METHOD:CANCEL file as cancelled', function () {
    $result = IcsParser::parse(icsFixture('cancel.ics'), 'Europe/London');

    expect($result->events)->toHaveCount(1)
        ->and($result->live())->toBe([])
        ->and($result->cancelledCount())->toBe(1);
});

it('skips a bad event without losing the rest', function () {
    $result = IcsParser::parse(icsFixture('broken.ics'), 'Europe/London');

    expect(array_map(fn (ParsedEvent $e) => $e->title, $result->events))->toBe(['Good event'])
        ->and($result->errors)->toHaveCount(3)
        ->and($result->errors[0])->toContain('No start')
        ->and($result->errors[1])->toContain('repeat rule');
});

it('caps how many events it reads', function () {
    $body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//EN\r\n";

    for ($i = 0; $i < 12; $i++) {
        $body .= "BEGIN:VEVENT\r\nUID:e{$i}@t\r\nDTSTART:20261201T100000Z\r\nSUMMARY:E{$i}\r\nEND:VEVENT\r\n";
    }

    $result = IcsParser::parse($body."END:VCALENDAR\r\n", 'UTC', max: 10);

    expect($result->events)->toHaveCount(10)
        ->and($result->truncated)->toBeTrue()
        ->and($result->errors[count($result->errors) - 1])->toContain('first 10');
});

it('keeps the latest copy of an event listed twice', function () {
    $event = fn (int $seq, string $title) => "BEGIN:VEVENT\r\nUID:dup@t\r\nDTSTART:20261201T100000Z\r\nSEQUENCE:{$seq}\r\nSUMMARY:{$title}\r\nEND:VEVENT\r\n";
    $body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//EN\r\n".$event(2, 'Newer').$event(1, 'Older')."END:VCALENDAR\r\n";

    $result = IcsParser::parse($body, 'UTC');

    expect($result->events)->toHaveCount(1)
        ->and($result->events[0]->title)->toBe('Newer');
});

it('refuses something that isn’t a calendar', function (string $body) {
    IcsParser::parse($body, 'UTC');
})->with([
    'html' => '<html><body>Sign in</body></html>',
    'vcard' => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Sam\r\nEND:VCARD\r\n",
])->throws(FeedException::class);

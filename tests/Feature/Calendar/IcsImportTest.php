<?php

use App\Features\Calendar as CalendarFeature;
use App\Features\Crews;
use App\Models\Calendar;
use App\Models\Crew;
use App\Models\CrewMember;
use App\Models\Event as CalendarEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 12:00', 'Europe/London'));
});

function icsImporter(array $attributes = []): User
{
    $user = User::factory()->create(['timezone' => 'Europe/London'] + $attributes);
    Feature::for($user)->activate([CalendarFeature::class, Crews::class]);

    return $user;
}

function icsUpload(string $fixture = 'google.ics', ?string $contents = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($fixture, $contents ?? (string) file_get_contents(base_path("tests/Fixtures/ics/{$fixture}")));
}

it('shows the import page with the calendars you can add to', function () {
    $me = icsImporter();
    $mine = Calendar::ensurePersonal($me);

    $this->actingAs($me)->get('/calendar/import')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('calendar/import')
        ->where('calendars.0.id', $mine->id)
        ->where('preview', null));
});

it('previews a file without importing anything', function () {
    $me = icsImporter();
    $calendar = Calendar::ensurePersonal($me);

    $this->actingAs($me)->post('/calendar/import/preview', ['file' => icsUpload(), 'calendar_id' => $calendar->id])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('calendar/import')
            ->where('preview.calendar_id', $calendar->id)
            ->where('preview.name', 'Work')
            ->where('preview.new', 4)
            ->where('preview.existing', 0)
            ->where('preview.cancelled', 1)
            ->where('preview.repeating', 1)
            ->where('preview.errors', [])
            ->where('preview.from', '2026-11-02T09:30:00+00:00')
            ->where('preview.to', '2026-12-22T00:00:00+00:00'));

    expect(CalendarEvent::count())->toBe(0);
});

it('lists what can’t be read in the preview', function () {
    $me = icsImporter();

    $this->actingAs($me)->post('/calendar/import/preview', ['file' => icsUpload('broken.ics'), 'calendar_id' => Calendar::ensurePersonal($me)->id])
        ->assertInertia(fn (Assert $page) => $page
            ->where('preview.new', 1)
            ->has('preview.errors', 3));
});

it('imports into your calendar as ordinary, editable events', function () {
    $me = icsImporter();
    $calendar = Calendar::ensurePersonal($me);

    $this->actingAs($me)->post('/calendar/import', ['file' => icsUpload(), 'calendar_id' => $calendar->id])
        ->assertRedirect('/calendar?date=2026-11-02')
        ->assertSessionHas('status', 'Imported 4 events into “My calendar”.');

    $events = $calendar->events()->get()->keyBy('title');
    $series = $events['Standup'];
    $moved = $events['Standup (moved to Tuesday)'];

    expect($events)->toHaveCount(4)
        ->and($series->source)->toBe('local')
        ->and($series->feed_subscription_id)->toBeNull()
        ->and($series->uid)->toBe('standup-2026@google.com')
        ->and($series->external_uid)->toBe('standup-2026@google.com')
        ->and($series->raw_ical)->toContain('X-GOOGLE-CONFERENCE')
        ->and($series->sequence)->toBe(2)
        ->and($series->created_by_user_id)->toBe($me->id)
        ->and($series->exdates)->toBe(['2026-11-16T09:30:00', '2026-11-30T09:30:00'])
        ->and($moved->series_id)->toBe($series->id)
        ->and($moved->recurrence_id)->toBe('2026-11-23T09:30:00')
        // An edited date shares its series' UID in iCal; ours must be unique.
        ->and($moved->uid)->not->toBe('standup-2026@google.com');

    // It's yours now: editable like anything else.
    $this->actingAs($me)->get("/calendar/events/{$series->id}/edit")->assertOk();
    $this->actingAs($me)->delete("/calendar/events/{$events['One-to-one']->id}")->assertRedirect();
    expect($events['One-to-one']->fresh()->trashed())->toBeTrue();
});

it('doesn’t duplicate anything when the same file is imported again', function () {
    $me = icsImporter();
    $calendar = Calendar::ensurePersonal($me);
    $this->actingAs($me)->post('/calendar/import', ['file' => icsUpload(), 'calendar_id' => $calendar->id]);

    $this->actingAs($me)->post('/calendar/import/preview', ['file' => icsUpload(), 'calendar_id' => $calendar->id])
        ->assertInertia(fn (Assert $page) => $page->where('preview.new', 0)->where('preview.existing', 4));

    $this->actingAs($me)->post('/calendar/import', ['file' => icsUpload(), 'calendar_id' => $calendar->id])
        ->assertSessionHas('status', 'Imported 0 events into “My calendar” · 4 were already there.');

    expect($calendar->events()->count())->toBe(4);
});

it('gives an event a new UID when its own is taken, and still spots a re-import', function () {
    $me = icsImporter();
    $first = Calendar::ensurePersonal($me);
    $second = new Calendar(['name' => 'Other', 'colour' => Calendar::COLOURS[3]]);
    $second->owner()->associate($me)->save();

    $this->actingAs($me)->post('/calendar/import', ['file' => icsUpload(), 'calendar_id' => $first->id]);
    $this->actingAs($me)->post('/calendar/import', ['file' => icsUpload(), 'calendar_id' => $second->id]);

    $copy = $second->events()->where('title', 'Standup')->sole();
    expect($copy->uid)->not->toBe('standup-2026@google.com')
        ->and($copy->external_uid)->toBe('standup-2026@google.com');

    $this->actingAs($me)->post('/calendar/import', ['file' => icsUpload(), 'calendar_id' => $second->id]);
    expect($second->events()->count())->toBe(4);
});

it('imports Outlook, Apple and a file of cancellations', function () {
    $me = icsImporter();
    $calendar = Calendar::ensurePersonal($me);

    $this->actingAs($me)->post('/calendar/import', ['file' => icsUpload('outlook.ics'), 'calendar_id' => $calendar->id]);
    $this->actingAs($me)->post('/calendar/import', ['file' => icsUpload('apple.ics'), 'calendar_id' => $calendar->id]);

    $events = $calendar->events()->get()->keyBy('title');
    expect($events['Board meeting']->tzid)->toBe('Europe/London')
        ->and($events['Berlin call']->tzid)->toBe('Europe/Berlin')
        ->and($events['Morning swim']->tzid)->toBe('Europe/London')
        ->and($events["Christmas at Gran's"]->all_day)->toBeTrue();

    $this->actingAs($me)->post('/calendar/import/preview', ['file' => icsUpload('cancel.ics'), 'calendar_id' => $calendar->id])
        ->assertInertia(fn (Assert $page) => $page->where('preview.new', 0)->where('preview.cancelled', 1));
});

it('refuses a file that isn’t a calendar, or is too big', function () {
    $me = icsImporter();
    $calendar = Calendar::ensurePersonal($me);

    $this->actingAs($me)->post('/calendar/import/preview', ['file' => icsUpload('x.ics', '<html>nope</html>'), 'calendar_id' => $calendar->id])
        ->assertSessionHasErrors(['file' => 'That isn’t an iCalendar (.ics) file Offing can read.']);

    $this->actingAs($me)->post('/calendar/import/preview', ['file' => icsUpload('x.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"), 'calendar_id' => $calendar->id])
        ->assertSessionHasErrors(['file' => 'There are no events in that file.']);

    $this->actingAs($me)->post('/calendar/import/preview', ['file' => UploadedFile::fake()->create('big.ics', 6 * 1024), 'calendar_id' => $calendar->id])
        ->assertSessionHasErrors(['file' => 'That file is too big (over 5 MB).']);
});

it('only imports into a calendar you can edit', function () {
    $me = icsImporter();
    $stranger = icsImporter();
    $theirs = Calendar::ensurePersonal($stranger);

    $this->actingAs($me)->post('/calendar/import', ['file' => icsUpload(), 'calendar_id' => $theirs->id])->assertNotFound();

    // A captains-only crew calendar, for a crewmate who isn't a captain.
    $crew = Crew::create(['name' => 'Club']);
    $crew->members()->create(['user_id' => $stranger->id, 'role' => CrewMember::CAPTAIN, 'joined_at' => now()]);
    $crew->members()->create(['user_id' => $me->id, 'role' => CrewMember::CREW, 'joined_at' => now()]);
    $club = new Calendar(['name' => 'Club', 'colour' => Calendar::COLOURS[1], 'editors' => Calendar::EDITORS_CAPTAINS]);
    $club->owner()->associate($crew)->save();

    $this->actingAs($me)->post('/calendar/import', ['file' => icsUpload(), 'calendar_id' => $club->id])->assertForbidden();

    expect(CalendarEvent::count())->toBe(0);
});

it('is behind the calendar flag', function () {
    $this->actingAs(User::factory()->create())->get('/calendar/import')->assertNotFound();
});

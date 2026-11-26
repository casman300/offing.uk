<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Calendar\Concerns\FindsEvents;
use App\Http\Controllers\Controller;
use App\Models\Calendar;
use App\Models\User;
use App\Support\Calendar\Agenda;
use App\Support\Calendar\Ics\FileImport;
use App\Support\Calendar\Ics\IcsParser;
use App\Support\Calendar\Ics\ParseResult;
use App\Support\Feeds\FeedException;
use App\Support\Feeds\FeedFetcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One-off .ics import (spec §13): upload, preview what would be imported,
 * then import into one of your editable calendars as ordinary events.
 *
 * Nothing is kept between preview and import — the page sends the same
 * file again to confirm — so an abandoned preview leaves no copy of
 * anyone's calendar behind.
 */
class IcsImportController extends Controller
{
    use FindsEvents;

    public function create(Request $request): Response
    {
        return $this->page($request->user());
    }

    public function preview(Request $request): Response
    {
        $user = $request->user();
        [$parsed, $calendar] = $this->read($request);

        return $this->page($user, FileImport::preview($parsed, $calendar) + ['calendar_id' => $calendar->id]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        [$parsed, $calendar] = $this->read($request);
        $result = FileImport::import($parsed, $calendar, $user);

        $parts = [$result['imported'] === 1 ? 'Imported 1 event' : "Imported {$result['imported']} events"];
        $parts[0] .= " into “{$calendar->name}”";

        if ($result['existing'] > 0) {
            $parts[] = $result['existing'] === 1 ? '1 was already there' : "{$result['existing']} were already there";
        }

        if ($parsed->errors !== []) {
            $parts[] = count($parsed->errors) === 1 ? '1 couldn’t be read' : 'some couldn’t be read';
        }

        return to_route('calendar.index', $result['first'] !== null ? ['date' => $result['first']] : [])
            ->with('status', implode(' · ', $parts).'.');
    }

    /**
     * @return array{0: ParseResult, 1: Calendar}
     */
    private function read(Request $request): array
    {
        $user = $request->user();
        $request->validate([
            'file' => ['required', 'file', 'max:'.intdiv(FeedFetcher::maxBytes(), 1024)],
            'calendar_id' => ['required', 'integer'],
        ], [
            'file.max' => 'That file is too big (over '.intdiv(FeedFetcher::maxBytes(), 1024 * 1024).' MB).',
        ]);

        $calendar = $this->editableCalendar($user, $request->integer('calendar_id'));
        $contents = (string) file_get_contents($request->file('file')->getRealPath());

        try {
            $parsed = IcsParser::parse($contents, $user->timezone ?? User::DEFAULT_TIMEZONE);
        } catch (FeedException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        if ($parsed->events === [] && $parsed->errors === []) {
            throw ValidationException::withMessages(['file' => 'There are no events in that file.']);
        }

        return [$parsed, $calendar];
    }

    /**
     * @param  array<string, mixed>|null  $preview
     */
    private function page(User $user, ?array $preview = null): Response
    {
        return Inertia::render('calendar/import', [
            'calendars' => Agenda::calendars($user)
                ->filter(fn (Calendar $c) => $c->editableBy($user))
                ->map(fn (Calendar $c) => ['id' => $c->id, 'name' => $c->name, 'colour' => $c->colour, 'owner' => $c->ownerLabel()])
                ->values(),
            'preview' => $preview,
            'timezone' => $user->timezone ?? User::DEFAULT_TIMEZONE,
        ]);
    }
}

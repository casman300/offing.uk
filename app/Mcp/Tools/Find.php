<?php

namespace App\Mcp\Tools;

use App\Features\BirthdayEngine;
use App\Features\Crews;
use App\Features\Shipshape;
use App\Mcp\Text;
use App\Models\Chore;
use App\Models\ChoreEntry;
use App\Models\ChoreList;
use App\Models\Crew;
use App\Models\CrewMember;
use App\Models\Person;
use App\Models\User;
use App\Support\Briefing;
use App\Support\Shipshape\Board;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

/**
 * The one broad read after the briefing (spec §12): any mix of free text,
 * a date range, a person, a crew or a chore. Filters narrow each other.
 * It sees exactly what the user sees in the app, and only the parts whose
 * feature is on for them.
 */
#[Name('find')]
#[Title('Find')]
#[Description("Look things up in Offing. Combine any of: text (matches people's names and notes, chores, chore lists and crews), from/to dates (birthdays and chores due in that range), person_id (everything about one person), crew_id (a crew's members with member ids, and its chores), chore_id (one chore with its recent log entries and entry ids, needed to undo one). Use the ids from get_briefing.")]
#[IsReadOnly]
#[IsIdempotent]
class Find extends Tool
{
    /** Per section, so one call stays small. */
    public const MAX_LINES = 50;

    /** Longest date range in one call. */
    public const MAX_DAYS = 366;

    public const LOG_ENTRIES = 10;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $today = $user->today();

        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'person_id' => ['nullable', 'integer'],
            'crew_id' => ['nullable', 'integer'],
            'chore_id' => ['nullable', 'integer'],
        ], [
            'to.after_or_equal' => '`to` must be on or after `from`.',
        ]);

        $text = filled($data['text'] ?? null) ? trim($data['text']) : null;
        [$from, $to] = $this->range($data['from'] ?? null, $data['to'] ?? null, $today);

        if ($text === null && $from === null && ! isset($data['person_id']) && ! isset($data['crew_id']) && ! isset($data['chore_id'])) {
            return Response::error('Give at least one of text, from/to, person_id, crew_id or chore_id. For an overview, call get_briefing.');
        }

        if ($from !== null && $from->diffInDays($to) > self::MAX_DAYS) {
            return Response::error('That range is too long — ask for '.self::MAX_DAYS.' days or fewer at a time.');
        }

        $features = Feature::for($user);
        $people = $features->active(BirthdayEngine::class);
        $chores = $features->active(Shipshape::class);
        $crews = $features->active(Crews::class);

        $crew = null;
        if (isset($data['crew_id'])) {
            $crew = $crews ? $this->crew($user, (int) $data['crew_id']) : null;

            if ($crew === null) {
                return Response::error("No crew {$data['crew_id']} — check the ids in get_briefing.");
            }
        }

        $sections = [];

        if (isset($data['person_id'])) {
            $person = $people ? $user->people()->find((int) $data['person_id']) : null;
            $sections[] = $person ? $this->person($person, $today) : "No person {$data['person_id']} in the user's address book.";
        }

        if (isset($data['chore_id'])) {
            $chore = $chores ? $this->viewableChore($user, (int) $data['chore_id']) : null;
            $sections[] = $chore ? $this->chore($chore, $user, $today) : "No chore {$data['chore_id']} on the user's lists.";
        }

        if ($crew !== null) {
            $sections[] = $this->crewMembers($crew, $user);
        }

        if ($crews && $text !== null && $crew === null) {
            $sections[] = $this->crewsMatching($user, $text);
        }

        if ($people && $crew === null && ($text !== null || $from !== null)) {
            $sections[] = $from !== null
                ? $this->birthdaysBetween($user, $from, $to, $text)
                : $this->peopleMatching($user, $text);
        }

        if ($chores && ($text !== null || $from !== null || $crew !== null)) {
            $sections[] = $this->choresMatching($user, $today, $text, $from, $to, $crew);
        }

        $sections = array_filter($sections);

        return Response::text($sections === [] ? 'Nothing found.' : implode("\n\n", $sections));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('Words to look for, e.g. "Sue" or "bins".'),
            'from' => $schema->string()->description('Start date, YYYY-MM-DD. With only `from`, the range runs 30 days.'),
            'to' => $schema->string()->description('End date, YYYY-MM-DD, inclusive. With only `to`, the range starts today.'),
            'person_id' => $schema->integer()->description('A person id, e.g. 12 from "(person 12)".'),
            'crew_id' => $schema->integer()->description('A crew id, e.g. 3 from "(crew 3)".'),
            'chore_id' => $schema->integer()->description('A chore id, e.g. 4 from "(chore 4)".'),
        ];
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function range(?string $from, ?string $to, CarbonImmutable $today): array
    {
        if ($from === null && $to === null) {
            return [null, null];
        }

        $from = $from !== null ? CarbonImmutable::parse($from, 'UTC') : CarbonImmutable::parse($today->toDateString(), 'UTC');
        $to = $to !== null ? CarbonImmutable::parse($to, 'UTC') : $from->addDays(30);

        return [$from, $to];
    }

    private function crew(User $user, int $id): ?Crew
    {
        return CrewMember::query()->active()->where('user_id', $user->id)->where('crew_id', $id)->with('crew')->first()?->crew;
    }

    private function viewableChore(User $user, int $id): ?Chore
    {
        $chore = Chore::query()->with('list.owner')->find($id);

        return $chore?->list?->viewableBy($user) ? $chore : null;
    }

    private function person(Person $person, CarbonImmutable $today): string
    {
        $birthday = $person->birthday();
        $lines = ["{$person->name} (person {$person->id})"];

        if ($birthday->hasDate()) {
            $date = $this->dayMonth($birthday->day, $birthday->month, 'F').($birthday->year !== null ? " {$birthday->year}" : ', year unknown');
            $days = $birthday->daysUntil($today);
            $next = Text::date($birthday->nextOccurrence($today));
            $when = match ($days) {
                0 => 'today',
                1 => 'tomorrow',
                default => "in {$days} days",
            };
            $age = $birthday->age($today);
            $lines[] = "Birthday: {$date}. Next: {$next}, {$when}".($birthday->turns($today) !== null ? ", turns {$birthday->turns($today)}" : '').'.';
            $lines[] = $age !== null ? "Age: {$age}." : 'Age: unknown.';
        } elseif ($birthday->isYearOnly()) {
            $lines[] = "Born {$birthday->year}, date unknown (about {$birthday->approximateAge($today)}).";
        } else {
            $lines[] = 'Birthday: not set.';
        }

        $lines[] = filled($person->notes) ? "Notes: {$person->notes}" : 'Notes: none.';

        return implode("\n", $lines);
    }

    private function chore(Chore $chore, User $user, CarbonImmutable $today): string
    {
        $row = Board::chore($chore, $user, $today);
        $every = $chore->every === 1 ? $chore->unit : "{$chore->every} {$chore->unit}s";
        $how = $chore->isFixed() ? "on a fixed schedule, every {$every}" : "every {$every} after it's last done";

        $lines = [
            ltrim(Text::chore($row, $chore->list->name), '- '),
            'Repeats '.$how.'.'.(filled($chore->area) ? " Area: {$chore->area}." : ''),
        ];

        if (filled($chore->notes)) {
            $lines[] = "Notes: {$chore->notes}";
        }

        if ($chore->list->isCrewList()) {
            $members = $chore->list->assignableMembers()
                ->map(fn (CrewMember $m) => "{$m->displayName()} (member {$m->id})".($m->user_id === $user->id ? ' — you' : ''))
                ->implode(', ');
            $lines[] = "Can be done by: {$members}.";
        }

        $entries = $chore->entries()->latest('id')->limit(self::LOG_ENTRIES)->get();
        $lines[] = $entries->isEmpty() ? 'Log: nothing yet.' : "Log, newest first:\n".$entries->map(function (ChoreEntry $e) {
            $what = $e->kind === ChoreEntry::DONE ? 'done' : 'skipped';
            $reason = $e->reason ? " ({$e->reason})" : '';
            $voided = $e->voided_at !== null ? ' — undone' : '';
            $via = $e->via === 'ai' ? ', via an AI assistant' : '';

            return "- {$what} ".CarbonImmutable::parse($e->done_on)->format('D j M Y')." by {$e->done_by_name}{$reason} (entry {$e->id}{$via}){$voided}";
        })->implode("\n");

        return implode("\n", $lines);
    }

    private function crewMembers(Crew $crew, User $user): string
    {
        $members = $crew->activeMembers()->with(['user:id,name', 'person:id,name'])->orderBy('joined_at')->get()
            ->map(fn (CrewMember $m) => "- {$m->displayName()} (member {$m->id}): ".Str::ucfirst($m->role).($m->user_id === $user->id ? ' — you' : ''));

        return "{$crew->name} (crew {$crew->id}) aboard:\n".$members->implode("\n");
    }

    private function crewsMatching(User $user, string $text): ?string
    {
        $matches = $user->crewMemberships()->with('crew')->get()
            ->pluck('crew')->filter()
            ->filter(fn (Crew $crew) => Str::contains($crew->name, $text, ignoreCase: true));

        return $matches->isEmpty() ? null : "Crews:\n".$matches->map(fn (Crew $c) => "- {$c->name} (crew {$c->id})")->implode("\n");
    }

    private function peopleMatching(User $user, string $text): string
    {
        $like = '%'.addcslashes($text, '%_\\').'%';
        $matches = $user->people()
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('notes', 'like', $like))
            ->orderBy('name')->get();

        if ($matches->isEmpty()) {
            return "People matching “{$text}”: none.";
        }

        $lines = $matches->map(function (Person $p) use ($text) {
            $birthday = $p->birthday();
            $when = $birthday->hasDate() ? ', birthday '.$this->dayMonth($birthday->day, $birthday->month, 'M') : '';
            $note = ! Str::contains($p->name, $text, ignoreCase: true) ? ' — matched in notes' : '';

            return "- {$p->name} (person {$p->id}){$when}{$note}";
        });

        return "People matching “{$text}”:\n".Text::capped($lines, self::MAX_LINES);
    }

    private function birthdaysBetween(User $user, CarbonImmutable $from, CarbonImmutable $to, ?string $text): string
    {
        $rows = collect();

        foreach ($user->people()->whereNotNull('birth_day')->whereNotNull('birth_month')->get() as $person) {
            if ($text !== null && ! Str::contains($person->name.' '.$person->notes, $text, ignoreCase: true)) {
                continue;
            }

            for ($year = $from->year; $year <= $to->year; $year++) {
                $on = $person->birthday()->observedIn($year);

                if ($on->betweenIncluded($from, $to)) {
                    $turns = $person->birth_year !== null ? ', turns '.($year - $person->birth_year) : '';
                    $rows->push(['on' => $on, 'line' => '- '.Text::date($on)." {$year}: {$person->name} (person {$person->id}){$turns}"]);
                }
            }
        }

        $heading = 'Birthdays '.Text::date($from).' – '.Text::date($to).($text !== null ? " matching “{$text}”" : '');

        return $rows->isEmpty()
            ? "{$heading}: none."
            : "{$heading}:\n".Text::capped($rows->sortBy('on')->pluck('line'), self::MAX_LINES);
    }

    private function choresMatching(User $user, CarbonImmutable $today, ?string $text, ?CarbonImmutable $from, ?CarbonImmutable $to, ?Crew $crew): string
    {
        $lists = Board::visibleLists($user);

        if ($crew !== null) {
            $lists = $lists->filter(fn (ChoreList $l) => $l->isCrewList() && (int) $l->owner_id === $crew->id)->values();
        }

        $listNames = $lists->pluck('name', 'id');
        $rows = Briefing::chores($user, $lists, $today, ['overdue', 'today', 'soon', 'later', 'nodate'])
            ->filter(fn (array $c) => $text === null || Str::contains(
                implode(' ', [$c['title'], $c['area'], $c['notes'], $listNames[$c['list_id']]]), $text, ignoreCase: true,
            ))
            ->filter(fn (array $c) => $from === null || ($c['due_on'] !== null && CarbonImmutable::parse($c['due_on'], 'UTC')->betweenIncluded($from, $to)));

        $heading = collect([
            $crew !== null ? "on {$crew->name}'s lists" : null,
            $text !== null ? "matching “{$text}”" : null,
            $from !== null ? 'due '.Text::date($from).' – '.Text::date($to) : null,
        ])->filter()->implode(', ');

        return $rows->isEmpty()
            ? "Chores {$heading}: none."
            : "Chores {$heading}:\n".Text::capped($rows->map(fn (array $c) => Text::chore($c, $listNames[$c['list_id']])), self::MAX_LINES);
    }

    /** "25 October" / "25 Oct" — a birthday without its year. */
    private function dayMonth(int $day, int $month, string $monthFormat): string
    {
        return $day.' '.CarbonImmutable::create(2000, $month, 1)->format($monthFormat);
    }
}

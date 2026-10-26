<?php

namespace App\Mcp\Tools;

use App\Features\BirthdayEngine;
use App\Features\Crews;
use App\Features\Shipshape;
use App\Mcp\Text;
use App\Mcp\Tools\Concerns\ActsForGrant;
use App\Models\CrewMember;
use App\Models\User;
use App\Support\Briefing;
use App\Support\Shipshape\Board;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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
 * Everything most questions need, in one call (spec §12): compact text with
 * ids inline, so Claude can answer — or act — without lookups. Each part
 * only appears if its feature is on for the user, as on the dashboard.
 */
#[Name('get_briefing')]
#[Title('Get briefing')]
#[Description("Start here. Returns the user's name, timezone, today's date, this connection's access level, their crews, people (with ids), birthdays in the next 30 days and Shipshape chores overdue, due today or coming up.")]
#[IsReadOnly]
#[IsIdempotent]
class GetBriefing extends Tool
{
    use ActsForGrant;

    public const BIRTHDAY_DAYS = 30;

    /** Enough for any real address book; the rest are counted, not listed. */
    public const MAX_PEOPLE = 200;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $today = $user->today();
        $features = Feature::for($user);
        $sections = [$this->header($user, $today)];

        if ($features->active(Crews::class)) {
            $sections[] = $this->crews($user);
        }

        if ($features->active(BirthdayEngine::class)) {
            $sections[] = $this->birthdays($user, $today);
            $sections[] = $this->people($user);
        }

        if ($features->active(Shipshape::class)) {
            $sections[] = $this->chores($user, $today);
        }

        return Response::text(implode("\n\n", array_filter($sections)));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    private function header(User $user, CarbonImmutable $today): string
    {
        $grant = $this->grant();

        return implode("\n", [
            "Offing briefing for {$user->name}.",
            'Today is '.$today->format('l j F Y').", timezone {$user->timezone}.",
            "This connection's access: {$this->level()->label()}. ".($grant?->include_private ? 'Private events: included.' : 'Private events: hidden.'),
            'Calendar events are not in Offing yet; birthdays and chores are.',
        ]);
    }

    private function crews(User $user): string
    {
        $memberships = $user->crewMemberships()->with('crew')->get()->filter(fn (CrewMember $m) => $m->crew !== null);

        if ($memberships->isEmpty()) {
            return 'Crews: none.';
        }

        $lines = $memberships->sortBy(fn (CrewMember $m) => $m->crew->name)->map(function (CrewMember $m) {
            $aboard = $m->crew->activeMembers()->count();

            return "- {$m->crew->name} (crew {$m->crew->id}): you are {$m->role}, {$aboard} aboard";
        });

        return "Crews:\n".$lines->implode("\n");
    }

    private function birthdays(User $user, CarbonImmutable $today): string
    {
        $birthdays = Briefing::birthdays($user, $today, self::BIRTHDAY_DAYS);

        if ($birthdays->isEmpty()) {
            return 'Birthdays in the next '.self::BIRTHDAY_DAYS.' days: none.';
        }

        $lines = $birthdays->map(function (array $b) {
            $when = match ($b['days']) {
                0 => 'today',
                1 => 'tomorrow',
                default => "in {$b['days']} days",
            };
            $date = $b['date'] ? Text::date($b['date']) : '';
            $turns = $b['turns'] !== null ? ", turns {$b['turns']}" : '';

            return "- {$b['name']} (person {$b['id']}): {$date}, {$when}{$turns}";
        });

        return 'Birthdays in the next '.self::BIRTHDAY_DAYS." days:\n".$lines->implode("\n");
    }

    private function people(User $user): string
    {
        $total = $user->people()->count();

        if ($total === 0) {
            return 'People: none yet.';
        }

        $names = $user->people()->orderBy('name')->limit(self::MAX_PEOPLE)->get(['id', 'name'])
            ->map(fn ($p) => "{$p->name} ({$p->id})")
            ->implode(', ');
        $more = $total > self::MAX_PEOPLE ? ', and '.($total - self::MAX_PEOPLE).' more' : '';

        return "People ({$total}, name and person id): {$names}{$more}.";
    }

    private function chores(User $user, CarbonImmutable $today): string
    {
        $lists = Board::visibleLists($user);
        $chores = Briefing::chores($user, $lists, $today, ['overdue', 'today', 'soon']);

        if ($chores->isEmpty()) {
            return 'Shipshape chores: nothing overdue, due today or coming up.';
        }

        $listNames = $lists->pluck('name', 'id');
        $headings = ['overdue' => 'Overdue', 'today' => 'Due today', 'soon' => 'Coming up'];

        $parts = [];
        foreach ($headings as $group => $heading) {
            $rows = $chores->where('group', $group);

            if ($rows->isEmpty()) {
                continue;
            }

            $parts[] = "{$heading}:\n".$rows->map(fn (array $c) => Text::chore($c, $listNames[$c['list_id']]))->implode("\n");
        }

        return "Shipshape chores:\n".implode("\n", $parts);
    }
}

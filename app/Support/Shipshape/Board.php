<?php

namespace App\Support\Shipshape;

use App\Models\Chore;
use App\Models\ChoreList;
use App\Models\CrewMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Shapes chores for the board, a list page and the Dashboard (spec §25),
 * so every screen groups and labels them the same way:
 * overdue · today · next 7 days (plus heads-up renewals) · later · needs a date.
 */
final class Board
{
    public const SOON_DAYS = 7;

    /**
     * Every list the user can see, with who can be picked as "done by".
     *
     * @return Collection<int, ChoreList>
     */
    public static function visibleLists(User $user): Collection
    {
        return ChoreList::query()->visibleTo($user)->with('owner')->orderBy('name')->get();
    }

    /**
     * @return array<string, mixed>
     */
    public static function listSummary(ChoreList $list, User $user): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'owner' => $list->ownerLabel(),
            'is_crew' => $list->isCrewList(),
            'editable' => $list->editableBy($user),
            'members' => $list->assignableMembers()->map(fn (CrewMember $m) => [
                'id' => $m->id,
                'name' => $m->displayName(),
                'kind' => $m->isPassenger() ? 'passenger' : 'crew',
                'is_me' => $m->user_id === $user->id,
            ])->values(),
        ];
    }

    /**
     * @param  Collection<int, ChoreList>  $lists
     * @return Collection<int, Chore>
     */
    public static function choresOn(Collection $lists): Collection
    {
        return Chore::query()
            ->whereIn('chore_list_id', $lists->pluck('id'))
            ->with(['assignee.user:id,name', 'assignee.person:id,name', 'rotaMembers.user:id,name', 'rotaMembers.person:id,name'])
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public static function chore(Chore $chore, User $viewer, CarbonImmutable $today): array
    {
        $today = ChoreSchedule::date($today);
        $due = $chore->due_on === null ? null : ChoreSchedule::date($chore->due_on);
        $days = $due === null ? null : (int) $today->diffInDays($due, false);
        $headsUp = $due !== null && $chore->lead_days !== null && $days > self::SOON_DAYS && $days <= $chore->lead_days;

        $who = $chore->currentAssignee();

        return [
            'id' => $chore->id,
            'list_id' => $chore->chore_list_id,
            'title' => $chore->title,
            'area' => $chore->area,
            'notes' => $chore->notes,
            'mode' => $chore->mode,
            'every' => $chore->every,
            'unit' => $chore->unit,
            'due_on' => $due?->toDateString(),
            'days' => $days,
            'group' => self::group($days, $headsUp),
            'heads_up' => $headsUp,
            'lead_days' => $chore->lead_days,
            'assign' => $chore->assign,
            'who' => $who === null ? null : [
                'member_id' => $who->id,
                'name' => $who->displayName(),
                'is_me' => $who->user_id === $viewer->id,
                'kind' => $who->isPassenger() ? 'passenger' : 'crew',
            ],
            'version' => $chore->version,
        ];
    }

    public static function group(?int $days, bool $headsUp = false): string
    {
        return match (true) {
            $days === null => 'nodate',
            $days < 0 => 'overdue',
            $days === 0 => 'today',
            $days <= self::SOON_DAYS || $headsUp => 'soon',
            default => 'later',
        };
    }
}

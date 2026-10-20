<?php

namespace App\Support\Crews;

use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\CrewMember;
use App\Models\User;

/**
 * Every crew always has a captain (spec §7). The UI enforces "hand over
 * first", but deleting an account can't be refused for that, so when the
 * last captain's account goes, the longest-serving crewmate is promoted —
 * or, with no other account aboard, the crew goes with them.
 */
final class CrewHandover
{
    public static function forDeletedAccount(User $user): void
    {
        $captaincies = $user->crewMemberships()->where('role', CrewMember::CAPTAIN)->with('crew')->get();

        foreach ($captaincies as $membership) {
            $crew = $membership->crew;

            // A deleted crew drops out of the relation (null) — nothing to hand over.
            if ($crew === null || $crew->captainCount() > 1) {
                continue;
            }

            $next = self::longestServingCrewmate($crew, $user);

            if ($next !== null) {
                $next->update(['role' => CrewMember::CAPTAIN]);
                AuditLog::record(null, 'crew.captain_promoted', $crew, ['member_id' => $next->id, 'reason' => 'account_deleted']);

                continue;
            }

            $crew->delete();
            AuditLog::record(null, 'crew.deleted', $crew, ['name' => $crew->name, 'reason' => 'account_deleted']);
        }
    }

    private static function longestServingCrewmate(Crew $crew, User $except): ?CrewMember
    {
        return $crew->activeMembers()
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $except->id)
            ->orderBy('joined_at')
            ->orderBy('id')
            ->first();
    }
}

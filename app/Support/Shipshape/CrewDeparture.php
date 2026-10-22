<?php

namespace App\Support\Shipshape;

use App\Models\Chore;
use App\Models\CrewMember;
use Illuminate\Support\Facades\DB;

/**
 * What happens to chores when someone leaves a crew (spec §7/§25): chores
 * pinned to them become "anyone", and they drop out of every rota — if it
 * was their turn it passes to the next person. The log keeps their name.
 */
final class CrewDeparture
{
    public static function handle(CrewMember $member): void
    {
        DB::transaction(function () use ($member) {
            Chore::query()->where('assignee_member_id', $member->id)->get()->each(function (Chore $chore) {
                $chore->forceFill(['assign' => Chore::ANYONE, 'assignee_member_id' => null])->save();
                $chore->increment('version');
            });

            $rotaChoreIds = DB::table('chore_rota')->where('crew_member_id', $member->id)->pluck('chore_id');

            Chore::withTrashed()->whereIn('id', $rotaChoreIds)->get()->each(function (Chore $chore) use ($member) {
                $ids = DB::table('chore_rota')->where('chore_id', $chore->id)->orderBy('position')->pluck('crew_member_id')->all();
                $index = array_search($member->id, $ids, true);
                $turn = count($ids) > 0 ? $chore->rota_position % count($ids) : 0;

                $remaining = array_values(array_filter($ids, fn ($id) => $id !== $member->id));
                DB::table('chore_rota')->where('chore_id', $chore->id)->delete();
                foreach ($remaining as $position => $id) {
                    DB::table('chore_rota')->insert(['chore_id' => $chore->id, 'crew_member_id' => $id, 'position' => $position]);
                }

                // Someone earlier in the order left: everyone shifts up one.
                // The leaver held the turn: the next person (now at their
                // index) has it.
                if ($index !== false && $index < $turn) {
                    $turn--;
                }

                $chore->rota_position = count($remaining) > 0 ? $turn % count($remaining) : 0;
                $chore->version++;
                $chore->save();
            });
        });
    }
}

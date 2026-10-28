<?php

use App\Features\Features;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Pennant used to store every user's first resolved value; now the
 * `features` table holds explicit overrides only (OverridesOnlyDriver).
 * A stored value that matches what the user's stage gives them today
 * carries no information, so drop it — the user then follows future stage
 * changes. A value that differs is a real override and stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        $features = Features::all();

        User::query()->chunkById(200, function ($users) use ($features) {
            foreach ($users as $user) {
                foreach ($features as $name => $feature) {
                    DB::table('features')
                        ->where('name', $name)
                        ->where('scope', Feature::serializeScope($user))
                        ->where('value', json_encode($feature->resolve($user)))
                        ->delete();
                }
            }
        });
    }

    public function down(): void
    {
        // Nothing to restore: dropped rows only repeated the stage default.
    }
};

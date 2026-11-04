<?php

namespace App\Features;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Every feature flag, in one place — the admin page and `offing:feature`
 * both read from here. Add new features to this list.
 */
final class Features
{
    /**
     * @return list<class-string<StagedFeature>>
     */
    public static function classes(): array
    {
        return [
            BirthdayEngine::class,
            VcardImport::class,
            Reminders::class,
            Crews::class,
            Shipshape::class,
            ConnectClaude::class,
            Barometer::class,
            SignInDevices::class,
        ];
    }

    /**
     * @return array<string, StagedFeature> keyed by stored name
     */
    public static function all(): array
    {
        $features = [];

        foreach (self::classes() as $class) {
            $feature = new $class;
            $features[$feature->name] = $feature;
        }

        return $features;
    }

    public static function find(string $name): ?StagedFeature
    {
        return self::all()[$name] ?? null;
    }

    /**
     * The per-user overrides stored for these users, read straight from
     * Pennant's table (which holds nothing else — OverridesOnlyDriver).
     * Missing = no override; the stage decides.
     *
     * @param  list<string>  $names
     * @param  array<int, User>  $users
     * @return array<string, array<int, bool>> feature name => user id => value
     */
    public static function stored(array $names, array $users): array
    {
        $scopes = collect($users)->mapWithKeys(fn (User $user) => [Feature::serializeScope($user) => $user->id]);

        if ($scopes->isEmpty()) {
            return [];
        }

        $values = [];

        DB::table('features')
            ->whereIn('name', $names)
            ->whereIn('scope', $scopes->keys())
            ->get(['name', 'scope', 'value'])
            ->each(function ($row) use (&$values, $scopes) {
                $values[(string) $row->name][(int) $scopes[$row->scope]] = (bool) json_decode($row->value);
            });

        return $values;
    }
}

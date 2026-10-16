<?php

namespace App\Features;

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
}

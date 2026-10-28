<?php

namespace App\Features;

use Illuminate\Support\Collection;
use Laravel\Pennant\Drivers\DatabaseDriver;
use Laravel\Pennant\Feature;

/**
 * Pennant's database driver, minus one habit: it doesn't store what it
 * resolves. Out of the box Pennant saves every user's first answer, which
 * pins them to whatever stage was live that day. Here the `features` table
 * only ever holds explicit per-user overrides (activate/deactivate from
 * /admin/features, /admin/users or `offing:feature`); everyone else follows
 * the flag's current stage on every request, so a stage change reaches
 * them straight away and clearing overrides never loses a deliberate one.
 */
class OverridesOnlyDriver extends DatabaseDriver
{
    public function get($feature, $scope): mixed
    {
        if (($record = $this->retrieve($feature, $scope)) !== null) {
            return $this->decode($record);
        }

        return $this->resolveUnstored($feature, $scope);
    }

    public function getAll($features): array
    {
        $query = $this->newQuery();

        foreach ($features as $feature => $scopes) {
            foreach ($scopes as $scope) {
                $query->orWhere(fn ($q) => $q->where('name', $feature)->where('scope', Feature::serializeScope($scope)));
            }
        }

        $records = $query->get();

        return Collection::make($features)->map(fn ($scopes, $feature) => Collection::make($scopes)->map(function ($scope) use ($feature, $records) {
            $record = $records->where('name', $feature)->where('scope', Feature::serializeScope($scope))->first();

            return $record !== null
                ? $this->decode($record)
                : $this->resolveUnstored($feature, $scope);
        })->all())->all();
    }

    private function decode(object $record): mixed
    {
        return json_decode((string) data_get($record, 'value'), flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);
    }

    private function resolveUnstored(string $feature, mixed $scope): mixed
    {
        $value = $this->resolveValue($feature, $scope);

        return $value === $this->unknownFeatureValue ? false : $value;
    }
}

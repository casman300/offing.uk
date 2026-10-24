<?php

namespace App\Features;

use Illuminate\Support\Facades\DB;

/**
 * Rollout stages chosen on /admin/features, kept in `feature_stages`. A
 * flag with no row (or a stage this code no longer knows) follows the
 * `$stage` declared in its class. Bound as a scoped singleton, so the
 * table is read at most once per request however many flags are checked.
 */
class StageOverrides
{
    /** @var array<string, array{stage: Rollout, percentage: int}>|null */
    private ?array $rows = null;

    /**
     * @return array{stage: Rollout, percentage: int}|null
     */
    public function for(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    public function set(string $name, Rollout $stage, int $percentage): void
    {
        DB::table('feature_stages')->upsert(
            [['name' => $name, 'stage' => $stage->value, 'percentage' => $percentage, 'created_at' => now(), 'updated_at' => now()]],
            ['name'],
            ['stage', 'percentage', 'updated_at'],
        );

        $this->rows = null;
    }

    public function forget(string $name): void
    {
        DB::table('feature_stages')->where('name', $name)->delete();

        $this->rows = null;
    }

    /**
     * @return array<string, array{stage: Rollout, percentage: int}>
     */
    private function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $this->rows = [];

        foreach (DB::table('feature_stages')->get(['name', 'stage', 'percentage']) as $row) {
            $stage = Rollout::tryFrom((string) $row->stage);

            if ($stage !== null) {
                $this->rows[(string) $row->name] = ['stage' => $stage, 'percentage' => (int) $row->percentage];
            }
        }

        return $this->rows;
    }
}

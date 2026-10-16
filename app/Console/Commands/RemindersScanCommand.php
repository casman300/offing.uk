<?php

namespace App\Console\Commands;

use App\Support\Reminders\ReminderScanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RemindersScanCommand extends Command
{
    protected $signature = 'reminders:scan';

    protected $description = 'Queue birthday reminders that have come due (runs every 5 minutes)';

    public function handle(ReminderScanner $scanner): int
    {
        $totals = $scanner->scan(CarbonImmutable::now('UTC'));
        $this->info("Queued {$totals['queued']}, skipped {$totals['skipped']} (too late).");

        return self::SUCCESS;
    }
}

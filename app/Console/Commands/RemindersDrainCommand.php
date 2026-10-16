<?php

namespace App\Console\Commands;

use App\Support\Reminders\OutboxDrainer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RemindersDrainCommand extends Command
{
    protected $signature = 'reminders:drain';

    protected $description = 'Send due reminder emails from the outbox, retrying failures with backoff (runs every minute)';

    public function handle(OutboxDrainer $drainer): int
    {
        $t = $drainer->drain(CarbonImmutable::now('UTC'));
        $this->info("Sent {$t['sent']}, skipped {$t['skipped']}, retrying {$t['retrying']}, failed {$t['failed']}.");

        return self::SUCCESS;
    }
}

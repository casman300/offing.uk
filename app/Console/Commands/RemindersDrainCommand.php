<?php

namespace App\Console\Commands;

use App\Support\Barometer\CheckInDrainer;
use App\Support\Reminders\OutboxDrainer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RemindersDrainCommand extends Command
{
    protected $signature = 'reminders:drain';

    protected $description = 'Send due birthday reminders and Barometer check-ins from the outbox, retrying failures with backoff (runs every minute)';

    public function handle(OutboxDrainer $drainer, CheckInDrainer $checkIns): int
    {
        $now = CarbonImmutable::now('UTC');

        $t = $drainer->drain($now);
        $this->info("Reminders: sent {$t['sent']}, skipped {$t['skipped']}, retrying {$t['retrying']}, failed {$t['failed']}.");

        $c = $checkIns->drain($now);
        $this->info("Check-ins: sent {$c['sent']}, skipped {$c['skipped']}, retrying {$c['retrying']}, failed {$c['failed']}.");

        return self::SUCCESS;
    }
}

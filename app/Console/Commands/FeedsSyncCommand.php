<?php

namespace App\Console\Commands;

use App\Features\Calendar;
use App\Models\FeedSubscription;
use App\Support\Calendar\Ics\FeedSync;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;

/**
 * Fetches subscribed calendars that are due (spec §13). The host has no
 * daemons or queue workers, so the scheduler runs this every few minutes;
 * each run takes a bounded batch and stops before the next one starts.
 * A healthy feed is due hourly; a failing one backs off (FeedSubscription).
 */
class FeedsSyncCommand extends Command
{
    protected $signature = 'feeds:sync {--limit=20 : Most subscriptions to fetch this run} {--seconds=240 : Stop starting new fetches after this long}';

    protected $description = 'Fetch subscribed external calendars that are due (runs every 5 minutes)';

    public function handle(FeedSync $sync): int
    {
        $started = microtime(true);
        $limit = max(1, (int) $this->option('limit'));
        $budget = max(1, (int) $this->option('seconds'));
        $done = 0;
        $failed = 0;

        $due = FeedSubscription::query()->due()->with('user')->limit($limit)->get();

        foreach ($due as $subscription) {
            if (microtime(true) - $started > $budget) {
                break;
            }

            // The calendar was switched off for them: don't fetch on their behalf until it's back.
            if ($subscription->user === null || ! Feature::for($subscription->user)->active(Calendar::class)) {
                $subscription->forceFill(['next_fetch_at' => now()->addMinutes(FeedSubscription::INTERVAL_MINUTES)])->save();

                continue;
            }

            $sync->run($subscription);
            $done++;

            if ($subscription->status === FeedSubscription::ERROR) {
                $failed++;
            }
        }

        $this->info("Fetched {$done} subscription(s), {$failed} failed.");

        return self::SUCCESS;
    }
}

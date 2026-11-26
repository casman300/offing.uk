<?php

namespace App\Support\Calendar\Ics;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\FeedSubscription;
use App\Models\User;
use App\Support\Feeds\FeedException;
use App\Support\Feeds\FeedFetcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Brings a subscription's events in line with its source (spec §13):
 * fetch (conditional GET), skip if the body hasn't changed, parse, then
 * upsert by UID + RECURRENCE-ID and remove whatever the source dropped.
 * Imported events are source=feed, read-only, and keep their VEVENT.
 *
 * Failures never throw: they're recorded on the subscription (status,
 * reason, back-off) for Settings to show.
 */
final class FeedSync
{
    public function __construct(private readonly FeedFetcher $fetcher) {}

    public function run(FeedSubscription $subscription): void
    {
        try {
            $result = $this->fetcher->fetch($subscription->url, $subscription->etag);

            if ($result->notModified) {
                $subscription->markSucceeded(changed: false, etag: null, hash: null);

                return;
            }

            $hash = hash('sha256', $result->body);

            if ($hash === $subscription->last_hash) {
                $subscription->markSucceeded(changed: false, etag: $result->etag, hash: $hash);

                return;
            }

            $user = $subscription->user;
            $parsed = IcsParser::parse($result->body, $user->timezone ?? User::DEFAULT_TIMEZONE);
            $count = $this->apply($subscription, $parsed);

            $warning = $parsed->errors === [] ? null : self::warning($parsed);
            $subscription->markSucceeded(changed: true, etag: $result->etag, hash: $hash, eventCount: $count, warning: $warning);
        } catch (FeedException $e) {
            $subscription->markFailed($e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $subscription->markFailed('Something went wrong reading the calendar. Offing will try again later.');
        }
    }

    /**
     * Upserts the parsed events onto the subscription's calendar and
     * deletes the ones that are gone. All or nothing.
     *
     * @return int how many events the calendar now holds from this feed
     */
    public function apply(FeedSubscription $subscription, ParseResult $parsed): int
    {
        return DB::transaction(function () use ($subscription, $parsed) {
            $calendar = $subscription->calendar;

            if ($calendar === null) {
                $calendar = new Calendar(['name' => $subscription->name, 'colour' => $subscription->colour, 'created_by_user_id' => $subscription->user_id]);
                $calendar->owner()->associate($subscription->user);
                $calendar->forceFill(['feed_subscription_id' => $subscription->id])->save();
            }

            /** @var array<string, Event> $existing keyed external_uid|recurrence_id */
            $existing = Event::query()->withTrashed()
                ->where('feed_subscription_id', $subscription->id)
                ->get()
                ->keyBy(fn (Event $e) => $e->external_uid.'|'.($e->recurrence_id ?? ''))
                ->all();

            $live = $parsed->live();
            $masters = array_filter($live, fn (ParsedEvent $e) => ! $e->isOverride());
            $overrides = array_filter($live, fn (ParsedEvent $e) => $e->isOverride());
            $cancelledDates = self::cancelledDates($parsed);

            $seen = [];
            /** @var array<string, Event> $series */
            $series = [];

            foreach ($masters as $item) {
                $key = $item->uid.'|';
                $event = $existing[$key] ?? new Event;
                $attributes = $item->attributes();

                if ($item->rrule !== null && isset($cancelledDates[$item->uid])) {
                    $skipped = array_map(fn (ParsedEvent $c) => $c->recurrenceKey($item->allDay, $item->tzid, $item->startsAt), $cancelledDates[$item->uid]);
                    $all = array_values(array_unique([...$item->exdates, ...$skipped]));
                    sort($all);
                    $attributes['exdates'] = $all;
                }

                $this->save($event, $calendar, $subscription, $attributes + ['series_id' => null, 'recurrence_id' => null]);
                $seen[$key] = true;
                $series[$item->uid] = $event;
            }

            foreach ($overrides as $item) {
                $master = $series[$item->uid] ?? null;
                $recurrence = $master !== null
                    ? $item->recurrenceKey($master->all_day, $master->tzid, $master->starts_at)
                    : $item->recurrenceKey($item->allDay, $item->tzid, $item->startsAt);
                $key = $item->uid.'|'.$recurrence;

                if (isset($seen[$key])) {
                    continue;
                }

                $event = $existing[$key] ?? new Event;
                $this->save($event, $calendar, $subscription, $item->attributes() + ['series_id' => $master?->id, 'recurrence_id' => $recurrence]);
                $seen[$key] = true;
            }

            // Gone from the source: gone from Offing. Edited dates first, then series.
            $gone = array_filter($existing, fn (Event $e, string $key) => ! isset($seen[$key]), ARRAY_FILTER_USE_BOTH);
            usort($gone, fn (Event $a, Event $b) => ($b->series_id !== null) <=> ($a->series_id !== null));

            foreach ($gone as $event) {
                $event->forceDelete();
            }

            return count($seen);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function save(Event $event, Calendar $calendar, FeedSubscription $subscription, array $attributes): void
    {
        $event->forceFill($attributes + [
            'calendar_id' => $calendar->id,
            'feed_subscription_id' => $subscription->id,
            'source' => Event::SOURCE_FEED,
            'created_by_user_id' => $subscription->user_id,
            'deleted_at' => null,
        ]);

        if (! $event->exists || $event->isDirty()) {
            $event->save();
        }
    }

    /**
     * Cancelled dates of a series (STATUS:CANCELLED with a RECURRENCE-ID),
     * by UID — they become EXDATEs on it.
     *
     * @return array<string, list<ParsedEvent>>
     */
    public static function cancelledDates(ParseResult $parsed): array
    {
        $dates = [];

        foreach ($parsed->events as $event) {
            if ($event->cancelled && $event->isOverride()) {
                $dates[$event->uid][] = $event;
            }
        }

        return $dates;
    }

    private static function warning(ParseResult $parsed): string
    {
        if ($parsed->truncated) {
            return 'The calendar has more events than Offing can hold; only the first '.number_format(IcsParser::MAX_EVENTS).' were kept.';
        }

        return (count($parsed->errors) === 1 ? 'One event couldn’t be read and was skipped: ' : 'Some events couldn’t be read and were skipped, e.g. ').$parsed->errors[0];
    }
}

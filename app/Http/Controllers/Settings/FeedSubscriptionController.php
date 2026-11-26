<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Calendar;
use App\Models\FeedSubscription;
use App\Support\Calendar\Ics\FeedSync;
use App\Support\Feeds\FeedException;
use App\Support\Feeds\UrlGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Subscriptions (spec §13): subscribe to an external ICS URL,
 * then rename, recolour, refresh or remove it. Personal — only the
 * subscriber ever sees a subscription or its events.
 *
 * The URL is treated as a secret: it's checked and stored (encrypted), and
 * from then on only its host is shown or written to the audit log.
 */
class FeedSubscriptionController extends Controller
{
    /** "Refresh now" at most this often per subscription. */
    private const REFRESH_COOLDOWN_SECONDS = 60;

    public function index(Request $request): Response
    {
        $subscriptions = FeedSubscription::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('id')
            ->get();

        return Inertia::render('settings/subscriptions', [
            'colours' => Calendar::COLOURS,
            'max' => FeedSubscription::MAX_PER_USER,
            'subscriptions' => $subscriptions->map(fn (FeedSubscription $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'colour' => $s->colour,
                'host' => $s->host,
                'status' => $s->status,
                'last_error' => $s->last_error,
                'event_count' => $s->event_count,
                'last_fetched_at' => $s->last_fetched_at?->toIso8601String(),
                'last_changed_at' => $s->last_changed_at?->toIso8601String(),
                'next_fetch_at' => $s->next_fetch_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request, UrlGuard $guard, FeedSync $sync): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2000'],
            'name' => ['required', 'string', 'max:60'],
            'colour' => ['required', Rule::in(Calendar::COLOURS)],
        ]);

        $existing = FeedSubscription::query()->where('user_id', $user->id)->get();

        if ($existing->count() >= FeedSubscription::MAX_PER_USER) {
            throw ValidationException::withMessages(['url' => 'You can subscribe to up to '.FeedSubscription::MAX_PER_USER.' calendars. Remove one first.']);
        }

        $url = UrlGuard::normalise($data['url']);

        try {
            $target = $guard->check($url);
        } catch (FeedException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        if ($existing->contains(fn (FeedSubscription $s) => $s->url === $url)) {
            throw ValidationException::withMessages(['url' => 'You’re already subscribed to that calendar.']);
        }

        $subscription = FeedSubscription::subscribe($user, $url, $target['host'], trim($data['name']), $data['colour']);

        AuditLog::record($user, 'feed_subscription.added', $subscription, ['host' => $subscription->host, 'name' => $subscription->name], $request->ip());

        // Fetch straight away, so the calendar (or what's wrong with it) shows now, not at the next cron run.
        $sync->run($subscription);

        return to_route('subscriptions.index')->with('status', $subscription->status === FeedSubscription::OK
            ? "Subscribed to “{$subscription->name}”."
            : "Subscribed to “{$subscription->name}”, but it couldn’t be fetched yet — see below.");
    }

    public function update(Request $request, int $subscription): RedirectResponse
    {
        $model = $this->find($request, $subscription);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'colour' => ['required', Rule::in(Calendar::COLOURS)],
        ]);

        $model->rename(trim($data['name']), $data['colour']);

        return to_route('subscriptions.index')->with('status', 'Saved.');
    }

    public function refresh(Request $request, int $subscription, FeedSync $sync): RedirectResponse
    {
        $model = $this->find($request, $subscription);

        if ($model->last_fetched_at !== null && $model->last_fetched_at->diffInSeconds(now()) < self::REFRESH_COOLDOWN_SECONDS) {
            return to_route('subscriptions.index')->with('status', "“{$model->name}” was refreshed moments ago.");
        }

        $sync->run($model);

        return to_route('subscriptions.index')->with('status', $model->status === FeedSubscription::OK
            ? "Refreshed “{$model->name}”."
            : "Couldn’t refresh “{$model->name}” — see below.");
    }

    public function destroy(Request $request, int $subscription): RedirectResponse
    {
        $model = $this->find($request, $subscription);
        $model->unsubscribe();

        AuditLog::record($request->user(), 'feed_subscription.removed', $model, ['host' => $model->host, 'name' => $model->name], $request->ip());

        return to_route('subscriptions.index')->with('status', "Removed “{$model->name}” and its events.");
    }

    /** Someone else's subscription is a 404 — it isn't there for you. */
    private function find(Request $request, int $id): FeedSubscription
    {
        return FeedSubscription::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}

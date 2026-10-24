<?php

namespace App\Mcp\Tools;

use App\Features\Barometer;
use App\Mcp\Text;
use App\Mcp\Tools\Concerns\ActsForGrant;
use App\Models\BarometerReading;
use App\Models\User;
use App\Support\Barometer\ReadingTime;
use App\Support\ConnectClaude\AccessLevel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Pennant\Feature;

/**
 * Barometer, write in (spec §12, §26): log a new reading, or add the second
 * rating to today's latest reading that hasn't got one. Content goes in —
 * the user already told the assistant — but the result never echoes it,
 * and there's no read-back, edit or delete: reviewing and corrections stay
 * in the app.
 *
 * Only listed when the user has Barometer, the connection's Barometer
 * switch is on and its access level allows adding new things.
 */
#[Name('log_reading')]
#[Title('Log Barometer reading')]
#[Description(<<<'TEXT'
    Barometer is the user's private anxiety log (health data). Log a new reading with `rating` (0 calm – 10 most anxious) and, if the user said them, situation, thoughts, sensations, what_i_did, what_i_said and second_rating. With no date or time it's logged as now; give `date` alone for an earlier day when the time isn't known.
    Or, to record how they feel now, give only `second_rating`: it's added to today's latest reading that has no second rating yet.
    Only log what the user asked you to. Don't interpret ratings or diagnose. If they mention risk to themselves, signpost Samaritans (116 123, free, UK, any time) and their GP or therapist. Corrections and the full log are in the Offing app.
    TEXT)]
#[IsDestructive(false)]
class LogReading extends Tool
{
    use ActsForGrant;

    /** A repeat of the same reading this soon is a retry, not a second episode. */
    public const DUPLICATE_SECONDS = 120;

    /** Tool argument => column. */
    private const TEXT_FIELDS = [
        'situation' => 'situation',
        'thoughts' => 'thoughts',
        'sensations' => 'sensations',
        'what_i_did' => 'coping_action',
        'what_i_said' => 'self_talk',
    ];

    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User
            && Feature::for($user)->active(Barometer::class)
            && $this->barometerGranted()
            && $this->allows(AccessLevel::WriteNew);
    }

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $rating = ['integer', 'between:'.BarometerReading::MIN_RATING.','.BarometerReading::MAX_RATING];
        $text = ['nullable', 'string', 'max:'.BarometerReading::MAX_TEXT];

        $data = $request->validate([
            'rating' => ['required_without:second_rating', 'nullable', ...$rating],
            'second_rating' => ['nullable', ...$rating],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            ...array_fill_keys(array_keys(self::TEXT_FIELDS), $text),
        ], [
            'rating.required_without' => 'Give rating (0–10) for a new reading, or only second_rating to add one to today’s latest reading.',
        ]);

        return isset($data['rating'])
            ? $this->log($user, $data)
            : $this->addSecondRating($user, (int) $data['second_rating']);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'rating' => $schema->integer()->min(0)->max(10)->description('How anxious they felt: 0 calm – 10 most anxious. Required for a new reading.'),
            'date' => $schema->string()->description('YYYY-MM-DD, today or earlier. Defaults to today.'),
            'time' => $schema->string()->description('HH:MM in the user’s timezone. Defaults to now when date is also left out; leave it out with a date if the time isn’t known.'),
            'situation' => $schema->string()->max(5000)->description('Where they were, what was happening.'),
            'thoughts' => $schema->string()->max(5000)->description('What went through their mind.'),
            'sensations' => $schema->string()->max(5000)->description('Physical sensations.'),
            'what_i_did' => $schema->string()->max(5000)->description('What they did about it.'),
            'what_i_said' => $schema->string()->max(5000)->description('What they said to themselves.'),
            'second_rating' => $schema->integer()->min(0)->max(10)->description('How anxious they feel afterwards, 0–10. On its own: added to today’s latest reading without one.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function log(User $user, array $data): Response
    {
        $timezone = $user->timezone ?? User::DEFAULT_TIMEZONE;
        $now = now($timezone);
        $date = $data['date'] ?? $now->toDateString();
        // "Now" only when nothing was said about when; a bare date means the time isn't known.
        $time = $data['time'] ?? (isset($data['date']) ? null : $now->format('H:i'));

        if (($problem = ReadingTime::problem($date, $time, $timezone)) !== null) {
            return Response::error($problem[1].' Readings can only be logged for now or earlier.');
        }

        $occurredAt = ReadingTime::occurredAt($date, $time, $timezone);
        $repeat = $this->recentTwin($user, (int) $data['rating'], $date, $time === null);

        if ($repeat !== null) {
            return Response::text('Already logged a moment ago — not logged twice.');
        }

        $attributes = [
            'occurred_at' => $occurredAt,
            'local_date' => $date,
            'tzid' => $timezone,
            'rating_before' => (int) $data['rating'],
            'rating_after' => isset($data['second_rating']) ? (int) $data['second_rating'] : null,
        ];

        foreach (self::TEXT_FIELDS as $argument => $column) {
            $value = trim((string) ($data[$argument] ?? ''));
            $attributes[$column] = $value === '' ? null : $value;
        }

        $reading = $user->barometerReadings()->create($attributes);

        $this->audit($user, 'barometer.reading.created', $reading, ['local_date' => $reading->local_date]);

        $when = Text::date($date).($time !== null ? " at {$time}" : ' (time not recorded)');
        $second = $reading->rating_after !== null ? ", then {$reading->rating_after}/10" : '';
        $details = array_keys(array_filter(array_intersect_key($attributes, array_flip(self::TEXT_FIELDS))));
        $saved = $details === [] ? '' : ' Saved with it: '.implode(', ', array_map(fn (string $column) => strtolower(BarometerReading::COLUMNS[$column]), $details)).'.';

        return Response::text("Logged in Barometer: {$reading->rating_before}/10{$second}, {$when}.{$saved} The user can review or change it in the Offing app.");
    }

    private function addSecondRating(User $user, int $rating): Response
    {
        $today = $user->today()->toDateString();

        $reading = $user->barometerReadings()
            ->where('local_date', $today)
            ->whereNull('rating_after')
            ->latest('occurred_at')
            ->latest('id')
            ->first(['id', 'user_id', 'occurred_at', 'tzid', 'local_date', 'rating_before', 'rating_after']);

        if ($reading === null) {
            return Response::error('There’s no reading from today without a second rating. To log how they feel as a new reading, give rating instead.');
        }

        $reading->update(['rating_after' => $rating]);

        $this->audit($user, 'barometer.reading.second_rating', $reading, ['local_date' => $reading->local_date]);

        $time = $reading->occurred_at?->setTimezone($user->timezone ?? User::DEFAULT_TIMEZONE)->format('H:i');
        $which = $time !== null ? "today’s {$time} reading" : 'today’s reading';

        return Response::text("Added a second rating of {$rating}/10 to {$which} (first rating {$reading->rating_before}/10).");
    }

    /**
     * The same reading from this user in the last couple of minutes: a
     * client retrying the call, not a second episode.
     */
    private function recentTwin(User $user, int $rating, string $date, bool $untimed): ?BarometerReading
    {
        return $user->barometerReadings()
            ->where('rating_before', $rating)
            ->where('local_date', $date)
            ->when($untimed, fn ($query) => $query->whereNull('occurred_at'), fn ($query) => $query->whereNotNull('occurred_at'))
            ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_SECONDS))
            ->first(['id']);
    }
}

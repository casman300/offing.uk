<?php

namespace App\Http\Requests\Barometer;

use App\Models\BarometerReading;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared by create and update (#35). Only the time and the first rating
 * are required — someone mid-panic should be able to log "8, now" in two
 * taps and fill in the rest later.
 *
 * `occurred_at` arrives as a `datetime-local` value (no zone) and means
 * the user's profile timezone, which is also stored as the reading's TZID.
 */
class ReadingRequest extends FormRequest
{
    /** A little slack for clock skew between phone and server. */
    private const FUTURE_SLACK_MINUTES = 5;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rating = ['integer', 'between:'.BarometerReading::MIN_RATING.','.BarometerReading::MAX_RATING];
        $text = ['nullable', 'string', 'max:'.BarometerReading::MAX_TEXT];

        return [
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'rating_before' => ['required', ...$rating],
            'rating_after' => ['nullable', ...$rating],
            ...array_fill_keys(BarometerReading::TEXT_COLUMNS, $text),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating_before.required' => 'Pick a rating from 0 to 10.',
            'occurred_at.date_format' => 'Enter a date and time.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('occurred_at')) {
                    return;
                }

                if ($this->occurredAt()->isAfter(now()->addMinutes(self::FUTURE_SLACK_MINUTES))) {
                    $validator->errors()->add('occurred_at', 'That time is in the future.');
                }
            },
        ];
    }

    public function timezone(): string
    {
        return $this->user()->timezone ?? User::DEFAULT_TIMEZONE;
    }

    public function occurredAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->string('occurred_at')->toString(), $this->timezone())->utc();
    }

    /**
     * @return array<string, mixed>
     */
    public function readingAttributes(): array
    {
        $attributes = [
            'occurred_at' => $this->occurredAt(),
            'tzid' => $this->timezone(),
            'rating_before' => $this->integer('rating_before'),
            'rating_after' => $this->filled('rating_after') ? $this->integer('rating_after') : null,
        ];

        foreach (BarometerReading::TEXT_COLUMNS as $column) {
            $value = $this->string($column)->trim()->toString();
            $attributes[$column] = $value === '' ? null : $value;
        }

        return $attributes;
    }
}

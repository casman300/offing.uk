<?php

namespace App\Http\Requests\Barometer;

use App\Models\BarometerReading;
use App\Models\User;
use App\Support\Barometer\ReadingTime;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared by create and update (#35). Only the day and the first rating
 * are required — someone mid-panic should be able to log "8, now" in two
 * taps and fill in the rest later.
 *
 * The time is optional: a worry remembered from an earlier day can be
 * logged against its day without inventing a time. `date` (Y-m-d) and
 * `time` (H:i) are the user's profile timezone, which is also stored as
 * the reading's TZID.
 */
class ReadingRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rating = ['integer', 'between:'.BarometerReading::MIN_RATING.','.BarometerReading::MAX_RATING];
        $text = ['nullable', 'string', 'max:'.BarometerReading::MAX_TEXT];

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
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
            'date.required' => 'Pick a day.',
            'date.date_format' => 'Pick a day.',
            'time.date_format' => 'Enter a time, or tick “I don’t remember the time”.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['date', 'time'])) {
                    return;
                }

                $problem = ReadingTime::problem($this->localDate(), $this->time(), $this->timezone());

                if ($problem !== null) {
                    $validator->errors()->add(...$problem);
                }
            },
        ];
    }

    public function timezone(): string
    {
        return $this->user()->timezone ?? User::DEFAULT_TIMEZONE;
    }

    public function localDate(): string
    {
        return $this->string('date')->toString();
    }

    public function time(): ?string
    {
        return $this->filled('time') ? $this->string('time')->toString() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function readingAttributes(): array
    {
        $attributes = [
            'occurred_at' => ReadingTime::occurredAt($this->localDate(), $this->time(), $this->timezone()),
            'local_date' => $this->localDate(),
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

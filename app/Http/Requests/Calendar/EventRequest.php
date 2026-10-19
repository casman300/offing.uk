<?php

namespace App\Http\Requests\Calendar;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared by create and update. The form sends local dates and times in the
 * event's own timezone (`tzid`); this turns them into UTC (spec §10).
 *
 * All-day events send dates only, with an inclusive end date; they're
 * stored as midnight UTC to midnight UTC of the day after.
 */
class EventRequest extends FormRequest
{
    private const MIN_YEAR = 1900;

    private const MAX_YEAR = 2200;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $date = ['required', 'date_format:Y-m-d', 'after_or_equal:'.self::MIN_YEAR.'-01-01', 'before_or_equal:'.self::MAX_YEAR.'-12-31'];

        return [
            'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'all_day' => ['required', 'boolean'],
            'tzid' => ['required', 'string', 'timezone:all'],
            'start_date' => $date,
            'end_date' => [...$date, 'after_or_equal:start_date'],
            'start_time' => ['exclude_if:all_day,true', 'required', 'date_format:H:i'],
            'end_time' => ['exclude_if:all_day,true', 'required', 'date_format:H:i'],
            'private' => ['required', 'boolean'],
            'busy' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The end date can’t be before the start.',
            'start_date.after_or_equal' => 'Pick a date after 1900.',
            'start_date.before_or_equal' => 'Pick a date before 2200.',
            'tzid.timezone' => 'Pick a timezone from the list.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->boolean('all_day')) {
                    return;
                }

                [$start, $end] = $this->instants();

                if ($end->lte($start)) {
                    $validator->errors()->add('end_time', 'The end has to be after the start.');
                }
            },
        ];
    }

    /**
     * @return array{title: string, location: ?string, description: ?string, all_day: bool, tzid: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, visibility: string, transparency: string}
     */
    public function eventAttributes(): array
    {
        [$start, $end] = $this->instants();

        return [
            'title' => $this->string('title')->trim()->toString(),
            'location' => $this->filled('location') ? $this->string('location')->trim()->toString() : null,
            'description' => $this->filled('description') ? $this->string('description')->toString() : null,
            'all_day' => $this->boolean('all_day'),
            'tzid' => $this->string('tzid')->toString(),
            'starts_at' => $start,
            'ends_at' => $end,
            'visibility' => $this->boolean('private') ? Event::VISIBILITY_PRIVATE : Event::VISIBILITY_NORMAL,
            'transparency' => $this->boolean('busy') ? Event::TRANSPARENCY_BUSY : Event::TRANSPARENCY_FREE,
        ];
    }

    /**
     * Start and (exclusive) end as UTC instants.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function instants(): array
    {
        if ($this->boolean('all_day')) {
            return [
                CarbonImmutable::createFromFormat('!Y-m-d', $this->string('start_date')->toString(), 'UTC'),
                CarbonImmutable::createFromFormat('!Y-m-d', $this->string('end_date')->toString(), 'UTC')->addDay(),
            ];
        }

        $tz = $this->string('tzid')->toString();

        return [
            CarbonImmutable::createFromFormat('!Y-m-d H:i', $this->input('start_date').' '.$this->input('start_time'), $tz)->utc(),
            CarbonImmutable::createFromFormat('!Y-m-d H:i', $this->input('end_date').' '.$this->input('end_time'), $tz)->utc(),
        ];
    }
}

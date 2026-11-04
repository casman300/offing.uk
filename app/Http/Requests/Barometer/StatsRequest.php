<?php

namespace App\Http\Requests\Barometer;

use App\Support\Barometer\Stats;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The stats page's range (#37): the last 12 weeks by default, a year at
 * most (the same limit as exports). Bad input goes back to the plain stats
 * page — the default range — with the error.
 */
class StatsRequest extends FormRequest
{
    public const DEFAULT_DAYS = 12 * 7;

    protected $redirectRoute = 'barometer.stats';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->from()->isAfter($this->to())) {
                    $validator->errors()->add('to', 'The end date must be on or after the start date.');
                } elseif (! Stats::validRange($this->from(), $this->to())) {
                    $validator->errors()->add('to', 'Look back up to a year at a time.');
                }
            },
        ];
    }

    public function to(): CarbonImmutable
    {
        return $this->filled('to')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->string('to')->toString())
            : $this->user()->today();
    }

    public function from(): CarbonImmutable
    {
        return $this->filled('from')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->string('from')->toString())
            : $this->to()->subDays(self::DEFAULT_DAYS - 1);
    }
}

<?php

namespace App\Http\Requests\Barometer;

use App\Models\BarometerReading;
use App\Support\Barometer\Stats;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Barometer export options (#35).
 *
 * Failures go to the export page explicitly, never "back": the download is
 * a GET, so Laravel remembers the last one as the previous URL, and
 * redirecting back would quietly download the previous export again.
 */
class ExportRequest extends FormRequest
{
    /** Keeps one dompdf render within shared-hosting limits. */
    public const MAX_DAYS = Stats::MAX_DAYS;

    protected $redirectRoute = 'barometer.export';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(['pdf', 'csv'])],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'omit' => ['array'],
            'omit.*' => [Rule::in(BarometerReading::OMITTABLE_COLUMNS)],
            // PDF only (#37): a page of summary numbers before the readings.
            'summary' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty() && $this->from()->diffInDays($this->to()) >= self::MAX_DAYS) {
                    $validator->errors()->add('to', 'Export up to a year at a time.');
                }
            },
        ];
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('from')->toString());
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('to')->toString());
    }

    /** Whether the PDF starts with a summary page (#37). Off by default. */
    public function wantsSummary(): bool
    {
        return $this->string('format')->toString() === 'pdf' && $this->boolean('summary');
    }

    /**
     * @return list<string>
     */
    public function omitted(): array
    {
        return array_values($this->array('omit'));
    }
}

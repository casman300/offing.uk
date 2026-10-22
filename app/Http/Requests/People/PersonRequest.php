<?php

namespace App\Http\Requests\People;

use App\Models\User;
use App\Support\Birthday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared by create and update. Birthday rules (#13):
 * - day and month go together, or neither;
 * - the year is optional, and may stand alone ("born 1950, date unknown");
 * - 1900 ≤ year ≤ this year, and a full date can't be in the future.
 *
 * Age never reaches the server — the form converts it to a year client-side,
 * and only once day+month are known.
 */
class PersonRequest extends FormRequest
{
    public const MESSAGES = [
        'birth_day.required_with' => 'Pick a day to go with the month.',
        'birth_month.required_with' => 'Pick a month to go with the day.',
        'birth_year.between' => 'The year must be between :min and :max.',
    ];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::rulesFor($this->user());
    }

    /**
     * Also used by the AI assistants' `save_person` tool, so a person saved
     * from Claude obeys exactly the same rules as one saved in the app.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function rulesFor(User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'birth_day' => ['nullable', 'integer', 'between:1,31', 'required_with:birth_month'],
            'birth_month' => ['nullable', 'integer', 'between:1,12', 'required_with:birth_day'],
            'birth_year' => ['nullable', 'integer', 'between:'.Birthday::MIN_YEAR.','.$user->today()->year],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::MESSAGES;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => self::checkBirthday($validator, $this->user())];
    }

    /**
     * The date itself: a real day (29 Feb only in leap years), not in the future.
     */
    public static function checkBirthday(Validator $validator, User $user): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $data = $validator->getData();
        $day = filled($data['birth_day'] ?? null) ? (int) $data['birth_day'] : null;
        $month = filled($data['birth_month'] ?? null) ? (int) $data['birth_month'] : null;
        $year = filled($data['birth_year'] ?? null) ? (int) $data['birth_year'] : null;

        if ($day === null || $month === null) {
            return;
        }

        if (! Birthday::isValidDate($day, $month, $year)) {
            $validator->errors()->add('birth_day', $day === 29 && $month === 2
                ? "29 February only exists in leap years — {$year} wasn't one."
                : 'That date doesn\'t exist.');

            return;
        }

        if ($year !== null && ! (new Birthday($day, $month, $year))->observedIn($year)->lte(Birthday::dateOnly($user->today()))) {
            $validator->errors()->add('birth_year', 'That birthday is in the future.');
        }
    }

    /**
     * @return array{name: string, birth_day: ?int, birth_month: ?int, birth_year: ?int, notes: ?string}
     */
    public function personAttributes(): array
    {
        return [
            'name' => $this->string('name')->trim()->toString(),
            'birth_day' => $this->filled('birth_day') ? $this->integer('birth_day') : null,
            'birth_month' => $this->filled('birth_month') ? $this->integer('birth_month') : null,
            'birth_year' => $this->filled('birth_year') ? $this->integer('birth_year') : null,
            'notes' => $this->filled('notes') ? $this->string('notes')->toString() : null,
        ];
    }
}

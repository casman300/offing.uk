<?php

namespace App\Support\Contacts;

use App\Models\ContactImport;
use App\Models\Person;
use App\Models\User;
use App\Support\Birthday;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Review, commit and undo for a parsed vCard import (#14).
 *
 * Matching against existing people is recomputed on every call — people may
 * be added or edited between upload and commit — and the server never trusts
 * dates from the browser: the review form only sends choices (include?,
 * name, add/update, which birthday, keep-or-replace).
 */
final class ContactImporter
{
    public const SECTIONS = ['new', 'match', 'conflict', 'duplicates', 'no_birthday'];

    public function __construct(private readonly User $user) {}

    /**
     * Rows for the review screen, each with its section, match and defaults.
     *
     * @return list<array<string, mixed>>
     */
    public function review(ContactImport $import): array
    {
        $people = $this->peopleByName();

        return array_map(function (array $row) use ($people) {
            $match = $this->matchFor($row, $people);
            $section = $this->sectionFor($row, $match);

            return [
                'key' => $row['key'],
                'name' => $row['name'],
                'birthdays' => $row['birthdays'],
                'has_notes' => $row['notes'] !== null,
                'warnings' => $row['warnings'],
                'cards' => $row['cards'],
                'section' => $section,
                'match' => $match ? [
                    'id' => $match->id,
                    'name' => $match->name,
                    'birth_day' => $match->birth_day,
                    'birth_month' => $match->birth_month,
                    'birth_year' => $match->birth_year,
                    'fills' => $section === 'match' && $row['birthdays'] !== []
                        ? array_keys($this->fillsFor($match, $row['birthdays'][0], false))
                        : [],
                ] : null,
                'default_include' => match ($section) {
                    'new', 'duplicates' => true,
                    // Keeping what's in Offing is the untouched default; ticking means "use the vCard date".
                    'conflict' => false,
                    'match' => $match !== null && $row['birthdays'] !== [] && $this->fillsFor($match, $row['birthdays'][0], false) !== [],
                    'no_birthday' => false,
                },
            ];
        }, $import->rows ?? []);
    }

    /**
     * @param  array<string, array{include?: bool, name?: string, action?: string, birthday?: int, use_vcard?: bool}>  $choices
     * @return array{created: int, updated: int, skipped: int}
     */
    public function commit(ContactImport $import, array $choices, bool $importNotes): array
    {
        return DB::transaction(function () use ($import, $choices, $importNotes) {
            $people = $this->peopleByName();
            $created = [];
            $filled = [];
            $updatedIds = [];
            $skipped = 0;

            foreach ($import->rows ?? [] as $row) {
                $choice = $choices[$row['key']] ?? [];

                if (! ($choice['include'] ?? false)) {
                    $skipped++;

                    continue;
                }

                $birthday = $row['birthdays'][(int) ($choice['birthday'] ?? 0)] ?? $row['birthdays'][0] ?? null;
                $match = $this->matchFor($row, $people);
                $notes = $importNotes ? $row['notes'] : null;

                if ($match !== null && ($choice['action'] ?? 'update') === 'update') {
                    $changes = $birthday ? $this->fillsFor($match, $birthday, (bool) ($choice['use_vcard'] ?? false)) : [];

                    if ($notes !== null && blank($match->notes)) {
                        $changes['notes'] = $notes;
                    }

                    if ($changes === []) {
                        $skipped++;

                        continue;
                    }

                    foreach ($changes as $field => $value) {
                        $filled[] = ['person_id' => $match->id, 'field' => $field, 'from' => $match->{$field}, 'to' => $value];
                    }

                    $match->update($changes);
                    $updatedIds[$match->id] = true;

                    continue;
                }

                $name = trim((string) ($choice['name'] ?? ''));
                $person = $this->user->people()->create([
                    'name' => mb_substr($name !== '' ? $name : $row['name'], 0, 255),
                    'birth_day' => $birthday['day'] ?? null,
                    'birth_month' => $birthday['month'] ?? null,
                    'birth_year' => $birthday['year'] ?? null,
                    'notes' => $notes,
                ]);

                $created[] = ['id' => $person->id, 'updated_at' => $person->fresh()?->updated_at?->toIso8601String()];
            }

            $counts = ['created' => count($created), 'updated' => count($updatedIds), 'skipped' => $skipped];

            $import->update([
                'status' => ContactImport::COMMITTED,
                'committed_at' => now(),
                // The parsed contacts have done their job; keep only what undo needs.
                'rows' => null,
                'result' => ['counts' => $counts, 'created' => $created, 'filled' => $filled, 'notes' => $importNotes],
            ]);

            return $counts;
        });
    }

    /**
     * Undo only what's untouched since: people the import created are
     * soft-deleted unless edited since, and filled fields are reverted only
     * if they still hold the imported value.
     *
     * @return array{removed: int, reverted: int, kept: list<string>}
     */
    public function undo(ContactImport $import): array
    {
        return DB::transaction(function () use ($import) {
            $result = $import->result ?? [];
            $removed = 0;
            $reverted = 0;
            $kept = [];

            foreach ($result['created'] ?? [] as $entry) {
                $person = $this->user->people()->find((int) $entry['id']);

                if ($person === null) {
                    continue; // already deleted
                }

                if ($person->updated_at?->toIso8601String() !== $entry['updated_at']) {
                    $kept[] = $person->name;

                    continue;
                }

                $person->delete();
                $removed++;
            }

            foreach (collect((array) ($result['filled'] ?? []))->groupBy('person_id') as $personId => $fields) {
                $person = $this->user->people()->find((int) $personId);

                if ($person === null) {
                    continue;
                }

                $revert = [];
                foreach ($fields as $field) {
                    if ($person->{$field['field']} === $field['to']) {
                        $revert[$field['field']] = $field['from'];
                    }
                }

                if ($revert === []) {
                    $kept[] = $person->name;

                    continue;
                }

                $person->update($revert);
                $reverted++;
            }

            $outcome = ['removed' => $removed, 'reverted' => $reverted, 'kept' => array_values(array_unique($kept))];

            $import->update([
                'status' => ContactImport::UNDONE,
                'undone_at' => now(),
                'result' => [...$result, 'undo' => $outcome],
            ]);

            return $outcome;
        });
    }

    /**
     * Which fields an update would change. Fills blanks by default; with
     * $useVcard it replaces a conflicting birthday instead.
     *
     * @param  array{day: int, month: int, year: ?int}  $birthday
     * @return array<string, int|null>
     */
    public function fillsFor(Person $person, array $birthday, bool $useVcard): array
    {
        $hasDate = $person->birth_day !== null && $person->birth_month !== null;
        $sameDate = $hasDate && $person->birth_day === $birthday['day'] && $person->birth_month === $birthday['month'];

        // Replacing: a year-less vCard date keeps the year we already had.
        if ($useVcard && $this->conflicts($person, $birthday)) {
            $target = ['birth_day' => $birthday['day'], 'birth_month' => $birthday['month'], 'birth_year' => $birthday['year'] ?? $person->birth_year];
        } elseif (! $hasDate) {
            $target = ['birth_day' => $birthday['day'], 'birth_month' => $birthday['month'], 'birth_year' => $person->birth_year ?? $birthday['year']];
        } elseif ($sameDate && $person->birth_year === null && $birthday['year'] !== null) {
            $target = ['birth_year' => $birthday['year']];
        } else {
            return [];
        }

        // Never leave an impossible combination (29 Feb + a non-leap year).
        $day = $target['birth_day'] ?? $person->birth_day;
        $month = $target['birth_month'] ?? $person->birth_month;
        $year = $target['birth_year'];
        if ($year !== null && ! Birthday::isValidDate($day, $month, $year)) {
            $target['birth_year'] = $person->birth_year !== null && Birthday::isValidDate($day, $month, $person->birth_year) ? $person->birth_year : null;
        }

        return array_filter($target, fn ($value, $field) => $person->{$field} !== $value, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array{day: int, month: int, year: ?int}  $birthday
     */
    private function conflicts(Person $person, array $birthday): bool
    {
        if ($person->birth_day !== null && $person->birth_month !== null) {
            return $person->birth_day !== $birthday['day']
                || $person->birth_month !== $birthday['month']
                || ($person->birth_year !== null && $birthday['year'] !== null && $person->birth_year !== $birthday['year']);
        }

        return $person->birth_year !== null && $birthday['year'] !== null && $person->birth_year !== $birthday['year'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return 'duplicates'|'no_birthday'|'new'|'conflict'|'match'
     */
    private function sectionFor(array $row, ?Person $match): string
    {
        if (count($row['birthdays']) > 1) {
            return 'duplicates';
        }

        if ($row['birthdays'] === []) {
            return 'no_birthday';
        }

        if ($match === null) {
            return 'new';
        }

        return $this->conflicts($match, $row['birthdays'][0]) ? 'conflict' : 'match';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<array-key, Collection<int, Person>>  $people
     */
    private function matchFor(array $row, Collection $people): ?Person
    {
        foreach ($row['match_names'] as $name) {
            if ($found = $people->get($name)?->first()) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return Collection<array-key, Collection<int, Person>>
     */
    private function peopleByName(): Collection
    {
        return $this->user->people()->orderBy('id')->get()->toBase()
            ->groupBy(fn (Person $person) => VcardParser::normalise($person->name));
    }
}

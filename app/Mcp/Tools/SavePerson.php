<?php

namespace App\Mcp\Tools;

use App\Features\BirthdayEngine;
use App\Http\Requests\People\PersonRequest;
use App\Mcp\Text;
use App\Mcp\Tools\Concerns\ActsForGrant;
use App\Models\Person;
use App\Models\User;
use App\Support\ConnectClaude\AccessLevel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Pennant\Feature;

/**
 * Add someone to the user's own address book, or change them (spec §12).
 * Adding needs "Read and add new"; changing needs "Read, add and change".
 * Same validation as the app's form. Deleting stays in the app.
 */
#[Name('save_person')]
#[Title('Save person')]
#[Description("Add a person to the user's own address book, or update one by person_id. Birthday: birth_day + birth_month together, birth_year optional (or a year alone if that's all that's known). On update, only the fields you pass change; pass null to clear one. Check get_briefing or find first so you don't add someone twice.")]
#[IsDestructive]
class SavePerson extends Tool
{
    use ActsForGrant;

    private const FIELDS = ['name', 'birth_day', 'birth_month', 'birth_year', 'notes'];

    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && Feature::for($user)->active(BirthdayEngine::class) && $this->allows(AccessLevel::WriteNew);
    }

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $input = $request->all();
        $person = null;

        if (isset($input['person_id'])) {
            if (! $this->allows(AccessLevel::Edit)) {
                return $this->notAllowed(AccessLevel::Edit, 'change people already in the address book');
            }

            $person = $user->people()->find((int) $input['person_id']);

            if ($person === null) {
                return Response::error("No person {$input['person_id']} in the user's address book.");
            }
        }

        // An update starts from what's there, so leaving a field out keeps it.
        $data = [
            ...($person?->only(self::FIELDS) ?? []),
            ...array_intersect_key($input, array_flip(self::FIELDS)),
        ];

        $validator = Validator::make($data, PersonRequest::rulesFor($user), PersonRequest::MESSAGES);
        $validator->after(fn ($v) => PersonRequest::checkBirthday($v, $user));
        $valid = $validator->validate();

        $attributes = [
            'name' => trim($valid['name']),
            'birth_day' => $valid['birth_day'] ?? null,
            'birth_month' => $valid['birth_month'] ?? null,
            'birth_year' => $valid['birth_year'] ?? null,
            'notes' => filled($valid['notes'] ?? null) ? $valid['notes'] : null,
        ];

        if ($person === null) {
            $twin = $user->people()->where('name', $attributes['name'])->first();
            $person = $user->people()->create($attributes);
            $this->audit($user, 'person.created', $person, ['name' => $person->name]);

            return Response::text("Added {$person->name} (person {$person->id}).".$this->birthdayNote($person, $user)
                .($twin ? " Note: there was already a {$twin->name} (person {$twin->id}) — if that's the same person, tell the user so they can delete one in Offing." : ''));
        }

        $before = $person->only(self::FIELDS);
        $person->update($attributes);
        $changed = array_keys($person->getChanges());
        $changed = array_values(array_intersect(self::FIELDS, $changed));

        if ($changed === []) {
            return Response::text("Nothing to change for {$person->name} (person {$person->id}).");
        }

        $this->audit($user, 'person.updated', $person, ['fields' => $changed, 'before' => array_intersect_key($before, array_flip(array_diff($changed, ['notes'])))]);

        return Response::text("Updated {$person->name} (person {$person->id}): ".implode(', ', str_replace('_', ' ', $changed)).'.'.$this->birthdayNote($person, $user));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person_id' => $schema->integer()->description('Leave out to add someone new; give it to update that person.'),
            'name' => $schema->string()->max(255)->description('Required when adding.'),
            'birth_day' => $schema->integer()->min(1)->max(31)->nullable(),
            'birth_month' => $schema->integer()->min(1)->max(12)->nullable(),
            'birth_year' => $schema->integer()->nullable()->description('Optional. Given an age instead, work the year out only when day and month are known too.'),
            'notes' => $schema->string()->max(5000)->nullable()->description('Free text: gift ideas, how you know them, and so on.'),
        ];
    }

    private function birthdayNote(Person $person, User $user): string
    {
        $birthday = $person->birthday();
        $today = $user->today();

        if (! $birthday->hasDate()) {
            return '';
        }

        $days = $birthday->daysUntil($today);

        return ' Next birthday '.Text::date($birthday->nextOccurrence($today)).($days === 0 ? ', today.' : ', in '.Text::days($days).'.');
    }
}

<?php

use App\Features\BirthdayEngine;
use App\Features\VcardImport;
use App\Models\AuditLog;
use App\Models\ContactImport;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

function importer(): User
{
    $user = User::factory()->create();
    Feature::for($user)->activate([BirthdayEngine::class, VcardImport::class]);

    return $user;
}

function vcf(string $fixture = 'iphone'): UploadedFile
{
    return new UploadedFile(__DIR__."/../../Fixtures/vcards/{$fixture}.vcf", "{$fixture}.vcf", 'text/vcard', null, true);
}

function upload(User $user, string $fixture = 'iphone'): ContactImport
{
    test()->actingAs($user)->post('/people/import', ['file' => vcf($fixture)])->assertRedirect();

    return $user->contactImports()->latest('id')->firstOrFail();
}

/** Review rows keyed by name. */
function reviewRows(User $user, ContactImport $import): array
{
    $rows = [];
    test()->actingAs($user)->get("/people/import/{$import->id}")
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$rows) {
            $page->component('people/import/review');
            $rows = collect($page->toArray()['props']['rows'])->keyBy('name')->all();
        });

    return $rows;
}

/** The choices the review screen sends by default. */
function defaultChoices(array $rows): array
{
    return collect($rows)->mapWithKeys(fn ($row) => [$row['key'] => [
        'include' => $row['default_include'],
        'action' => $row['match'] ? 'update' : 'add',
        'birthday' => 0,
        'use_vcard' => $row['section'] === 'conflict' && $row['default_include'],
    ]])->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'Europe/London'));
});

describe('access', function () {
    it('needs both flags', function () {
        $peopleOnly = User::factory()->create();
        Feature::for($peopleOnly)->activate(BirthdayEngine::class);
        $importOnly = User::factory()->create();
        Feature::for($importOnly)->activate(VcardImport::class);

        $this->actingAs($peopleOnly)->get('/people/import')->assertNotFound();
        $this->actingAs($importOnly)->get('/people/import')->assertNotFound();
        $this->actingAs($peopleOnly)->post('/people/import', ['file' => vcf()])->assertNotFound();
        $this->actingAs(importer())->get('/people/import')->assertOk();
    });

    it('shares the flag for the Import button', function () {
        $this->actingAs(importer())->get('/people')
            ->assertInertia(fn (Assert $page) => $page->where('features.vcardImport', true));
    });

    it('404s on someone else’s import', function () {
        $import = upload(importer());
        $me = importer();

        $this->actingAs($me)->get("/people/import/{$import->id}")->assertNotFound();
        $this->actingAs($me)->post("/people/import/{$import->id}/commit", ['rows' => []])->assertNotFound();
        $this->actingAs($me)->delete("/people/import/{$import->id}")->assertNotFound();

        expect($import->fresh()->status)->toBe('pending');
    });
});

describe('upload', function () {
    it('stores only names, birthdays and notes', function () {
        $import = upload(importer());

        expect($import->status)->toBe('pending')
            ->and($import->filename)->toBe('iphone.vcf')
            ->and($import->getRawOriginal('rows'))->not->toContain('7700')->not->toContain('sue@example.com')->not->toContain('High St');
    });

    it('rejects bad files with a readable message', function (string $fixture, string $message) {
        $this->actingAs(importer())->post('/people/import', ['file' => vcf($fixture)])
            ->assertSessionHasErrors(['file' => $message]);
    })->with([
        ['empty', 'We couldn’t find any contacts in that file.'],
        ['not-a-vcard', 'That file doesn’t look like a contacts (.vcf) export.'],
    ]);

    it('rejects non-.vcf and oversized files', function () {
        $user = importer();

        $this->actingAs($user)->post('/people/import', ['file' => UploadedFile::fake()->create('contacts.csv', 10)])
            ->assertSessionHasErrors('file');
        $this->actingAs($user)->post('/people/import', ['file' => UploadedFile::fake()->create('contacts.vcf', 10241)])
            ->assertSessionHasErrors('file');

        expect($user->contactImports()->count())->toBe(0);
    });
});

describe('review', function () {
    it('sorts rows into sections against existing people', function () {
        $user = importer();
        Person::factory()->for($user, 'owner')->create(['name' => 'Susan Smith', 'birth_day' => null, 'birth_month' => null, 'birth_year' => null]);
        Person::factory()->for($user, 'owner')->birthday(8, 6, 1970)->create(['name' => 'TOM jones']);
        $rows = reviewRows($user, upload($user));

        expect($rows['Auntie Sue']['section'])->toBe('match')
            ->and($rows['Auntie Sue']['match']['name'])->toBe('Susan Smith')
            ->and($rows['Auntie Sue']['match']['fills'])->toBe(['birth_day', 'birth_month', 'birth_year'])
            ->and($rows['Auntie Sue']['default_include'])->toBeTrue()
            ->and($rows['Tom Jones']['section'])->toBe('conflict')
            // Ticking a conflict means "use the vCard date"; keeping ours is the untouched default.
            ->and($rows['Tom Jones']['default_include'])->toBeFalse()
            ->and($rows['Lily Leap']['section'])->toBe('new')
            ->and($rows['Lily Leap']['default_include'])->toBeTrue()
            ->and($rows['Tess Twin']['section'])->toBe('duplicates')
            ->and($rows['The Plumber']['section'])->toBe('no_birthday')
            ->and($rows['The Plumber']['default_include'])->toBeFalse()
            ->and($rows['Olive Old']['warnings'])->toBe(['year_ignored'])
            ->and($rows['Auntie Sue']['has_notes'])->toBeTrue();
    });

    it('does not pre-tick a match with nothing to add', function () {
        $user = importer();
        Person::factory()->for($user, 'owner')->birthday(29, 2, 2000)->create(['name' => 'Lily Leap']);

        $row = reviewRows($user, upload($user))['Lily Leap'];

        expect($row['section'])->toBe('match')
            ->and($row['match']['fills'])->toBe([])
            ->and($row['default_include'])->toBeFalse();
    });
});

describe('commit', function () {
    it('creates, fills blanks, keeps conflicts, and skips unticked rows by default', function () {
        $user = importer();
        $sue = Person::factory()->for($user, 'owner')->create(['name' => 'Susan Smith', 'birth_day' => null, 'birth_month' => null, 'birth_year' => null, 'notes' => null]);
        $tom = Person::factory()->for($user, 'owner')->birthday(8, 6, 1970)->create(['name' => 'Tom Jones']);
        $import = upload($user);
        $rows = reviewRows($user, $import);

        $this->actingAs($user)->post("/people/import/{$import->id}/commit", ['rows' => defaultChoices($rows), 'import_notes' => false])
            ->assertRedirect("/people/import/{$import->id}");

        expect($sue->fresh()->only(['name', 'birth_day', 'birth_month', 'birth_year', 'notes']))
            ->toBe(['name' => 'Susan Smith', 'birth_day' => 2, 'birth_month' => 10, 'birth_year' => 1983, 'notes' => null])
            // Conflict: kept by default.
            ->and($tom->fresh()->only(['birth_day', 'birth_month', 'birth_year']))->toBe(['birth_day' => 8, 'birth_month' => 6, 'birth_year' => 1970])
            ->and($user->people()->where('name', 'The Plumber')->exists())->toBeFalse()
            ->and($user->people()->where('name', 'Lily Leap')->first()->birth_year)->toBe(2000)
            ->and($user->people()->where('name', 'Tess Twin')->first()->only(['birth_day', 'birth_month', 'birth_year']))
            ->toBe(['birth_day' => 3, 'birth_month' => 3, 'birth_year' => 1991]);

        $import->refresh();
        expect($import->status)->toBe('committed')
            ->and($import->rows)->toBeNull()
            ->and($import->result['counts'])->toBe(['created' => 5, 'updated' => 1, 'skipped' => 3]);

        $log = AuditLog::where('action', 'contacts.import_committed')->sole();
        expect($log->actor_id)->toBe($user->id)->and($log->meta)->toBe(['created' => 5, 'updated' => 1, 'skipped' => 3]);
    });

    it('honours per-row choices: rename, add-as-new, use the vCard date, pick a duplicate', function () {
        $user = importer();
        $tom = Person::factory()->for($user, 'owner')->birthday(8, 6, 1970)->create(['name' => 'Tom Jones']);
        Person::factory()->for($user, 'owner')->birthday(29, 2, 2000)->create(['name' => 'Lily Leap']);
        $import = upload($user);
        $rows = reviewRows($user, $import);
        $choices = defaultChoices($rows);

        $choices[$rows['Tom Jones']['key']] = ['include' => true, 'action' => 'update', 'use_vcard' => true];
        $choices[$rows['Lily Leap']['key']] = ['include' => true, 'action' => 'add', 'name' => 'Lily (work)'];
        $choices[$rows['Tess Twin']['key']]['birthday'] = 1;
        $choices[$rows['Zoë Müller']['key']]['name'] = 'Zoë';

        $this->actingAs($user)->post("/people/import/{$import->id}/commit", ['rows' => $choices]);

        expect($tom->fresh()->only(['birth_day', 'birth_month', 'birth_year']))
            // vCard date is year-less: the year we had is kept.
            ->toBe(['birth_day' => 7, 'birth_month' => 6, 'birth_year' => 1970])
            ->and($user->people()->where('name', 'Lily (work)')->exists())->toBeTrue()
            ->and($user->people()->where('name', 'Lily Leap')->count())->toBe(1)
            ->and($user->people()->where('name', 'Tess Twin')->first()->birth_year)->toBe(1992)
            ->and($user->people()->where('name', 'Zoë')->exists())->toBeTrue();
    });

    it('imports notes only when asked, and only into empty notes', function () {
        $user = importer();
        $import = upload($user);
        $rows = reviewRows($user, $import);

        $this->actingAs($user)->post("/people/import/{$import->id}/commit", ['rows' => defaultChoices($rows), 'import_notes' => true]);

        expect($user->people()->where('name', 'Auntie Sue')->first()->notes)->toBe('Gate code 1234');

        $other = importer();
        Person::factory()->for($other, 'owner')->create(['name' => 'Auntie Sue', 'notes' => 'Mine', 'birth_day' => null, 'birth_month' => null, 'birth_year' => null]);
        $import = upload($other);
        $this->actingAs($other)->post("/people/import/{$import->id}/commit", ['rows' => defaultChoices(reviewRows($other, $import)), 'import_notes' => true]);

        expect($other->people()->where('name', 'Auntie Sue')->first()->notes)->toBe('Mine');
    });

    it('cannot be committed twice', function () {
        $user = importer();
        $import = upload($user);
        $choices = defaultChoices(reviewRows($user, $import));

        $this->actingAs($user)->post("/people/import/{$import->id}/commit", ['rows' => $choices]);
        $this->actingAs($user)->post("/people/import/{$import->id}/commit", ['rows' => $choices])->assertNotFound();

        expect($user->people()->where('name', 'Lily Leap')->count())->toBe(1);
    });

    it('can be cancelled before committing', function () {
        $user = importer();
        $import = upload($user);

        $this->actingAs($user)->delete("/people/import/{$import->id}")->assertRedirect('/people');

        expect(ContactImport::find($import->id))->toBeNull();
    });
});

describe('undo', function () {
    it('removes untouched people, reverts untouched fills, and keeps your edits', function () {
        $user = importer();
        $sue = Person::factory()->for($user, 'owner')->create(['name' => 'Susan Smith', 'birth_day' => null, 'birth_month' => null, 'birth_year' => null]);
        $import = upload($user);
        $this->actingAs($user)->post("/people/import/{$import->id}/commit", ['rows' => defaultChoices(reviewRows($user, $import))]);

        // Edit one created person afterwards; that edit must survive undo.
        $this->travel(5)->minutes();
        $lily = $user->people()->where('name', 'Lily Leap')->first();
        $lily->update(['notes' => 'Edited after import']);

        $this->actingAs($user)->post("/people/import/{$import->id}/undo")->assertRedirect("/people/import/{$import->id}");

        expect($lily->fresh()->trashed())->toBeFalse()
            ->and($user->people()->where('name', 'Zoë Müller')->exists())->toBeFalse()
            ->and($user->people()->onlyTrashed()->where('name', 'Zoë Müller')->exists())->toBeTrue()
            ->and($sue->fresh()->only(['birth_day', 'birth_month', 'birth_year']))->toBe(['birth_day' => null, 'birth_month' => null, 'birth_year' => null]);

        $import->refresh();
        expect($import->status)->toBe('undone')
            ->and($import->result['undo']['kept'])->toBe(['Lily Leap'])
            ->and($import->result['undo']['removed'])->toBe(5)
            ->and($import->result['undo']['reverted'])->toBe(1)
            ->and(AuditLog::where('action', 'contacts.import_undone')->exists())->toBeTrue();
    });

    it('does not revert a field you changed since', function () {
        $user = importer();
        $sue = Person::factory()->for($user, 'owner')->create(['name' => 'Susan Smith', 'birth_day' => null, 'birth_month' => null, 'birth_year' => null]);
        $import = upload($user);
        $this->actingAs($user)->post("/people/import/{$import->id}/commit", ['rows' => defaultChoices(reviewRows($user, $import))]);

        $sue->fresh()->update(['birth_year' => 1984]);
        $this->actingAs($user)->post("/people/import/{$import->id}/undo");

        // Day and month still held the imported values, so they revert; the year you fixed stays.
        expect($sue->fresh()->only(['birth_day', 'birth_month', 'birth_year']))->toBe(['birth_day' => null, 'birth_month' => null, 'birth_year' => 1984]);
    });

    it('can only undo once, and only a committed import', function () {
        $user = importer();
        $import = upload($user);

        $this->actingAs($user)->post("/people/import/{$import->id}/undo")->assertNotFound();
        $this->actingAs($user)->post("/people/import/{$import->id}/commit", ['rows' => defaultChoices(reviewRows($user, $import))]);
        $this->actingAs($user)->post("/people/import/{$import->id}/undo")->assertRedirect();
        $this->actingAs($user)->post("/people/import/{$import->id}/undo")->assertNotFound();
    });
});

describe('pruning', function () {
    it('drops pending imports after a day, keeps committed ones', function () {
        $user = importer();
        $old = upload($user);
        $done = upload($user);
        $this->actingAs($user)->post("/people/import/{$done->id}/commit", ['rows' => []]);

        $this->travel(25)->hours();
        $fresh = upload($user);
        $this->artisan('model:prune', ['--model' => [ContactImport::class]])->assertSuccessful();

        expect(ContactImport::find($old->id))->toBeNull()
            ->and(ContactImport::find($done->id))->not->toBeNull()
            ->and(ContactImport::find($fresh->id))->not->toBeNull();
    });
});

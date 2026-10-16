<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ContactImport;
use App\Support\Contacts\ContactImporter;
use App\Support\Contacts\VcardImportException;
use App\Support\Contacts\VcardParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * vCard import (#14): upload → review → commit → (optional) undo.
 * Imports are looked up through the signed-in user's own relation, so
 * anyone else's is a 404.
 */
class ContactImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('people/import/create', [
            'maxCards' => VcardParser::MAX_CARDS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:vcf,vcard'],
        ], [
            'file.max' => 'That file is over 10 MB — try exporting without photos, or a smaller list.',
            'file.extensions' => 'Choose a contacts export ending in .vcf.',
        ]);

        $file = $request->file('file');
        $stream = $file instanceof UploadedFile ? fopen($file->getRealPath(), 'r') : false;

        if ($stream === false) {
            throw ValidationException::withMessages(['file' => 'We couldn’t read that file — try uploading it again.']);
        }

        try {
            $rows = (new VcardParser($request->user()->today()))->parse($stream);
        } catch (VcardImportException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        } finally {
            fclose($stream);
        }

        $import = $request->user()->contactImports()->create([
            'status' => ContactImport::PENDING,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'rows' => $rows,
        ]);

        return to_route('people.import.show', $import);
    }

    public function show(Request $request, int $import): Response
    {
        $user = $request->user();
        $model = $user->contactImports()->findOrFail($import);

        if ($model->status === ContactImport::PENDING) {
            return Inertia::render('people/import/review', [
                'import' => ['id' => $model->id, 'filename' => $model->filename],
                'rows' => (new ContactImporter($user))->review($model),
            ]);
        }

        return Inertia::render('people/import/result', [
            'import' => [
                'id' => $model->id,
                'filename' => $model->filename,
                'status' => $model->status,
                'committed_at' => $model->committed_at?->toIso8601String(),
                'counts' => $model->result['counts'] ?? null,
                'undo' => $model->result['undo'] ?? null,
            ],
        ]);
    }

    public function commit(Request $request, int $import): RedirectResponse
    {
        $model = $request->user()->contactImports()->where('status', ContactImport::PENDING)->findOrFail($import);

        $data = $request->validate([
            'rows' => ['present', 'array'],
            'rows.*.include' => ['boolean'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.action' => ['nullable', 'in:add,update'],
            'rows.*.birthday' => ['nullable', 'integer', 'min:0', 'max:100'],
            'rows.*.use_vcard' => ['boolean'],
            'import_notes' => ['boolean'],
        ]);

        $counts = (new ContactImporter($request->user()))->commit($model, $data['rows'], (bool) ($data['import_notes'] ?? false));

        AuditLog::record($request->user(), 'contacts.import_committed', $model, $counts, $request->ip());

        return to_route('people.import.show', $model);
    }

    public function undo(Request $request, int $import): RedirectResponse
    {
        $model = $request->user()->contactImports()->where('status', ContactImport::COMMITTED)->findOrFail($import);

        $outcome = (new ContactImporter($request->user()))->undo($model);

        AuditLog::record($request->user(), 'contacts.import_undone', $model, [
            'removed' => $outcome['removed'],
            'reverted' => $outcome['reverted'],
            'kept' => count($outcome['kept']),
        ], $request->ip());

        return to_route('people.import.show', $model);
    }

    public function destroy(Request $request, int $import): RedirectResponse
    {
        $request->user()->contactImports()->where('status', ContactImport::PENDING)->findOrFail($import)->delete();

        return to_route('people.index');
    }
}

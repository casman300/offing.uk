<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Mail\PasskeyAddedMail;
use App\Models\AuditLog;
use App\Models\Passkey;
use App\Support\Passkeys\WebAuthn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Settings → Sign-in & devices → Passkeys (#9): add, rename and remove
 * passkeys (DevicesController lists them). Every change
 * is audited, and adding one also emails the account, so a stolen session
 * can't quietly plant a permanent way in.
 */
class PasskeyController extends Controller
{
    /**
     * Friendly default names for well-known passkey providers, by AAGUID.
     * Some browsers send all-zero AAGUIDs, so the client's device hint and
     * then "Passkey" are the fallbacks.
     */
    private const PROVIDERS = [
        'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'iCloud Keychain',
        'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Google Password Manager',
        'adce0002-35bc-c60a-648b-0b25f1f05503' => 'Chrome on Mac',
        '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
        'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
        'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Bitwarden',
    ];

    public function options(Request $request, WebAuthn $webAuthn): JsonResponse
    {
        return JsonResponse::fromJsonString($webAuthn->registrationOptions($request->user()));
    }

    public function store(Request $request, WebAuthn $webAuthn): RedirectResponse
    {
        $validated = $request->validate([
            'credential' => ['required', 'string', 'max:16384'],
            'device' => ['nullable', 'string', 'max:50'],
            'from_setup' => ['boolean'],
        ]);

        $record = $webAuthn->verifyRegistration($validated['credential']);
        $credentialId = $record === null ? null : WebAuthn::encodeId($record->publicKeyCredentialId);

        if ($record === null || strlen($credentialId) > 255 || Passkey::where('credential_id', $credentialId)->exists()) {
            return back()->withErrors(['passkey' => "That passkey couldn't be added. Please try again."]);
        }

        $user = $request->user();

        $passkey = new Passkey(['name' => self::PROVIDERS[$record->aaguid->toRfc4122()] ?? ($validated['device'] ?? null) ?: 'Passkey']);
        $passkey->user_id = $user->id;
        $passkey->credential_id = $credentialId;
        $passkey->credential = $webAuthn->serializeRecord($record);
        $passkey->save();

        AuditLog::record($user, 'auth.passkey.registered', $passkey, ['name' => $passkey->name, 'aaguid' => $record->aaguid->toRfc4122()], $request->ip());
        Mail::to($user->email)->queue(new PasskeyAddedMail($user, $passkey));

        if ($request->boolean('from_setup')) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        return to_route('devices.index')->with('status', 'passkey-added');
    }

    public function update(Request $request, int $passkey): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:50']]);

        $model = $request->user()->passkeys()->findOrFail($passkey);
        $from = $model->name;
        $model->update(['name' => trim($validated['name'])]);

        AuditLog::record($request->user(), 'auth.passkey.renamed', $model, ['from' => $from, 'to' => $model->name], $request->ip());

        return to_route('devices.index');
    }

    public function destroy(Request $request, int $passkey): RedirectResponse
    {
        $model = $request->user()->passkeys()->findOrFail($passkey);
        $model->delete();

        AuditLog::record($request->user(), 'auth.passkey.removed', $model, ['name' => $model->name], $request->ip());

        return to_route('devices.index')->with('status', 'passkey-removed');
    }
}

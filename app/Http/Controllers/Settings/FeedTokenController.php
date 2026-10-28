<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FeedToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Connected apps → Calendar feeds (#15): mint and revoke the
 * user's own subscribe URLs (ConnectedAppsController lists them). Not behind the birthday-engine flag; see FeedController.
 *
 * A new URL goes back exactly once, as a flash. It can't be shown again,
 * because only its hash is stored.
 */
class FeedTokenController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        $user = $request->user();

        if ($user->feedTokens()->active()->count() >= FeedToken::MAX_ACTIVE) {
            throw ValidationException::withMessages([
                'name' => 'You can have up to '.FeedToken::MAX_ACTIVE.' feed links. Revoke one first.',
            ]);
        }

        [$token, $plaintext] = FeedToken::mint($user, trim($validated['name']));

        AuditLog::record($user, 'feed_token.created', $token, ['name' => $token->name, 'scope' => $token->scope], $request->ip());

        return to_route('connected-apps.index')->with('feedUrl', [
            'id' => $token->id,
            'url' => route('feed.show', $plaintext),
        ]);
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $model = $request->user()->feedTokens()->active()->findOrFail($token);
        $model->forceFill(['revoked_at' => now()])->save();

        AuditLog::record($request->user(), 'feed_token.revoked', $model, ['name' => $model->name], $request->ip());

        return to_route('connected-apps.index')->with('status', 'feed-revoked');
    }
}

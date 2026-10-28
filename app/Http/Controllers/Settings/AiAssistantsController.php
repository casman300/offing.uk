<?php

namespace App\Http\Controllers\Settings;

use App\Features\Barometer;
use App\Http\Controllers\Controller;
use App\Models\AiGrant;
use App\Support\ConnectClaude\AccessLevel;
use App\Support\ConnectClaude\Connections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

/**
 * Settings → Connected apps → AI assistants (spec §12): change or revoke a
 * connected MCP client's access (ConnectedAppsController lists them, with
 * how to connect Claude, ChatGPT or any other MCP client). Tested with Claude; nothing here is Claude-specific.
 */
class AiAssistantsController extends Controller
{
    public function update(Request $request, int $grant): RedirectResponse
    {
        $validated = $request->validate([
            'level' => ['required', Rule::enum(AccessLevel::class)],
            'include_private' => ['required', 'boolean'],
            'barometer' => ['boolean'],
        ]);

        $model = AiGrant::where('user_id', $request->user()->id)->findOrFail($grant);

        Connections::update(
            $model,
            AccessLevel::from($validated['level']),
            $request->boolean('include_private'),
            $request->ip(),
            barometer: $request->boolean('barometer') && Feature::for($request->user())->active(Barometer::class),
        );

        return to_route('connected-apps.index')->with('status', 'connection-updated');
    }

    public function destroy(Request $request, int $grant): RedirectResponse
    {
        $model = AiGrant::where('user_id', $request->user()->id)->findOrFail($grant);

        Connections::revoke($model, $request->ip());

        return to_route('connected-apps.index')->with('status', 'connection-revoked');
    }
}

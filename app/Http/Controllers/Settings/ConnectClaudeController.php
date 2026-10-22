<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AiGrant;
use App\Support\ConnectClaude\AccessLevel;
use App\Support\ConnectClaude\Connections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Connect Claude (spec §12): how to connect, and the list of
 * connected apps with their access, which can be changed or revoked here.
 */
class ConnectClaudeController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/connect-claude', [
            'mcpUrl' => url('/mcp'),
            'levels' => collect(AccessLevel::cases())->map(fn (AccessLevel $level) => [
                'value' => $level->value,
                'label' => $level->label(),
            ])->values(),
            'connections' => AiGrant::with('client')->where('user_id', $request->user()->id)->latest('id')->get()
                ->map(fn (AiGrant $grant) => [
                    'id' => $grant->id,
                    'name' => $grant->client?->name ?? 'Unknown app',
                    'host' => ($uri = $grant->client?->redirect_uris[0] ?? null) ? parse_url($uri, PHP_URL_HOST) : null,
                    'level' => $grant->level->value,
                    'include_private' => $grant->include_private,
                    'created_at' => $grant->created_at?->toIso8601String(),
                    'last_used_at' => $grant->last_used_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    public function update(Request $request, int $grant): RedirectResponse
    {
        $validated = $request->validate([
            'level' => ['required', Rule::enum(AccessLevel::class)],
            'include_private' => ['required', 'boolean'],
        ]);

        $model = AiGrant::where('user_id', $request->user()->id)->findOrFail($grant);

        Connections::update($model, AccessLevel::from($validated['level']), $request->boolean('include_private'), $request->ip());

        return to_route('connect-claude.index')->with('status', 'connection-updated');
    }

    public function destroy(Request $request, int $grant): RedirectResponse
    {
        $model = AiGrant::where('user_id', $request->user()->id)->findOrFail($grant);

        Connections::revoke($model, $request->ip());

        return to_route('connect-claude.index')->with('status', 'connection-revoked');
    }
}

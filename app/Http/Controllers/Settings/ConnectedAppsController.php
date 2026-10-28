<?php

namespace App\Http\Controllers\Settings;

use App\Features\Barometer;
use App\Features\ConnectClaude;
use App\Http\Controllers\Controller;
use App\Models\AiGrant;
use App\Models\FeedToken;
use App\Support\ConnectClaude\AccessLevel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

/**
 * Settings → Connected apps: everything outside Offing that reads your
 * calendar — subscribe links for calendar apps (#15) and, once Connect
 * Claude is on for you, AI assistants (spec §12). Changes go through
 * FeedTokenController and AiAssistantsController.
 */
class ConnectedAppsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $props = [
            'maxActive' => FeedToken::MAX_ACTIVE,
            'tokens' => $user->feedTokens()->active()->latest('id')->get()
                ->map(fn (FeedToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'last_user_agent' => $token->last_user_agent,
                ])
                ->values(),
            'assistants' => null,
        ];

        if (Feature::for($user)->active(ConnectClaude::class)) {
            $props['assistants'] = [
                'mcpUrl' => url('/mcp'),
                'levels' => collect(AccessLevel::cases())->map(fn (AccessLevel $level) => [
                    'value' => $level->value,
                    'label' => $level->label(),
                ])->values(),
                'barometerAvailable' => Feature::for($user)->active(Barometer::class),
                'connections' => AiGrant::with('client')->where('user_id', $user->id)->latest('id')->get()
                    ->map(fn (AiGrant $grant) => [
                        'id' => $grant->id,
                        'name' => $grant->client->name ?? 'Unknown app',
                        'host' => ($uri = $grant->client->redirect_uris[0] ?? null) ? parse_url($uri, PHP_URL_HOST) : null,
                        'level' => $grant->level->value,
                        'include_private' => $grant->include_private,
                        'barometer' => $grant->barometer,
                        'created_at' => $grant->created_at?->toIso8601String(),
                        'last_used_at' => $grant->last_used_at?->toIso8601String(),
                    ])
                    ->values(),
            ];
        }

        return Inertia::render('settings/connected-apps', $props);
    }
}

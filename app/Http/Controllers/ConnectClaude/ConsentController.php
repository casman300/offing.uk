<?php

namespace App\Http\Controllers\ConnectClaude;

use App\Features\ConnectClaude;
use App\Http\Controllers\Controller;
use App\Models\AiGrant;
use App\Models\User;
use App\Support\ConnectClaude\AccessLevel;
use App\Support\ConnectClaude\Connections;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use Laravel\Passport\Passport;
use Laravel\Pennant\Feature;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The consent screen for Claude, ChatGPT or any MCP client (spec §12):
 * access level (defaulting to read + add new) + "include my private events". Passport validates the OAuth request and keeps it in the
 * session; we record the user's choices as an AiGrant, then hand back to
 * Passport to issue the code.
 */
class ConsentController extends Controller
{
    private const SESSION_CLIENT = 'connectClaude.client';

    /**
     * Passport's authorization view (registered in AppServiceProvider).
     *
     * @param  array{client: Client, user: User, authToken: string, request: Request}  $parameters
     */
    public function show(array $parameters): InertiaResponse
    {
        $client = $parameters['client'];
        $user = $parameters['user'];

        if (! Feature::for($user)->active(ConnectClaude::class)) {
            return Inertia::render('connect-claude/unavailable');
        }

        $parameters['request']->session()->put(self::SESSION_CLIENT, $client->getKey());

        $existing = AiGrant::where('user_id', $user->id)->where('oauth_client_id', $client->getKey())->first();

        return Inertia::render('connect-claude/consent', [
            'client' => [
                'name' => $client->name,
                'host' => self::redirectHost($client),
            ],
            'authToken' => $parameters['authToken'],
            'csrfToken' => csrf_token(),
            'levels' => collect(AccessLevel::cases())->map(fn (AccessLevel $level) => [
                'value' => $level->value,
                'label' => $level->label(),
                'description' => $level->description(),
            ])->values(),
            'level' => ($existing?->level ?? AccessLevel::WriteNew)->value,
            'includePrivate' => (bool) $existing?->include_private,
        ]);
    }

    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        $validated = $request->validate([
            'auth_token' => ['required', 'string'],
            'level' => ['required', Rule::enum(AccessLevel::class)],
            'include_private' => ['boolean'],
        ]);

        $client = $this->pendingClient($request);
        $user = $request->user();

        abort_unless(Feature::for($user)->active(ConnectClaude::class), 404);

        Connections::approve($user, $client, AccessLevel::from($validated['level']), $request->boolean('include_private'), $request->ip());

        return app(ApproveAuthorizationController::class)->approve($request, $psrResponse);
    }

    public function deny(Request $request, ResponseInterface $psrResponse): Response
    {
        $request->validate(['auth_token' => ['required', 'string']]);

        Connections::denied($request->user(), $this->pendingClient($request), $request->ip());

        return app(DenyAuthorizationController::class)->deny($request, $psrResponse);
    }

    /**
     * The client from the request shown on the consent screen — checked
     * against the same one-time token Passport checks, before we record
     * anything.
     */
    private function pendingClient(Request $request): Client
    {
        $session = $request->session();

        abort_unless(
            $session->has('authRequest') && hash_equals((string) $session->get('authToken'), (string) $request->input('auth_token')),
            419,
            'This sign-in request has expired. Start connecting again from Claude.',
        );

        return Passport::client()->newQuery()->findOrFail($session->pull(self::SESSION_CLIENT));
    }

    private static function redirectHost(Client $client): ?string
    {
        $uri = $client->redirect_uris[0] ?? null;

        return $uri ? parse_url($uri, PHP_URL_HOST) : null;
    }
}

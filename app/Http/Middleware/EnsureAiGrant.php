<?php

namespace App\Http\Middleware;

use App\Features\ConnectClaude;
use App\Models\AiGrant;
use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:api on /mcp (spec §12). A valid token isn't enough: the
 * user must still have the feature and a grant for this client — revoking
 * from Settings deletes the grant, and this is where that bites. The grant
 * is bound in the container for the tools to read.
 */
class EnsureAiGrant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        // Only a real OAuth token names a client; a TransientToken (cookie auth) doesn't.
        $clientId = $token instanceof AccessToken ? $token->oauth_client_id : null;

        if ($user === null || $clientId === null || ! Feature::for($user)->active(ConnectClaude::class)) {
            return response()->json(['error' => 'access_denied'], 403);
        }

        $grant = AiGrant::where('user_id', $user->id)->where('oauth_client_id', $clientId)->first();

        if ($grant === null) {
            // 401 (with WWW-Authenticate, added by laravel/mcp) sends the
            // client back through consent rather than failing for good.
            return response()->json(['error' => 'invalid_token'], 401);
        }

        app()->instance(AiGrant::class, $grant);
        $grant->touchUsed();

        if ($request->json('method') === 'tools/call') {
            // Which tool, never its arguments or answer (spec §12: audited, no content).
            AuditLog::record($user, 'connect_claude.tool', $grant, [
                'tool' => (string) $request->json('params.name'),
            ], $request->ip());
        }

        return $next($request);
    }
}

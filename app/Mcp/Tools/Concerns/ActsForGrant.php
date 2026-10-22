<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\AiGrant;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ConnectClaude\AccessLevel;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Response;

/**
 * The connection's grant (bound per request by EnsureAiGrant): what it may
 * do, and who to credit in the audit log. No grant (e.g. a unit test) reads
 * as "Read only".
 */
trait ActsForGrant
{
    protected function grant(): ?AiGrant
    {
        return app()->bound(AiGrant::class) ? app(AiGrant::class) : null;
    }

    protected function level(): AccessLevel
    {
        return $this->grant()->level ?? AccessLevel::Read;
    }

    protected function allows(AccessLevel $needed): bool
    {
        return $this->level()->allows($needed);
    }

    protected function notAllowed(AccessLevel $needed, string $what): Response
    {
        return Response::error("This connection can't {$what}: its access is “{$this->level()->label()}” and that needs “{$needed->label()}”. "
            .'The user can change it in Offing under Settings → AI assistants; it applies straight away.');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function audit(User $user, string $action, ?Model $subject, array $meta = []): void
    {
        AuditLog::record($user, $action, $subject, [...$meta, 'via' => 'ai', 'oauth_client_id' => $this->grant()?->oauth_client_id], request()->ip());
    }
}

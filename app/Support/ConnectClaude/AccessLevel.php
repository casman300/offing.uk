<?php

namespace App\Support\ConnectClaude;

/**
 * What a connected Claude may do (spec §12). Stored on the AiGrant, not as
 * OAuth scopes: laravel/mcp advertises the single `mcp:use` scope, and this
 * way a change applies straight away without reconnecting.
 */
enum AccessLevel: string
{
    case Read = 'read';
    case WriteNew = 'write-new';
    case Edit = 'edit';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read only',
            self::WriteNew => 'Read and add new',
            self::Edit => 'Read, add and change',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Read => 'Claude can look things up but not change anything.',
            self::WriteNew => 'Claude can also add new things, like a person or a chore you’ve done.',
            self::Edit => 'Claude can also change or remove things that are already there.',
        };
    }

    public function allows(self $needed): bool
    {
        return $this->rank() >= $needed->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Read => 0,
            self::WriteNew => 1,
            self::Edit => 2,
        };
    }
}

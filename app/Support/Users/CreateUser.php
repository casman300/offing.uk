<?php

namespace App\Support\Users;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The one way an account gets made (spec §15) — shared by
 * `artisan user:create` and /admin/users so the rules can't drift.
 * Audited as `user.created` with how it happened in `via`.
 */
final class CreateUser
{
    /**
     * @param  array<string, mixed>  $input  email, plus optional name and timezone
     *
     * @throws ValidationException
     */
    public static function create(array $input, ?User $actor, string $via, ?string $ip = null): User
    {
        $email = Str::lower(trim((string) ($input['email'] ?? '')));

        $data = [
            'email' => $email,
            'name' => trim((string) ($input['name'] ?? '')) ?: Str::before($email, '@'),
            'timezone' => ($input['timezone'] ?? null) ?: User::DEFAULT_TIMEZONE,
        ];

        Validator::make($data, [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ])->validate();

        $user = User::create($data);

        AuditLog::record($actor, 'user.created', $user, ['via' => $via], $ip);

        return $user;
    }
}

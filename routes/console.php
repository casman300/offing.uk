<?php

use App\Models\AuditLog;
use App\Models\ContactImport;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// No sign-up flow yet, so accounts are created by hand on the host. A real
// command rather than `tinker --execute` because 20i's cron "test" box (the
// only shell some environments get) mangles quoted PHP. Takes no quotes:
// `artisan user:create someone@example.com Someone`.
Artisan::command('user:create {email} {name?}', function (string $email, ?string $name = null) {
    $email = Str::lower(trim($email));
    $name ??= Str::before($email, '@');

    $validator = Validator::make(compact('email', 'name'), [
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'name' => ['required', 'string', 'max:255'],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    $user = User::create(['name' => $name, 'email' => $email]);

    AuditLog::record(null, 'user.created', $user, ['via' => 'console']);

    $this->info("Created {$user->email} — request a magic link on the login page to sign in.");
})->purpose('Create a user account (there is no sign-up flow yet)');

// 20i has no daemons/queue workers (spec §4) — the scheduler drains the
// database queue instead. Runs once a minute, processes whatever's due,
// then exits; never a long-lived worker process.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

// Pending vCard imports hold third parties' names and birthdays; drop any
// review abandoned for more than a day (#14).
Schedule::command('model:prune', ['--model' => [ContactImport::class]])
    ->hourly()
    ->withoutOverlapping();

<?php

use App\Models\ContactImport;
use App\Models\Crew;
use App\Support\ConnectClaude\Connections;
use App\Support\Users\CreateUser;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// No sign-up flow yet, so accounts are created by hand on the host. A real
// command rather than `tinker --execute` because 20i's cron "test" box (the
// only shell some environments get) mangles quoted PHP. Takes no quotes:
// `artisan user:create someone@example.com Someone`.
Artisan::command('user:create {email} {name?}', function (string $email, ?string $name = null) {
    try {
        $user = CreateUser::create(['email' => $email, 'name' => $name], null, 'console');
    } catch (ValidationException $e) {
        foreach ($e->validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    $this->info("Created {$user->email} — request a magic link on the login page to sign in.");
})->purpose('Create a user account (there is no sign-up flow yet; /admin/users does the same)');

// 20i has no daemons/queue workers (spec §4) — the scheduler drains the
// database queue instead. Runs once a minute, processes whatever's due,
// then exits; never a long-lived worker process.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

// Pending vCard imports hold third parties' names and birthdays; drop any
// review abandoned for more than a day (#14). Deleted crews go for good
// after 30 days (spec §7).
Schedule::command('model:prune', ['--model' => [ContactImport::class, Crew::class]])
    ->hourly()
    ->withoutOverlapping();

// Connect Claude (spec §12): OAuth clients that registered but were never
// approved, and expired or revoked tokens.
Schedule::call(fn () => Connections::pruneAbandonedClients())->hourly()->name('connect-claude-prune')->withoutOverlapping();
Schedule::command('passport:purge')->daily()->withoutOverlapping();

// Birthday reminders (spec §11, #17): the scanner queues reminders once
// they're due; the drainer sends them, and Barometer check-ins (#36) too.
// Both tolerate 5-minute cron gaps.
Schedule::command('reminders:scan')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('reminders:drain')->everyMinute()->withoutOverlapping();

// Dead-man's switch (spec §22, part of #7): a reminders product whose cron
// silently dies is the worst failure, so ping healthchecks.io every run and
// let it alert when the pings stop. No-op unless HEALTHCHECK_URL is set.
Schedule::call(function () {
    try {
        Http::timeout(10)->retry(2, 1000)->get(config('offing.healthcheck_url'));
    } catch (Throwable $e) {
        report($e);
    }
})->everyMinute()->name('heartbeat')->when(fn () => filled(config('offing.healthcheck_url')));

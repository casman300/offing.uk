<?php

namespace App\Console\Commands;

use App\Features\BirthdayEngine;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;

/**
 * Flip a dark-launched feature for one user, so staging doesn't need tinker.
 * Run it with the host's real PHP CLI (deploy/find-php.sh), not bare `php`.
 */
class FeatureCommand extends Command
{
    protected $signature = 'offing:feature
        {feature : Feature name, e.g. birthday-engine}
        {email : The user to switch it for}
        {--on : Activate the feature}
        {--off : Deactivate the feature}';

    protected $description = 'Show, activate or deactivate a feature flag for one user';

    /**
     * @var array<string, class-string>
     */
    private const FEATURES = [
        'birthday-engine' => BirthdayEngine::class,
    ];

    public function handle(): int
    {
        $name = (string) $this->argument('feature');
        $feature = self::FEATURES[$name] ?? null;

        if ($feature === null) {
            $this->error("Unknown feature '{$name}'. Known: ".implode(', ', array_keys(self::FEATURES)));

            return self::FAILURE;
        }

        $user = User::where('email', strtolower((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        if ($this->option('on') && $this->option('off')) {
            $this->error('Pass --on or --off, not both.');

            return self::FAILURE;
        }

        if ($this->option('on') || $this->option('off')) {
            $on = (bool) $this->option('on');

            if ($on) {
                Feature::for($user)->activate($feature);
            } else {
                Feature::for($user)->deactivate($feature);
            }

            AuditLog::record(null, $on ? 'feature.activated' : 'feature.deactivated', $user, ['feature' => $name, 'via' => 'console']);
        }

        $state = Feature::for($user)->active($feature) ? 'on' : 'off';
        $this->info("{$name} is {$state} for {$user->email}.");

        return self::SUCCESS;
    }
}

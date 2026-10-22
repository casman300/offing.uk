<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Prints a fresh VAPID key pair to paste into a host's .env. Never writes
 * .env itself — keys live with the host, like APP_KEY (CLAUDE.md).
 */
class PushKeysCommand extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Generate a VAPID key pair for Web Push (paste into .env)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line("VAPID_PUBLIC_KEY={$keys['publicKey']}");
        $this->line("VAPID_PRIVATE_KEY={$keys['privateKey']}");
        $this->line('VAPID_SUBJECT=mailto:hello@offing.uk');
        $this->newLine();
        $this->warn('Generate once per environment. Changing keys later breaks every device’s subscription.');

        return self::SUCCESS;
    }
}

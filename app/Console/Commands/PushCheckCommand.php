<?php

namespace App\Console\Commands;

use App\Models\UserDevice;
use App\Support\Push\PushSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PushCheckCommand extends Command
{
    protected $signature = 'push:check';

    protected $description = 'Report whether this host can send Web Push';

    public function handle(PushSender $push): int
    {
        $status = $push->status();

        $this->line('Configured: '.($status->configured ? 'yes' : 'no'));
        $this->line('Ready:      '.($status->ready ? 'yes' : 'no'));
        $this->line('gmp/bcmath: '.(extension_loaded('gmp') || extension_loaded('bcmath') ? 'loaded' : 'neither loaded (optional — push works, just slower)'));
        $this->line('Devices:    '.(Schema::hasTable('user_devices') ? UserDevice::count() : 'n/a (run migrations)'));

        if ($status->reason) {
            $this->warn($status->reason);
        }

        return $status->ready || ! $status->configured ? self::SUCCESS : self::FAILURE;
    }
}

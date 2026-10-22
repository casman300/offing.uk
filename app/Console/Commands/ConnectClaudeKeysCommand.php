<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Connect Claude (spec §12): Passport signs access tokens with an RSA key.
 * Keys live in each host's .env, never in storage/ — the rsync deploy must
 * never ship or overwrite them.
 */
class ConnectClaudeKeysCommand extends Command
{
    protected $signature = 'connect-claude:keys';

    protected $description = 'Generate the OAuth signing key pair for Connect Claude (paste into .env)';

    public function handle(): int
    {
        $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false || ! openssl_pkey_export($key, $private) || ($details = openssl_pkey_get_details($key)) === false) {
            $this->error('OpenSSL couldn’t generate the key pair: '.(openssl_error_string() ?: 'unknown error'));

            return self::FAILURE;
        }

        $public = $details['key'];

        $this->line('PASSPORT_PRIVATE_KEY="'.self::oneLine($private).'"');
        $this->line('PASSPORT_PUBLIC_KEY="'.self::oneLine($public).'"');
        $this->newLine();
        $this->warn('Generate once per environment. Changing keys later signs everyone’s Claude out.');

        return self::SUCCESS;
    }

    /** .env double-quoted values turn \n back into newlines. */
    private static function oneLine(string $pem): string
    {
        return str_replace("\n", '\n', trim($pem));
    }
}

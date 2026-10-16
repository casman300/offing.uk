<?php

namespace App\Mail;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Security notice: a passkey was added to the account (#9).
 */
class PasskeyAddedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Passkey $passkey,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('A passkey was added to your Offing account')
            ->markdown('emails.passkey-added', [
                'settingsUrl' => route('passkeys.index'),
            ]);
    }
}

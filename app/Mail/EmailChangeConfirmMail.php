<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Users\EmailChange;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the new address: nothing changes until this is clicked (H1).
 */
class EmailChangeConfirmMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $newEmail,
        public string $url,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Confirm your new Offing email address')
            ->markdown('emails.email-change-confirm', [
                'hours' => intdiv(EmailChange::CONFIRM_MINUTES, 60),
            ]);
    }
}

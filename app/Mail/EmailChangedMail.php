<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Users\EmailChange;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Security notice to the old address once a change is confirmed (H1), with
 * the link that undoes it.
 */
class EmailChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $oldEmail,
        public string $newEmail,
        public string $revertUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Your Offing email address was changed')
            ->markdown('emails.email-changed', [
                'days' => intdiv(EmailChange::REVERT_MINUTES, 60 * 24),
            ]);
    }
}

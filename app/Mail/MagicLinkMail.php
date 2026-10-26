<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MagicLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * $welcome: an admin sent this to someone who has never signed in, so
     * "if you didn't ask for it" would be confusing — say who set them up.
     */
    public function __construct(
        public User $user,
        public string $url,
        public bool $welcome = false,
        public ?string $invitedBy = null,
    ) {}

    public function build(): self
    {
        return $this
            ->subject($this->welcome ? "You're on Offing" : 'Your Offing magic link')
            ->markdown('emails.magic-link');
    }
}

<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The last step of joining from a copied invite link: proves this inbox is
 * the opener's. Worded the same whether or not the address has an account.
 */
class CrewJoinConfirmMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $crewName,
        public string $url,
        public int $minutes,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("Finish joining {$this->crewName} on Offing")
            ->markdown('emails.crew-join-confirm');
    }
}

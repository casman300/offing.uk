<?php

namespace App\Mail;

use App\Models\CrewInvite;
use App\Models\CrewMember;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A crew invite's link (spec §7/§15). Worded the same whether or not the
 * address has an account — the page it opens sorts that out.
 */
class CrewInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $crewName,
        public string $invitedBy,
        public string $role,
        public string $url,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("{$this->invitedBy} invited you to join {$this->crewName} on Offing")
            ->markdown('emails.crew-invite', [
                'asRole' => $this->role === CrewMember::CAPTAIN ? 'a captain' : 'crew',
                'days' => CrewInvite::EXPIRES_AFTER_DAYS,
            ]);
    }
}

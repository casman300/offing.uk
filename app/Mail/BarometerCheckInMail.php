<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * Barometer check-in (#36): "time for a quick check-in", with a link to add
 * the second rating. Says nothing about anxiety or what was logged — inbox
 * previews and shared screens are visible to other people. Sent
 * synchronously by CheckInDrainer, which owns retries, so not queued.
 */
class BarometerCheckInMail extends Mailable
{
    public string $offUrl;

    public function __construct(public User $user, public int $readingId)
    {
        $this->offUrl = URL::signedRoute('barometer.check-ins.off', ['user' => $user->id]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Time for a quick check-in');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.barometer-check-in', with: [
            'url' => route('barometer.edit', $this->readingId).'#second-rating',
            'offUrl' => $this->offUrl,
        ]);
    }

    /**
     * RFC 8058 one-click unsubscribe, as for reminders.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => "<{$this->offUrl}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}

<?php

namespace App\Mail;

use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * One reminder email listing every birthday due at the same slot (#17).
 * Sent synchronously by the outbox drainer, which owns retries — so this is
 * deliberately not queued. Never includes a person's notes.
 */
class BirthdayReminderMail extends Mailable
{
    public string $unsubscribeUrl;

    /**
     * @param  list<array{person: Person, date: CarbonImmutable, turns: ?int}>  $entries
     */
    public function __construct(
        public User $user,
        public array $entries,
        public int $daysBefore,
        public bool $isTest = false,
    ) {
        $this->unsubscribeUrl = URL::signedRoute('reminders.unsubscribe', ['user' => $user->id]);
    }

    public function envelope(): Envelope
    {
        $when = $this->whenLabel();
        $names = array_map(fn (array $e) => $e['person']->name, $this->entries);
        $who = count($names) === 1 ? "{$names[0]}’s birthday" : count($names).' birthdays';

        return new Envelope(subject: ($this->isTest ? '[Test] ' : '')."{$when}: {$who}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.birthday-reminder', with: [
            'when' => $this->whenLabel(),
            'lines' => array_map(fn (array $e) => [
                // Names are free text: keep markdown from reading them as syntax.
                'name' => addcslashes($e['person']->name, '\\`*_[]'),
                'url' => route('people.show', $e['person']->id),
                'turns' => $e['turns'],
                'date' => $e['date']->format('l j F'),
            ], $this->entries),
            'settingsUrl' => route('reminders.edit'),
        ]);
    }

    /**
     * RFC 8058 one-click unsubscribe: mail apps show their own button and
     * POST to the signed URL (CSRF-exempt, see bootstrap/app.php).
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => "<{$this->unsubscribeUrl}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    private function whenLabel(): string
    {
        // A test lists your next few birthdays, whenever they are.
        if ($this->isTest) {
            return 'Coming up';
        }

        return match ($this->daysBefore) {
            0 => 'Today',
            1 => 'Tomorrow',
            default => "In {$this->daysBefore} days",
        };
    }
}

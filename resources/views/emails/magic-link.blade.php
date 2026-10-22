<x-mail::message>
# Hey {{ $user->name }},

@if ($welcome)
{{ $invitedBy ?? 'Someone' }} has set up an Offing account for you. Tap below to sign in — there's no password, now or ever.
@else
Here's your magic link — no password required.
@endif

<x-mail::button :url="$url">
Log in to Offing
</x-mail::button>

@if ($welcome)
This link works once and expires in 15 minutes. If it's run out, ask for a fresh one on the login page with this email address — it'll arrive in seconds.
@else
This link works once and expires in 15 minutes. If you didn't ask for it, just ignore this email — nothing happens until it's clicked.
@endif

What's in the offing,<br>
Offing
</x-mail::message>

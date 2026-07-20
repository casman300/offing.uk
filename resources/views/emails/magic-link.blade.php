<x-mail::message>
# Hey {{ $user->name }},

Here's your magic link — no password required.

<x-mail::button :url="$url">
Log in to Offing
</x-mail::button>

This link works once and expires in 15 minutes. If you didn't ask for it, just ignore this email — nothing happens until it's clicked.

What's in the offing,<br>
Offing
</x-mail::message>

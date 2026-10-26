<x-mail::message>
# Hey {{ $user->name }},

You asked to change the email address on your Offing account to **{{ $newEmail }}**. Tap below to confirm it's yours — until then, nothing changes.

<x-mail::button :url="$url">
Confirm my new email
</x-mail::button>

This link works once and expires in {{ $hours }} hours. If you didn't ask for this, just ignore this email — your account stays as it is.

What's in the offing,<br>
Offing
</x-mail::message>

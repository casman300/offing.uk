<x-mail::message>
# Hey {{ $user->name }},

The email address on your Offing account was changed from **{{ $oldEmail }}** to **{{ $newEmail }}**. Sign-in links will go to the new address from now on.

If that was you, there's nothing to do.

If it wasn't, undo it straight away. This puts your old address back, signs out everywhere, and removes anything added since the change — passkeys, calendar feed links, devices and AI connections:

<x-mail::button :url="$revertUrl" color="error">
This wasn't me — undo it
</x-mail::button>

This link works once, for {{ $days }} days.

What's in the offing,<br>
Offing
</x-mail::message>

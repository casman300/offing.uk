<x-mail::message>
# Hey {{ $user->name }},

A new passkey called **{{ $passkey->name }}** was added to your Offing account on {{ $passkey->created_at?->timezone($user->timezone ?? 'Europe/London')->format('j F Y \a\t H:i') }}. It can now sign you in without an email link.

If that was you, there's nothing to do.

If it wasn't, remove it straight away:

<x-mail::button :url="$settingsUrl">
Review your passkeys
</x-mail::button>

What's in the offing,<br>
Offing
</x-mail::message>

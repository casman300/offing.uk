<x-mail::message>
# Nearly aboard

Tap below to finish joining **{{ $crewName }}** on Offing with this email address. There's no password, now or ever.

<x-mail::button :url="$url">
Finish joining {{ $crewName }}
</x-mail::button>

This link expires in {{ $minutes }} minutes. If you didn't ask to join, just ignore this email — nothing happens until it's tapped.

What's in the offing,<br>
Offing
</x-mail::message>

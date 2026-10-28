<x-mail::message>
# Ahoy,

{{ $invitedBy }} has invited you to join **{{ $crewName }}** on Offing as {{ $asRole }}. Crews share things like household chores, so everyone aboard sees what's due and who did it.

<x-mail::button :url="$url">
See the invite
</x-mail::button>

New to Offing? The link sets you up with just your name — there's no password, now or ever. Nothing happens until you say yes.

This link expires in {{ $days }} days. If you weren't expecting it, just ignore this email.

What's in the offing,<br>
Offing
</x-mail::message>

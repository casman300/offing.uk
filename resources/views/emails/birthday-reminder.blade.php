<x-mail::message>
# {{ $when }}

@foreach ($lines as $line)
**[{{ $line['name'] }}]({{ $line['url'] }})** {{ $line['turns'] !== null ? 'turns '.$line['turns'] : 'has a birthday' }} — {{ $line['date'] }}

@endforeach
What's in the offing,<br>
Offing

<x-slot:subcopy>
You're getting this because birthday reminders are on. [Change reminder times]({{ $settingsUrl }}) · [Turn off reminders]({{ $unsubscribeUrl }})
</x-slot:subcopy>
</x-mail::message>

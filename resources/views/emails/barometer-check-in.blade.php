<x-mail::message>
# Time for a quick check-in

How are you feeling now? If you'd like to, add a second rating to the reading you took earlier.

<x-mail::button :url="$url">
Add a second rating
</x-mail::button>

What's in the offing,<br>
Offing

<x-slot:subcopy>
You're getting this because check-ins are on. [Turn off check-ins]({{ $offUrl }})
</x-slot:subcopy>
</x-mail::message>

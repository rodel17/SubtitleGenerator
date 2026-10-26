<x-mail::message>
# Your subtitles are ready

The subtitles for **{{ $upload->original_name }}** are attached to this email.

@if ($upload->translationFellBack())
They were translated with Standard (on our own server), because Enhanced (Microsoft Azure) was unavailable.

@endif
They have now been deleted from our servers, so keep this email if you need them again.

Thanks,<br>
SubGen
</x-mail::message>

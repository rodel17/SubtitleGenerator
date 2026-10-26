@props(['url'])
{{-- SubGen header: a "CC" (closed captions) badge, built from text so it shows even where email images are blocked --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<span style="display: inline-block; vertical-align: middle; background-color: #2F5BEA; color: #ffffff; border-radius: 6px; padding: 4px 7px; font-size: 13px; font-weight: 800; letter-spacing: 1px; line-height: 16px;">CC</span>
<span style="display: inline-block; vertical-align: middle; margin-left: 8px; color: #0F172A; font-size: 19px; font-weight: bold;">{!! $slot !!}</span>
</a>
</td>
</tr>

@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- A local address is unreachable from an inbox, so local emails load the logo from the company site --}}
<img src="{{ app()->environment('local') ? 'https://sidracore.com/android-chrome-192x192.png' : asset('images/sidra-mark.png') }}" class="brand-mark" width="36" height="36" alt="Sidra Core"><span class="brand-name">{!! $slot !!}</span>
</a>
</td>
</tr>

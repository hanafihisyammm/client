<x-mail::message>
# {{ $heading }}

Hello {{ $name }},

{{ $intro }}

@if (! empty($details))
<x-mail::table>
| | |
|:--|:--|
@foreach ($details as $label => $value)
| {{ $label }} | {{ $value }} |
@endforeach
</x-mail::table>
@endif

@if (! empty($quote))
@if (! empty($quoteLabel))
**{{ $quoteLabel }}**
@endif

<x-mail::panel>
{{ $quote }}
</x-mail::panel>
@endif

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($actionText))
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

<x-slot:subcopy>
If the "{{ $actionText }}" button does not work, copy and paste this link into your browser: <span class="break-all">[{{ $actionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endif
</x-mail::message>

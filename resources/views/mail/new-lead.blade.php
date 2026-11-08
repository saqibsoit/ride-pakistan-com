@php
    $rows = array_filter([
        'Name' => $lead->name,
        'Phone' => $lead->phone,
        'Email' => $lead->email,
        'Interested in' => $lead->interest?->label(),
        'Preferred date' => $lead->preferred_date?->format('j M Y'),
        'Campaign' => collect([$lead->utm_source, $lead->utm_medium, $lead->utm_campaign])->filter()->implode(' / ') ?: null,
        'Received' => $lead->created_at->setTimezone('Asia/Karachi')->format('j M Y, H:i').' (Pakistan time)',
    ], fn ($value) => filled($value));

    // Visitor text stays plain text: Markdown (links, images) escaped.
    $plain = fn (string $value): string => preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~])/', '\\\\$1', $value) ?? '';

    // Table cells: no pipes or line breaks from visitor input.
    $cell = fn (string $value): string => e($plain(str_replace(['|', "\r", "\n"], ['/', ' ', ' '], $value)));
@endphp
<x-mail::message>
# New lead

A new enquiry came in from the {{ \Illuminate\Support\Str::lower($lead->source->label()) }}.

<x-mail::table>
| | |
|:--|:--|
{!! collect($rows)->map(fn ($value, $label) => '| '.$label.' | '.$cell((string) $value).' |')->implode("\n") !!}
</x-mail::table>

@if ($lead->message)
<x-mail::panel>
{{ $plain($lead->message) }}
</x-mail::panel>
@endif

<x-mail::button :url="$leadUrl">
Open the lead
</x-mail::button>

Ride Pakistan
</x-mail::message>

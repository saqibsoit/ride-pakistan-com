@php
    // Lead names and handles come from visitors: Markdown escaped, no table breaks.
    $plain = fn (string $value): string => preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~])/', '\\\\$1', str_replace(['|', "\r", "\n"], ['/', ' ', ' '], $value)) ?? '';
    $row = fn (array $lead): string => '| ['.e($plain($lead['name'])).']('.$lead['url'].') | '.e($plain($lead['contact'] ?: '—')).' | '.e($lead['status']).' | '.e($lead['due']).($lead['overdue'] ? ' (overdue)' : '').' | '.e($plain($lead['assignee'])).' |';
@endphp
<x-mail::message>
# Follow-ups for {{ $date }}

These open leads are due for a follow-up today or are overdue. Answer them from the lead page, where the quick replies are ready to copy.

@if ($mine !== [])
## Assigned to you

<x-mail::table>
| Lead | Contact | Status | Due | Assigned to |
|:--|:--|:--|:--|:--|
{!! collect($mine)->map($row)->implode("\n") !!}
</x-mail::table>
@endif

@if ($others !== [])
## {{ $mine === [] ? 'Due' : 'Everyone else' }}

<x-mail::table>
| Lead | Contact | Status | Due | Assigned to |
|:--|:--|:--|:--|:--|
{!! collect($others)->map($row)->implode("\n") !!}
</x-mail::table>
@endif

Ride Pakistan
</x-mail::message>

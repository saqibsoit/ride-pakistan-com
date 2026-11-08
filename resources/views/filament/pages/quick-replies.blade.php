{{-- Quick replies page (App\Filament\Pages\QuickReplies): pick a lead, then copy. --}}
<x-filament-panels::page>
    {{ $this->form }}

    {{ $this->replies() }}
</x-filament-panels::page>

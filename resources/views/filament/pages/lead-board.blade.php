{{--
    Lead board (App\Filament\Pages\LeadBoard). Layout: resources/css/filament/lead-board.css.
    Every card has a "Move to" menu (keyboard and single pointer); dragging
    a card onto a column does the same thing. Status is always shown as text.
--}}
<x-filament-panels::page>
    <div class="rp-board__filters">
        <label>
            <span class="fi-fo-field-label-content">Source</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="source">
                    <option value="">All sources</option>
                    @foreach ($this->sourceOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <label>
            <span class="fi-fo-field-label-content">Assignee</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="assignee">
                    <option value="">Everyone</option>
                    <option value="none">Nobody</option>
                    @foreach ($this->teamOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </div>

    <div class="rp-board" x-data="{ dragging: null }">
        @foreach ($this->columns() as $value => $column)
            <section
                class="rp-board__column"
                aria-labelledby="board-col-{{ $value }}"
                x-data="{ over: false }"
                x-bind:data-drop-active="over ? '' : null"
                x-on:dragover.prevent="over = true"
                x-on:dragleave="over = false"
                x-on:drop.prevent="over = false; if (dragging && dragging.status !== '{{ $value }}') { $wire.moveLead(dragging.id, '{{ $value }}') } dragging = null"
            >
                <h2 id="board-col-{{ $value }}" class="rp-board__header">
                    <x-filament::badge :color="$column['status']->color()" :icon="$column['status']->icon()">{{ $column['status']->label() }}</x-filament::badge>
                    <span>{{ $column['total'] }}<span class="fi-sr-only"> leads</span></span>
                </h2>

                @if ($column['leads']->isEmpty())
                    <p class="rp-board__empty">No leads.</p>
                @else
                    <ul class="rp-board__list">
                        @foreach ($column['leads'] as $lead)
                            <li
                                class="rp-board__card"
                                wire:key="lead-{{ $lead->id }}"
                                @if ($lead->status !== \App\Enums\LeadStatus::Booked)
                                    draggable="true"
                                    x-on:dragstart="dragging = { id: {{ $lead->id }}, status: '{{ $value }}' }"
                                    x-on:dragend="dragging = null"
                                @endif
                            >
                                <a href="{{ \App\Filament\Resources\Leads\LeadResource::getUrl('view', ['record' => $lead]) }}" class="rp-board__name">{{ $lead->name }}</a>
                                <span class="rp-board__meta">{{ $lead->source->label() }}@if ($lead->interest) · {{ $lead->interest->label() }}@endif</span>
                                @if ($lead->contactLine() !== '')
                                    <span class="rp-board__meta">{{ $lead->contactLine() }}</span>
                                @endif
                                @if ($lead->assignee)
                                    <span class="rp-board__meta">Assigned to {{ $lead->assignee->name ?? $lead->assignee->email }}</span>
                                @endif
                                @if ($lead->lost_reason)
                                    <span class="rp-board__meta">Lost: {{ $lead->lost_reason }}</span>
                                @endif
                                @if ($lead->next_follow_up_at)
                                    <span class="{{ $lead->isFollowUpDue() ? 'rp-board__due' : 'rp-board__meta' }}">
                                        {{ $lead->isFollowUpDue() ? 'Follow-up due' : 'Follow up' }} {{ $lead->next_follow_up_at->format('j M') }}
                                    </span>
                                @endif

                                @php($targets = \App\Filament\Resources\Leads\LeadActions::targets($lead))
                                <div class="rp-board__actions">
                                    <x-filament::button size="xs" color="gray" icon="heroicon-m-chat-bubble-left-right" wire:click="mountAction('quickReplies', { lead: {{ $lead->id }} })">
                                        Replies<span class="fi-sr-only"> for {{ $lead->name }}</span>
                                    </x-filament::button>
                                @if ($targets !== [])
                                        <x-filament::dropdown placement="bottom-start">
                                            <x-slot name="trigger">
                                                <x-filament::button size="xs" color="gray" icon="heroicon-m-arrows-right-left">
                                                    Move to<span class="fi-sr-only"> (lead {{ $lead->name }})</span>
                                                </x-filament::button>
                                            </x-slot>
                                            <x-filament::dropdown.list>
                                                @foreach ($targets as $target => $label)
                                                    <x-filament::dropdown.list.item wire:click="moveLead({{ $lead->id }}, '{{ $target }}')">
                                                        {{ $label }}
                                                    </x-filament::dropdown.list.item>
                                                @endforeach
                                            </x-filament::dropdown.list>
                                        </x-filament::dropdown>
                                @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($column['total'] > $column['leads']->count())
                        <a href="{{ \App\Filament\Resources\Leads\LeadResource::getUrl('index') }}" class="rp-board__meta">
                            {{ $column['total'] - $column['leads']->count() }} more in the list
                        </a>
                    @endif
                @endif
            </section>
        @endforeach
    </div>

</x-filament-panels::page>

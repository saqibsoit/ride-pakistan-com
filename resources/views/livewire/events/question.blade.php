{{-- One custom event question (decided 2026-11-22). Pass `question`, `model` (wire:model path) and `id`. --}}
@php($label = $question['label'].($question['required'] ? '' : ' (optional)'))
@switch($question['type'])
    @case(\App\Enums\EventQuestionType::Select->value)
        <x-form.field :name="$model" :id="$id" :label="$label" type="select" :required="$question['required']" wire:model="{{ $model }}">
            <option value="">Choose…</option>
            @foreach ($question['options'] as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </x-form.field>
        @break
    @case(\App\Enums\EventQuestionType::YesNo->value)
        <fieldset class="flex flex-col gap-2">
            <legend class="text-sm font-semibold">{{ $label }}</legend>
            <div class="flex gap-6">
                <label class="flex min-h-11 items-center gap-2"><input type="radio" value="yes" wire:model="{{ $model }}" class="size-5 accent-sand"> Yes</label>
                <label class="flex min-h-11 items-center gap-2"><input type="radio" value="no" wire:model="{{ $model }}" class="size-5 accent-sand"> No</label>
            </div>
        </fieldset>
        @break
    @default
        <x-form.field :name="$model" :id="$id" :label="$label" :required="$question['required']" maxlength="{{ \App\Support\Events\EventAnswers::MAX_LENGTH }}" wire:model.blur="{{ $model }}" />
@endswitch

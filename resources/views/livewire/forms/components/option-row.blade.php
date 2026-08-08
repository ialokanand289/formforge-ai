@props([
    'index',
    'isFirst' => false,
    'isLast' => false,
])

@php
    $labelKey = 'fieldForm.options.'.$index.'.label';
    $valueKey = 'fieldForm.options.'.$index.'.value';
@endphp

<div class="rounded-md border border-gray-200 p-2">
    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div>
            <label class="block text-[11px] font-medium text-gray-600" for="option-label-{{ $index }}">Label</label>
            <input id="option-label-{{ $index }}"
                   type="text"
                   wire:model.blur="{{ $labelKey }}"
                   @if ($errors->has($labelKey))
                       aria-invalid="true"
                       aria-describedby="option-label-{{ $index }}-error"
                   @endif
                   class="mt-1 block w-full rounded-md text-xs shadow-sm focus:ring-indigo-500 {{ $errors->has($labelKey) ? 'border-red-400 focus:border-red-500' : 'border-gray-300 focus:border-indigo-500' }}">
            @error($labelKey)
                <p id="option-label-{{ $index }}-error" class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-[11px] font-medium text-gray-600" for="option-value-{{ $index }}">Value</label>
            <input id="option-value-{{ $index }}"
                   type="text"
                   wire:model.blur="{{ $valueKey }}"
                   @if ($errors->has($valueKey))
                       aria-invalid="true"
                       aria-describedby="option-value-{{ $index }}-error"
                   @endif
                   class="mt-1 block w-full rounded-md text-xs shadow-sm focus:ring-indigo-500 {{ $errors->has($valueKey) ? 'border-red-400 focus:border-red-500' : 'border-gray-300 focus:border-indigo-500' }}">
            @error($valueKey)
                <p id="option-value-{{ $index }}-error" class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="mt-2 flex items-center justify-end gap-0.5">
        <x-builder::icon-button
            label="Move option {{ $index + 1 }} up"
            :disabled="$isFirst"
            wire:click="moveOptionUp({{ $index }})"
            wire:target="moveOptionUp({{ $index }})"
            wire:loading.attr="disabled">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" />
            </svg>
        </x-builder::icon-button>

        <x-builder::icon-button
            label="Move option {{ $index + 1 }} down"
            :disabled="$isLast"
            wire:click="moveOptionDown({{ $index }})"
            wire:target="moveOptionDown({{ $index }})"
            wire:loading.attr="disabled">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </x-builder::icon-button>

        <x-builder::icon-button
            label="Remove option {{ $index + 1 }}"
            tone="danger"
            wire:click="removeOption({{ $index }})"
            wire:target="removeOption({{ $index }})"
            wire:loading.attr="disabled">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </x-builder::icon-button>
    </div>
</div>

@props([
    'index',
    'option',
    'isFirst' => false,
    'isLast' => false,
])

<div class="rounded-md border border-gray-200 p-2">
    <div class="grid grid-cols-2 gap-2">
        <div>
            <label class="block text-[11px] font-medium text-gray-600" for="option-label-{{ $index }}">Label</label>
            <input id="option-label-{{ $index }}"
                   type="text"
                   wire:model.blur="fieldForm.options.{{ $index }}.label"
                   class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            @error('fieldForm.options.'.$index.'.label')
                <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-[11px] font-medium text-gray-600" for="option-value-{{ $index }}">Value</label>
            <input id="option-value-{{ $index }}"
                   type="text"
                   wire:model.blur="fieldForm.options.{{ $index }}.value"
                   class="mt-1 block w-full rounded-md border-gray-300 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            @error('fieldForm.options.'.$index.'.value')
                <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="mt-2 flex items-center justify-end gap-0.5">
        <x-builder::icon-button label="Move option up" :disabled="$isFirst" wire:click="moveOptionUp({{ $index }})">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" />
            </svg>
        </x-builder::icon-button>

        <x-builder::icon-button label="Move option down" :disabled="$isLast" wire:click="moveOptionDown({{ $index }})">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </x-builder::icon-button>

        <x-builder::icon-button label="Remove option" tone="danger" wire:click="removeOption({{ $index }})">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </x-builder::icon-button>
    </div>
</div>

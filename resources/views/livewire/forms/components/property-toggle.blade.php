@props([
    'label',
    'model',
    'hint' => null,
])

@php
    $id = 'prop-'.Str::slug(str_replace(['.', '_'], '-', $model));
@endphp

<div class="flex items-start gap-2">
    <input id="{{ $id }}"
           type="checkbox"
           wire:model.live="{{ $model }}"
           class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">

    <label for="{{ $id }}" class="text-xs text-gray-700">
        <span class="font-medium">{{ $label }}</span>
        @if ($hint)
            <span class="mt-0.5 block text-[11px] text-gray-500">{{ $hint }}</span>
        @endif
    </label>
</div>

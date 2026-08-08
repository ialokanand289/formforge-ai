@props([
    'field',
])

<x-form::label :field="$field" />

<select id="{{ $field['id'] }}"
        name="{{ $field['key'] }}"
        wire:model="{{ $field['name'] }}"
        @if ($field['required']) required aria-required="true" @endif
        @if ($field['invalid']) aria-invalid="true" @endif
        @if ($field['describedBy']) aria-describedby="{{ $field['describedBy'] }}" @endif
        @class([
            'mt-1.5 block w-full rounded-lg text-sm shadow-sm',
            'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500' => ! $field['invalid'],
            'border-red-400 focus:border-red-500 focus:ring-red-500' => $field['invalid'],
        ])>
    <option value="" @selected($field['value'] === '')>
        {{ $field['placeholder'] !== '' ? $field['placeholder'] : 'Select an option' }}
    </option>

    @foreach ($field['options'] as $option)
        <option value="{{ $option['value'] }}" @selected($field['value'] === $option['value'])>
            {{ $option['label'] }}
        </option>
    @endforeach
</select>

<x-form::help-text :field="$field" />
<x-form::error :field="$field" />

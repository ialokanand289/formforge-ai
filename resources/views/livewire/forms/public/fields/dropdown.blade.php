@props([
    'field',
])

<x-form::label :field="$field" />

<select id="{{ $field['id'] }}"
        name="{{ $field['key'] }}"
        @if ($field['required']) required aria-required="true" @endif
        @if ($field['describedBy']) aria-describedby="{{ $field['describedBy'] }}" @endif
        class="mt-1.5 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    <option value="" @selected($field['default'] === '')>
        {{ $field['placeholder'] !== '' ? $field['placeholder'] : 'Select an option' }}
    </option>

    @foreach ($field['options'] as $option)
        <option value="{{ $option['value'] }}" @selected($field['default'] === $option['value'])>
            {{ $option['label'] }}
        </option>
    @endforeach
</select>

<x-form::help-text :field="$field" />

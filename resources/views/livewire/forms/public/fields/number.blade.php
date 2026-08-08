@props([
    'field',
])

<x-form::label :field="$field" />

<input id="{{ $field['id'] }}"
       type="number"
       name="{{ $field['key'] }}"
       value="{{ $field['default'] }}"
       @if ($field['placeholder'] !== '') placeholder="{{ $field['placeholder'] }}" @endif
       @if ($field['required']) required aria-required="true" @endif
       @if ($field['min'] !== null) min="{{ $field['min'] }}" @endif
       @if ($field['max'] !== null) max="{{ $field['max'] }}" @endif
       @if ($field['describedBy']) aria-describedby="{{ $field['describedBy'] }}" @endif
       class="mt-1.5 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">

<x-form::help-text :field="$field" />

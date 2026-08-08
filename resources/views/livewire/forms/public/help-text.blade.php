@props([
    'field',
])

@if ($field['helpText'] !== '')
    <p id="{{ $field['id'] }}-help" class="mt-1 text-xs text-gray-500">{{ $field['helpText'] }}</p>
@endif

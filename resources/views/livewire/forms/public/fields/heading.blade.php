@props([
    'field',
])

<div class="border-b border-gray-200 pb-2">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ $field['label'] }}</h3>

    @if ($field['helpText'] !== '')
        <p class="mt-1 text-xs text-gray-500">{{ $field['helpText'] }}</p>
    @endif
</div>

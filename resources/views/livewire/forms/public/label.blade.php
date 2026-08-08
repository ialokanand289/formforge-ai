@props([
    'field',
    'as' => 'label',
])

@php
    $tag = $as === 'legend' ? 'legend' : 'label';
@endphp

<{{ $tag }} @if ($tag === 'label') for="{{ $field['id'] }}" @endif
    class="block text-sm font-medium text-gray-900">
    {{ $field['label'] }}

    @if ($field['required'])
        <span class="text-red-600" aria-hidden="true">*</span>
        <span class="sr-only">(required)</span>
    @endif
</{{ $tag }}>

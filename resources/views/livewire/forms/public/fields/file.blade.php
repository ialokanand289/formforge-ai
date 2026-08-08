@props([
    'field',
])

{{-- Presentation only: nothing is uploaded or stored in this phase. --}}
<x-form::label :field="$field" />

<input id="{{ $field['id'] }}"
       type="file"
       name="{{ $field['key'] }}"
       @if ($field['accept']) accept="{{ $field['accept'] }}" @endif
       @if ($field['required']) required aria-required="true" @endif
       aria-describedby="{{ $field['describedBy'] ? $field['describedBy'].' ' : '' }}{{ $field['id'] }}-limits"
       class="mt-1.5 block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100">

<p id="{{ $field['id'] }}-limits" class="mt-1 text-xs text-gray-500">
    @if ($field['accept'])
        Accepted: {{ $field['accept'] }}.
    @endif
    Maximum size: {{ $field['maxFileSizeKb'] }} KB.
</p>

<x-form::help-text :field="$field" />

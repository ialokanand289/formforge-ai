@props([
    'field',
])

@php
    // Checkbox members fail on an indexed key, and a wildcard lookup returns one
    // list per matching key, so the result is flattened before it is read.
    $messages = Illuminate\Support\Arr::flatten(array_merge(
        $errors->get($field['name']),
        $errors->get($field['name'].'.*'),
    ));
@endphp

@if ($messages !== [])
    <p id="{{ $field['id'] }}-error" class="mt-1.5 text-xs font-medium text-red-600">{{ $messages[0] }}</p>
@endif

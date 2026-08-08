@props([
    'field',
])

<fieldset @if ($field['describedBy']) aria-describedby="{{ $field['describedBy'] }}" @endif>
    <x-form::label :field="$field" as="legend" />

    <div class="mt-2 space-y-2">
        @foreach ($field['options'] as $index => $option)
            <div class="flex items-center gap-2.5">
                <input id="{{ $field['id'] }}-{{ $index }}"
                       type="checkbox"
                       name="{{ $field['key'] }}[]"
                       value="{{ $option['value'] }}"
                       @checked($field['default'] === $option['value'])
                       class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">

                <label for="{{ $field['id'] }}-{{ $index }}" class="text-sm text-gray-700">
                    {{ $option['label'] }}
                </label>
            </div>
        @endforeach
    </div>
</fieldset>

<x-form::help-text :field="$field" />

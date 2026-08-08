@props([
    'field',
])

<fieldset @if ($field['invalid']) aria-invalid="true" @endif
          @if ($field['describedBy']) aria-describedby="{{ $field['describedBy'] }}" @endif>
    <x-form::label :field="$field" as="legend" />

    <div class="mt-2 space-y-2">
        @foreach ($field['options'] as $index => $option)
            <div class="flex items-center gap-2.5">
                <input id="{{ $field['id'] }}-{{ $index }}"
                       type="checkbox"
                       name="{{ $field['key'] }}[]"
                       wire:model="{{ $field['name'] }}"
                       value="{{ $option['value'] }}"
                       @checked(in_array((string) $option['value'], $field['value'], true))
                       class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">

                <label for="{{ $field['id'] }}-{{ $index }}" class="text-sm text-gray-700">
                    {{ $option['label'] }}
                </label>
            </div>
        @endforeach
    </div>
</fieldset>

<x-form::help-text :field="$field" />
<x-form::error :field="$field" />

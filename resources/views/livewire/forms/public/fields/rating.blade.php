@props([
    'field',
])

{{-- Radio based so it stays keyboard operable and needs no custom widget. --}}
<fieldset @if ($field['invalid']) aria-invalid="true" @endif
          @if ($field['describedBy']) aria-describedby="{{ $field['describedBy'] }}" @endif>
    <x-form::label :field="$field" as="legend" />

    <div class="mt-2 flex flex-wrap gap-2">
        @foreach ($field['ratingScale'] as $value)
            <div>
                <input id="{{ $field['id'] }}-{{ $value }}"
                       type="radio"
                       name="{{ $field['key'] }}"
                       wire:model="{{ $field['name'] }}"
                       value="{{ $value }}"
                       @checked($field['value'] === (string) $value)
                       @if ($field['required']) required aria-required="true" @endif
                       class="peer sr-only">

                <label for="{{ $field['id'] }}-{{ $value }}"
                       class="flex h-9 w-9 cursor-pointer items-center justify-center rounded-md border border-gray-300 text-sm font-medium text-gray-700 transition hover:border-indigo-400 peer-checked:border-indigo-600 peer-checked:bg-indigo-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500">
                    {{ $value }}
                </label>
            </div>
        @endforeach
    </div>
</fieldset>

<x-form::help-text :field="$field" />
<x-form::error :field="$field" />

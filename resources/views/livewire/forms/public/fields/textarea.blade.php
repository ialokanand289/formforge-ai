@props([
    'field',
])

<x-form::label :field="$field" />

<textarea id="{{ $field['id'] }}"
          name="{{ $field['key'] }}"
          wire:model="{{ $field['name'] }}"
          rows="4"
          @if ($field['placeholder'] !== '') placeholder="{{ $field['placeholder'] }}" @endif
          @if ($field['required']) required aria-required="true" @endif
          @if ($field['minLength'] !== null) minlength="{{ $field['minLength'] }}" @endif
          @if ($field['maxLength'] !== null) maxlength="{{ $field['maxLength'] }}" @endif
          @if ($field['invalid']) aria-invalid="true" @endif
          @if ($field['describedBy']) aria-describedby="{{ $field['describedBy'] }}" @endif
          @class([
              'mt-1.5 block w-full rounded-lg text-sm shadow-sm',
              'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500' => ! $field['invalid'],
              'border-red-400 focus:border-red-500 focus:ring-red-500' => $field['invalid'],
          ])>{{ $field['value'] }}</textarea>

<x-form::help-text :field="$field" />
<x-form::error :field="$field" />

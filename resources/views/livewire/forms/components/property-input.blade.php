@props([
    'label',
    'model',
    'type' => 'text',
    'value' => '',
    'placeholder' => null,
    'hint' => null,
    'min' => null,
    'maxlength' => null,
    'rows' => 3,
])

@php
    $id = 'prop-'.Str::slug(str_replace(['.', '_'], '-', $model));
    $errorKey = $model;
    $counted = $maxlength !== null;
    $hasError = $errors->has($errorKey);
    $hintId = $hint ? $id.'-hint' : null;
    $errorId = $hasError ? $id.'-error' : null;
    $describedBy = collect([$hintId, $errorId])->filter()->implode(' ');
    $inputClasses = 'block w-full rounded-md text-sm shadow-sm focus:ring-indigo-500 '
        .($hasError ? 'border-red-400 focus:border-red-500' : 'border-gray-300 focus:border-indigo-500');
@endphp

<div @if ($counted) x-data="{ count: @js(mb_strlen((string) $value)) }" @endif>
    <div class="flex items-baseline justify-between gap-2">
        <label for="{{ $id }}" class="block text-xs font-medium text-gray-700">{{ $label }}</label>

        @if ($counted)
            <span class="text-[11px] tabular-nums text-gray-400">
                <span x-text="count"></span>/{{ $maxlength }}
            </span>
        @endif
    </div>

    <div class="mt-1">
        @if ($type === 'textarea')
            <textarea id="{{ $id }}"
                      rows="{{ $rows }}"
                      wire:model.blur="{{ $model }}"
                      @if ($placeholder) placeholder="{{ $placeholder }}" @endif
                      @if ($hasError) aria-invalid="true" @endif
                      @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                      @if ($counted) maxlength="{{ $maxlength }}" x-on:input="count = $event.target.value.length" @endif
                      class="{{ $inputClasses }}"></textarea>
        @else
            <input id="{{ $id }}"
                   type="{{ $type }}"
                   wire:model.blur="{{ $model }}"
                   @if ($placeholder) placeholder="{{ $placeholder }}" @endif
                   @if ($min !== null) min="{{ $min }}" @endif
                   @if ($hasError) aria-invalid="true" @endif
                   @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                   @if ($counted) maxlength="{{ $maxlength }}" x-on:input="count = $event.target.value.length" @endif
                   class="{{ $inputClasses }}">
        @endif
    </div>

    @if ($hint)
        <p id="{{ $hintId }}" class="mt-1 text-[11px] text-gray-500">{{ $hint }}</p>
    @endif

    @error($errorKey)
        <p id="{{ $errorId }}" class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p>
    @enderror
</div>

@props([
    'label',
    'disabled' => false,
    'tone' => 'neutral',
])

@php
    $tones = [
        'neutral' => 'text-gray-500 hover:bg-gray-100 hover:text-gray-700',
        'danger' => 'text-gray-500 hover:bg-red-50 hover:text-red-600',
    ];
@endphp

<button type="button"
        @disabled($disabled)
        title="{{ $label }}"
        aria-label="{{ $label }}"
        {{ $attributes->merge([
            'class' => 'inline-flex h-7 w-7 items-center justify-center rounded-md transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent '
                .($tones[$tone] ?? $tones['neutral']),
        ]) }}>
    {{ $slot }}
</button>

@props([
    'title' => null,
    'disabled' => true,
    'tone' => 'neutral',
])

@php
    $tones = [
        'neutral' => 'border border-gray-200 bg-white text-gray-700 hover:border-indigo-300 hover:text-indigo-600',
        'primary' => 'border border-transparent bg-indigo-600 text-white hover:bg-indigo-500',
    ];

    $enabledClasses = $tones[$tone] ?? $tones['neutral'];
    $disabledClasses = 'cursor-not-allowed border border-gray-200 bg-gray-50 text-gray-400';
@endphp

<button type="button"
        @disabled($disabled)
        @if ($title) title="{{ $title }}" @endif
        {{ $attributes->merge([
            'class' => 'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:cursor-not-allowed disabled:border-gray-200 disabled:bg-gray-50 disabled:text-gray-400 '
                .($disabled ? $disabledClasses : $enabledClasses),
        ]) }}>
    {{ $slot }}
</button>

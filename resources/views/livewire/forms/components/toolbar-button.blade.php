@props([
    'title' => null,
])

<button type="button"
        disabled
        @if ($title) title="{{ $title }}" @endif
        {{ $attributes->merge([
            'class' => 'inline-flex cursor-not-allowed items-center rounded-md border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm font-medium text-gray-400',
        ]) }}>
    {{ $slot }}
</button>

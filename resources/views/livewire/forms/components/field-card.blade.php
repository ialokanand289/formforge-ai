@props([
    'type',
    'label',
    'description',
    'icon',
])

<button type="button"
        data-field-type="{{ $type }}"
        {{ $attributes->merge([
            'class' => 'flex w-full items-start gap-3 rounded-lg border border-gray-200 bg-white p-3 text-left transition hover:border-indigo-300 hover:bg-indigo-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:cursor-wait',
        ]) }}>
    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-indigo-50 text-indigo-600">
        <x-dynamic-component :component="$icon" class="h-5 w-5" />
    </span>

    <span class="min-w-0">
        <span class="block text-sm font-medium text-gray-900">{{ $label }}</span>
        <span class="mt-0.5 block text-xs leading-snug text-gray-500">{{ $description }}</span>
    </span>
</button>

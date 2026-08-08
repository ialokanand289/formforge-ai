@props([
    'type',
    'label',
    'description',
    'icon',
])

<div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-white p-3" data-field-type="{{ $type }}">
    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-indigo-50 text-indigo-600">
        <x-dynamic-component :component="$icon" class="h-5 w-5" />
    </span>

    <div class="min-w-0">
        <p class="text-sm font-medium text-gray-900">{{ $label }}</p>
        <p class="mt-0.5 text-xs leading-snug text-gray-500">{{ $description }}</p>
    </div>
</div>

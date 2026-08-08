@props([
    'fields' => [],
])

<aside class="flex w-full shrink-0 flex-col border-b border-gray-200 bg-white lg:h-full lg:w-72 lg:border-b-0 lg:border-r">
    <div class="shrink-0 border-b border-gray-200 px-4 py-3">
        <h2 class="text-sm font-semibold text-gray-900">Field Palette</h2>
        <p class="mt-0.5 text-xs text-gray-500">Click a field to add it</p>
    </div>

    <div class="max-h-72 overflow-y-auto p-3 lg:max-h-none lg:flex-1">
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-1">
            @foreach ($fields as $field)
                <x-builder::field-card
                    :type="$field['type']"
                    :label="$field['label']"
                    :description="$field['description']"
                    :icon="$field['icon']"
                    wire:click="addField('{{ $field['type'] }}')"
                    wire:key="palette-{{ $field['type'] }}" />
            @endforeach
        </div>
    </div>
</aside>

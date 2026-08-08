@props([
    'section',
])

<section {{ $attributes->merge([
        'class' => 'rounded-xl border bg-white shadow-sm transition '
            .($section['selected'] ? 'border-indigo-400 ring-1 ring-indigo-400' : 'border-gray-200'),
    ]) }}>
    <div class="flex flex-wrap items-center gap-3 border-b border-gray-200 px-4 py-3">
        <button type="button"
                wire:click="selectSection('{{ $section['id'] }}')"
                class="min-w-0 flex-1 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
            <span class="block truncate text-sm font-semibold text-gray-900">{{ $section['title'] }}</span>
            <span class="mt-0.5 block text-xs text-gray-500">
                {{ $section['fieldCount'] }} {{ Str::plural('field', $section['fieldCount']) }}
            </span>
        </button>

        <div class="flex items-center gap-2">
            <button type="button"
                    wire:click="addField('text', '{{ $section['id'] }}')"
                    class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-700 transition hover:border-indigo-300 hover:text-indigo-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Add Field
            </button>

            <x-builder::icon-button
                label="Delete section"
                tone="danger"
                wire:click="removeSection('{{ $section['id'] }}')"
                wire:confirm="Delete this section and its fields?">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
            </x-builder::icon-button>
        </div>
    </div>

    <div class="space-y-2 p-4">
        @forelse ($section['fields'] as $field)
            <x-builder::canvas-field
                :field="$field"
                wire:key="field-{{ $field['id'] }}" />
        @empty
            <p class="rounded-lg border border-dashed px-4 py-6 text-center text-sm transition"
               :class="overSectionId === @js($section['id'])
                   ? 'border-indigo-400 bg-indigo-50/50 text-indigo-600'
                   : 'border-gray-300 text-gray-500'"
               x-on:dragover.prevent="if (draggingId !== null) { overSectionId = @js($section['id']); overId = null; overEdge = null }"
               x-on:dragleave="if (! $el.contains($event.relatedTarget)) overSectionId = null"
               x-on:drop.prevent="
                   if (draggingId === null) { reset(); return; }
                   $wire.moveField(draggingId, @js($section['id']), 0);
                   reset();
               ">
                <span x-show="draggingId === null">No fields yet. Click a field type on the left.</span>
                <span x-cloak x-show="draggingId !== null">Drop the field here.</span>
            </p>
        @endforelse
    </div>
</section>

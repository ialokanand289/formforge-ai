@props([
    'field',
])

@php
    $fieldId = $field['id'];
    $sectionId = $field['sectionId'];
    $index = $field['index'];
@endphp

<div class="relative"
     x-on:dragover.prevent="
         if (draggingId === null || draggingId === @js($fieldId)) return;
         overId = @js($fieldId);
         overSectionId = @js($sectionId);
         overEdge = ($event.clientY - $el.getBoundingClientRect().top) < ($el.offsetHeight / 2) ? 'before' : 'after';
     "
     x-on:dragleave="if (overId === @js($fieldId) && ! $el.contains($event.relatedTarget)) { overId = null; overEdge = null }"
     x-on:drop.prevent="
         if (draggingId === null || draggingId === @js($fieldId)) { reset(); return; }
         $wire.moveField(draggingId, @js($sectionId), {{ $index }} + (overEdge === 'after' ? 1 : 0));
         reset();
     ">
    <div x-cloak
         x-show="overId === @js($fieldId) && overEdge === 'before'"
         class="pointer-events-none absolute -top-1 left-0 right-0 h-0.5 rounded-full bg-indigo-500"
         aria-hidden="true"></div>

    <div {{ $attributes->merge([
            'class' => 'flex items-center gap-2 rounded-lg border bg-white px-2 py-2.5 transition '
                .($field['selected'] ? 'border-indigo-400 ring-1 ring-indigo-400' : 'border-gray-200 hover:border-gray-300'),
        ]) }}
        :class="draggingId === @js($fieldId) && 'opacity-40'">
        <span draggable="true"
              title="Drag to reorder"
              aria-label="Drag to reorder"
              x-on:dragstart="draggingId = @js($fieldId); $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', @js($fieldId))"
              x-on:dragend="reset()"
              class="flex h-7 w-5 shrink-0 cursor-grab items-center justify-center rounded text-gray-400 transition hover:text-gray-600 active:cursor-grabbing">
            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path d="M7 4a1.25 1.25 0 1 1-2.5 0A1.25 1.25 0 0 1 7 4Zm0 6a1.25 1.25 0 1 1-2.5 0A1.25 1.25 0 0 1 7 10Zm0 6a1.25 1.25 0 1 1-2.5 0A1.25 1.25 0 0 1 7 16Zm8.5-12a1.25 1.25 0 1 1-2.5 0 1.25 1.25 0 0 1 2.5 0Zm0 6a1.25 1.25 0 1 1-2.5 0 1.25 1.25 0 0 1 2.5 0Zm0 6a1.25 1.25 0 1 1-2.5 0 1.25 1.25 0 0 1 2.5 0Z" />
            </svg>
        </span>

        <button type="button"
                wire:click="selectField('{{ $fieldId }}')"
                class="flex min-w-0 flex-1 items-center gap-3 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-indigo-50 text-indigo-600">
                <x-dynamic-component :component="$field['icon']" class="h-4 w-4" />
            </span>

            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900">{{ $field['label'] }}</span>

            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600">
                {{ $field['typeLabel'] }}
            </span>
        </button>

        <div class="flex shrink-0 items-center gap-0.5">
            <x-builder::icon-button
                label="Move up"
                :disabled="$field['isFirst']"
                wire:click="moveFieldUp('{{ $fieldId }}')">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" />
                </svg>
            </x-builder::icon-button>

            <x-builder::icon-button
                label="Move down"
                :disabled="$field['isLast']"
                wire:click="moveFieldDown('{{ $fieldId }}')">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </x-builder::icon-button>

            <x-builder::icon-button
                label="Duplicate field"
                wire:click="duplicateField('{{ $fieldId }}')">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 8.25V6a2.25 2.25 0 0 0-2.25-2.25H6A2.25 2.25 0 0 0 3.75 6v8.25A2.25 2.25 0 0 0 6 16.5h2.25m4.5-8.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-7.5A2.25 2.25 0 0 1 8.25 18v-7.5A2.25 2.25 0 0 1 10.5 8.25Z" />
                </svg>
            </x-builder::icon-button>

            <x-builder::icon-button
                label="Delete field"
                tone="danger"
                wire:click="removeField('{{ $fieldId }}')">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
            </x-builder::icon-button>
        </div>
    </div>

    <div x-cloak
         x-show="overId === @js($fieldId) && overEdge === 'after'"
         class="pointer-events-none absolute -bottom-1 left-0 right-0 h-0.5 rounded-full bg-indigo-500"
         aria-hidden="true"></div>
</div>

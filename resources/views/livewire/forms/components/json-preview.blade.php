@props([
    'json' => '{}',
    'open' => false,
    'error' => null,
    'message' => null,
])

<section x-data="{ open: @js($open) }" class="shrink-0 border-t border-gray-200 bg-white">
    <button type="button"
            x-on:click="open = !open"
            :aria-expanded="open ? 'true' : 'false'"
            aria-controls="builder-json-editor"
            class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left transition hover:bg-gray-50 sm:px-6">
        <span class="flex items-center gap-2">
            <span class="text-sm font-semibold text-gray-900">Edit schema JSON</span>
            <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700">Source of truth</span>
            @if ($error)
                <span class="rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-700">Not applied</span>
            @endif
        </span>

        <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform" :class="open && 'rotate-180'"
             fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>
    </button>

    <div id="builder-json-editor" x-cloak x-show="open" class="border-t border-gray-200">
        <label for="builder-json-textarea" class="sr-only">Schema JSON</label>

        <textarea id="builder-json-textarea"
                  wire:model.blur="schemaDraft"
                  spellcheck="false"
                  autocomplete="off"
                  autocapitalize="off"
                  autocorrect="off"
                  aria-describedby="builder-json-status"
                  class="block max-h-44 w-full resize-y border-0 bg-gray-900 p-4 font-mono text-xs leading-relaxed text-gray-100 focus:ring-2 focus:ring-inset focus:ring-indigo-400 sm:max-h-72 sm:px-6"
                  rows="14">{{ $json }}</textarea>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 px-4 py-3 sm:px-6">
            <p id="builder-json-status" class="min-w-0 flex-1 text-xs" aria-live="polite">
                @if ($error)
                    <span class="font-medium text-red-700">{{ $error }}</span>
                @elseif ($message)
                    <span class="font-medium text-green-700">{{ $message }}</span>
                @else
                    <span class="text-gray-500">Edits here replace the canvas once applied. Nothing is saved until you press Save.</span>
                @endif
            </p>

            <div class="flex shrink-0 items-center gap-2">
                <button type="button"
                        wire:click="formatJson"
                        wire:loading.attr="disabled"
                        wire:target="formatJson, applyJson"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50">
                    Format JSON
                </button>

                <button type="button"
                        wire:click="applyJson"
                        wire:loading.attr="disabled"
                        wire:target="formatJson, applyJson"
                        class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                    <span wire:loading.remove wire:target="applyJson">Apply JSON</span>
                    <span wire:loading wire:target="applyJson">Applying...</span>
                </button>
            </div>
        </div>
    </div>
</section>

@props([
    'json' => '{}',
    'open' => false,
])

<section x-data="{ open: @js($open) }" class="shrink-0 border-t border-gray-200 bg-white">
    <button type="button"
            x-on:click="open = !open"
            :aria-expanded="open ? 'true' : 'false'"
            aria-controls="builder-json-preview"
            class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left transition hover:bg-gray-50 sm:px-6">
        <span class="flex items-center gap-2">
            <span class="text-sm font-semibold text-gray-900">JSON Preview</span>
            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-500">Read only</span>
        </span>

        <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform" :class="open && 'rotate-180'"
             fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>
    </button>

    <div id="builder-json-preview" x-cloak x-show="open" class="border-t border-gray-200">
        <pre class="max-h-44 overflow-auto bg-gray-900 p-4 text-xs leading-relaxed text-gray-100 sm:max-h-72 sm:px-6"><code>{{ $json }}</code></pre>
    </div>
</section>

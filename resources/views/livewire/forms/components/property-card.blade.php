@props([
    'title',
    'open' => false,
])

<section x-data="{ open: @js($open) }" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
    <button type="button"
            x-on:click="open = !open"
            :aria-expanded="open ? 'true' : 'false'"
            class="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left transition hover:bg-gray-50">
        <span class="text-xs font-semibold uppercase tracking-wide text-gray-700">{{ $title }}</span>

        <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform" :class="open && 'rotate-180'"
             fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>
    </button>

    <div x-cloak x-show="open" class="space-y-3 border-t border-gray-200 p-3">
        {{ $slot }}
    </div>
</section>

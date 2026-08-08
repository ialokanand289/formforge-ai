@props([
    'message' => null,
])

@if ($message)
    <div role="alert" class="flex shrink-0 items-start gap-3 border-b border-red-200 bg-red-50 px-4 py-3 sm:px-6">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
        </svg>

        <p class="flex-1 text-sm text-red-800">{{ $message }}</p>

        <button type="button"
                wire:click="dismissSchemaError"
                class="shrink-0 rounded-md p-1 text-red-500 transition hover:bg-red-100 hover:text-red-700"
                aria-label="Dismiss message">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>
    </div>
@endif

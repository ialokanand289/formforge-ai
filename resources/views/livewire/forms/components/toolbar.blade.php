@props([
    'title' => 'Untitled Form',
    'status' => 'draft',
    'backUrl' => null,
])

<header class="flex shrink-0 flex-wrap items-center gap-3 border-b border-gray-200 bg-white px-4 py-3 sm:px-6">
    <div class="flex min-w-0 flex-1 items-center gap-3">
        @if ($backUrl)
            <a href="{{ $backUrl }}"
               class="inline-flex items-center rounded-md p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700"
               aria-label="Back to forms">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
            </a>
        @endif

        <h1 class="truncate text-base font-semibold text-gray-900 sm:text-lg">{{ $title }}</h1>

        <span class="inline-flex shrink-0 items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium capitalize text-amber-800">
            {{ $status }}
        </span>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <x-builder::toolbar-button title="Saving arrives in a later phase">Save</x-builder::toolbar-button>
        <x-builder::toolbar-button title="Preview arrives in a later phase">Preview</x-builder::toolbar-button>
        <x-builder::toolbar-button title="AI generation arrives in a later phase">AI</x-builder::toolbar-button>
        <x-builder::toolbar-button title="Import arrives in a later phase">Import</x-builder::toolbar-button>
    </div>
</header>

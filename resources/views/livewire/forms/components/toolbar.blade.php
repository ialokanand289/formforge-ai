@props([
    'title' => 'Untitled Form',
    'status' => 'draft',
    'backUrl' => null,
    'unsaved' => false,
    'saved' => null,
    'importLabel' => 'Import',
    'versionsUrl' => null,
    'submissionsUrl' => null,
    'publicUrl' => null,
])

@php
    $statusTone = match ($status) {
        'published' => 'bg-emerald-100 text-emerald-800',
        'archived' => 'bg-gray-200 text-gray-700',
        default => 'bg-amber-100 text-amber-800',
    };
@endphp

<header class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-b border-gray-200 bg-white px-3 py-3 sm:px-6">
    <div class="flex min-w-0 flex-1 basis-full items-center gap-2 sm:basis-auto sm:gap-3">
        @if ($backUrl)
            <a href="{{ $backUrl }}"
               class="inline-flex shrink-0 items-center rounded-md p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
               aria-label="Back to forms">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
            </a>
        @endif

        <h1 class="truncate text-base font-semibold text-gray-900 sm:text-lg">{{ $title }}</h1>

        <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize {{ $statusTone }}">
            {{ $status }}
        </span>

        <span role="status" aria-live="polite" class="shrink-0">
            @if ($unsaved)
                <span class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                    <span class="hidden sm:inline">Unsaved changes</span>
                    <span class="sm:hidden">Unsaved</span>
                </span>
            @elseif ($saved)
                <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                    {{ $saved }}
                </span>
            @endif
        </span>
    </div>

    <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
        @if ($publicUrl)
            {{-- The live URL, kept beside the toggle so the effect of publishing is visible. --}}
            <div x-data="{ copied: false }" class="flex items-center gap-1.5 rounded-md bg-emerald-50 py-1 pl-2.5 pr-1 ring-1 ring-inset ring-emerald-200">
                <a href="{{ $publicUrl }}" target="_blank" rel="noopener"
                   class="max-w-[14rem] truncate text-xs font-medium text-emerald-800 underline-offset-2 hover:underline">
                    {{ $publicUrl }}
                </a>
                <button type="button"
                        x-on:click="navigator.clipboard.writeText(@js($publicUrl)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                        class="rounded px-1.5 py-0.5 text-xs font-medium text-emerald-700 transition hover:bg-emerald-100"
                        aria-label="Copy public form link">
                    <span x-show="! copied">Copy</span>
                    <span x-show="copied" x-cloak>Copied</span>
                </button>
            </div>
        @endif

        @if ($versionsUrl)
            {{-- Disabled while dirty: navigating away destroys the builder's
                 in-memory schema, so unsaved work would vanish silently. --}}
            @if ($unsaved)
                <span class="inline-flex cursor-not-allowed items-center rounded-md border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-400"
                      title="Save or discard your changes before leaving the builder">
                    Versions
                </span>
            @else
                <a href="{{ $versionsUrl }}" wire:navigate
                   title="View, compare and roll back saved versions"
                   class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                    Versions
                </a>
            @endif
        @endif

        @if ($submissionsUrl)
            <a href="{{ $submissionsUrl }}" wire:navigate
               title="View responses to this form"
               class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                Submissions
            </a>
        @endif

        <x-builder::toolbar-button
            tone="primary"
            :disabled="! $unsaved"
            :title="$unsaved ? 'Save changes' : 'No changes to save'"
            wire:click="save"
            wire:target="save"
            wire:loading.attr="disabled"
            wire:loading.class="opacity-70">
            <span wire:loading.remove wire:target="save">Save</span>
            <span wire:loading wire:target="save">Saving...</span>
        </x-builder::toolbar-button>

        @if ($status === 'published')
            <x-builder::toolbar-button
                :disabled="false"
                title="Take this form offline"
                wire:click="unpublish"
                wire:target="unpublish"
                wire:loading.attr="disabled">Unpublish</x-builder::toolbar-button>
        @else
            <x-builder::toolbar-button
                :disabled="false"
                title="Make this form reachable at its public link"
                wire:click="publish"
                wire:target="publish"
                wire:loading.attr="disabled">Publish</x-builder::toolbar-button>
        @endif

        <x-builder::toolbar-button title="Preview arrives in a later phase">Preview</x-builder::toolbar-button>
        <x-builder::toolbar-button
            :disabled="false"
            title="Generate or edit this form with AI"
            wire:click="toggleAiPanel">AI</x-builder::toolbar-button>
        <x-builder::toolbar-button
            :disabled="false"
            title="Build this form from a Word or Excel document"
            wire:click="toggleImport">{{ $importLabel }}</x-builder::toolbar-button>
    </div>
</header>

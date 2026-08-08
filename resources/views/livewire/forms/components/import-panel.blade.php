@props([
    'open' => false,
    'running' => false,
    'status' => null,
    'preview' => null,
    'filename' => null,
    'source' => null,
    'notice' => null,
    'error' => null,
    'maxKb' => 10240,
])

@if ($open)
    {{-- The poll attribute only exists while an import is in flight, so polling
         stops on its own the moment the status resolves. --}}
    <section class="shrink-0 border-b border-gray-200 bg-sky-50/60"
             @if ($running) wire:poll.2s="pollImport" @endif>
        <div class="px-4 py-4 sm:px-6">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <h2 class="text-sm font-semibold text-gray-900">Import a document</h2>

                <button type="button"
                        wire:click="toggleImport"
                        class="ml-auto rounded-md p-1 text-gray-500 transition hover:bg-white hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500"
                        aria-label="Close import panel">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <p class="mt-1 text-xs text-gray-600">
                Upload a Word (.docx) or Excel (.xlsx) file and review what it detects. Nothing changes until you accept it.
            </p>

            @if (! $preview && ! $running)
                <form wire:submit="startImport" class="mt-3">
                    <label for="import-file" class="sr-only">Document to import</label>

                    <input id="import-file"
                           type="file"
                           wire:model="importFile"
                           accept=".docx,.xlsx"
                           @class([
                               'block w-full rounded-md border border-gray-300 bg-white text-sm text-gray-700 shadow-sm file:mr-3 file:rounded-l-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500',
                               'border-red-400' => $errors->has('importFile'),
                           ])>

                    <p class="mt-1 text-xs text-gray-500">
                        Word or Excel, up to {{ number_format($maxKb / 1024, 0) }} MB.
                    </p>

                    @error('importFile')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror

                    <div class="mt-2 flex items-center gap-3">
                        <button type="submit"
                                wire:target="startImport,importFile"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center rounded-md bg-sky-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-sky-500 disabled:cursor-not-allowed disabled:bg-gray-300">
                            <span wire:loading.remove wire:target="startImport,importFile">Import document</span>
                            <span wire:loading wire:target="importFile">Uploading...</span>
                            <span wire:loading wire:target="startImport">Sending...</span>
                        </button>

                        <span class="text-xs text-gray-500">Runs in the background; this page updates itself.</span>
                    </div>
                </form>
            @endif

            <div aria-live="polite" class="mt-3 empty:mt-0">
                @if ($running)
                    <p class="flex items-center gap-2 text-sm font-medium text-sky-800">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" />
                        </svg>
                        {{ $status === 'processing'
                            ? 'Reading '.($filename ?: 'your document').' and building a form...'
                            : 'Waiting for a worker to pick this up...' }}
                    </p>
                @elseif ($notice)
                    <p class="text-sm font-medium text-emerald-700">{{ $notice }}</p>
                @endif
            </div>

            @if ($preview)
                <div class="mt-3 rounded-md border border-sky-200 bg-white">
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 border-b border-gray-200 px-3 py-2">
                        <h3 class="text-sm font-semibold text-gray-900">{{ $preview['title'] ?? 'Untitled Form' }}</h3>
                        <span class="text-xs text-gray-500">
                            {{ $preview['field_count'] ?? 0 }} {{ Str::plural('field', $preview['field_count'] ?? 0) }}
                            detected in {{ $filename }}
                            <span class="uppercase">({{ $source }})</span>
                        </span>
                    </div>

                    @foreach ($preview['warnings'] ?? [] as $warning)
                        <p class="border-b border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">{{ $warning }}</p>
                    @endforeach

                    <div class="max-h-72 overflow-y-auto px-3 py-2">
                        @forelse ($preview['sections'] ?? [] as $section)
                            <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-gray-500 first:mt-0">
                                {{ $section['title'] }}
                            </p>

                            <ul class="mt-1 divide-y divide-gray-100">
                                @foreach ($section['fields'] as $field)
                                    <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 py-1.5">
                                        <span class="text-sm text-gray-900">{{ $field['label'] }}</span>

                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600">
                                            {{ $field['type'] }}
                                        </span>

                                        @if ($field['required'])
                                            <span class="text-xs font-medium text-red-600">required</span>
                                        @endif

                                        <span class="text-xs text-gray-400">{{ $field['key'] }}</span>

                                        @if ($field['options'])
                                            <span class="basis-full text-xs text-gray-500">
                                                Options: {{ implode(', ', $field['options']) }}
                                            </span>
                                        @endif

                                        @if ($field['validation'])
                                            <span class="basis-full text-xs text-gray-500">{{ $field['validation'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @empty
                            <p class="py-2 text-sm text-gray-500">No fields were detected in this document.</p>
                        @endforelse
                    </div>

                    <div class="flex items-center gap-3 border-t border-gray-200 px-3 py-2">
                        <button type="button"
                                wire:click="acceptImport"
                                wire:target="acceptImport"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:bg-gray-300">
                            <span wire:loading.remove wire:target="acceptImport">Accept import</span>
                            <span wire:loading wire:target="acceptImport">Applying...</span>
                        </button>

                        <button type="button"
                                wire:click="cancelImport"
                                class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900">
                            Discard
                        </button>

                        <span class="text-xs text-gray-500">Accepting replaces the current form and saves a new version.</span>
                    </div>
                </div>
            @endif

            @if ($error)
                <div class="mt-3 flex items-start gap-3 rounded-md border border-red-200 bg-red-50 px-3 py-2">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>

                    <p class="min-w-0 flex-1 break-words text-sm text-red-800">{{ $error }}</p>

                    <button type="button"
                            wire:click="dismissImportError"
                            class="shrink-0 rounded p-0.5 text-red-500 transition hover:bg-red-100 hover:text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500"
                            aria-label="Dismiss import error">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            @endif
        </div>
    </section>
@endif

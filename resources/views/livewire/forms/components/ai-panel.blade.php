@props([
    'open' => false,
    'mode' => 'generate',
    'running' => false,
    'status' => null,
    'notice' => null,
    'error' => null,
    'maxChars' => 2000,
])

@if ($open)
    {{-- The poll attribute only exists while a request is in flight, so polling
         stops on its own the moment the status resolves. --}}
    <section class="shrink-0 border-b border-gray-200 bg-indigo-50/60"
             @if ($running) wire:poll.2s="pollAi" @endif>
        <div class="px-4 py-4 sm:px-6">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <h2 class="text-sm font-semibold text-gray-900">Build with AI</h2>

                <div class="inline-flex rounded-md border border-gray-300 bg-white p-0.5" role="group" aria-label="AI mode">
                    <button type="button"
                            wire:click="$set('aiMode', 'generate')"
                            @disabled($running)
                            aria-pressed="{{ $mode === 'generate' ? 'true' : 'false' }}"
                            @class([
                                'rounded px-2.5 py-1 text-xs font-medium transition disabled:opacity-50',
                                'bg-indigo-600 text-white' => $mode === 'generate',
                                'text-gray-600 hover:text-gray-900' => $mode !== 'generate',
                            ])>
                        Generate
                    </button>
                    <button type="button"
                            wire:click="$set('aiMode', 'edit')"
                            @disabled($running)
                            aria-pressed="{{ $mode === 'edit' ? 'true' : 'false' }}"
                            @class([
                                'rounded px-2.5 py-1 text-xs font-medium transition disabled:opacity-50',
                                'bg-indigo-600 text-white' => $mode === 'edit',
                                'text-gray-600 hover:text-gray-900' => $mode !== 'edit',
                            ])>
                        Edit
                    </button>
                </div>

                <button type="button"
                        wire:click="toggleAiPanel"
                        class="ml-auto rounded-md p-1 text-gray-500 transition hover:bg-white hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                        aria-label="Close AI panel">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <p class="mt-1 text-xs text-gray-600">
                {{ $mode === 'edit'
                    ? 'Describe the change. Existing fields keep their keys, so answers already collected stay attached.'
                    : 'Describe the form you need. This replaces the current fields.' }}
            </p>

            <form wire:submit="runAi" class="mt-3">
                <label for="ai-prompt" class="sr-only">AI instruction</label>

                <textarea id="ai-prompt"
                          wire:model="aiPrompt"
                          rows="3"
                          maxlength="{{ $maxChars }}"
                          @disabled($running)
                          placeholder="{{ $mode === 'edit'
                              ? 'Add a department dropdown with HR, Engineering and Sales.'
                              : 'An employee registration form with name, email, phone, department, joining date and experience.' }}"
                          @class([
                              'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100',
                              'border-red-400' => $errors->has('aiPrompt'),
                          ])></textarea>

                @error('aiPrompt')
                    <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                @enderror

                <div class="mt-2 flex items-center gap-3">
                    <button type="submit"
                            @disabled($running)
                            wire:target="runAi"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-300">
                        <span wire:loading.remove wire:target="runAi">{{ $mode === 'edit' ? 'Apply edit' : 'Generate form' }}</span>
                        <span wire:loading wire:target="runAi">Sending...</span>
                    </button>

                    <span class="text-xs text-gray-500">Runs in the background; this page updates itself.</span>
                </div>
            </form>

            <div aria-live="polite" class="mt-3 empty:mt-0">
                @if ($running)
                    <p class="flex items-center gap-2 text-sm font-medium text-indigo-800">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" />
                        </svg>
                        {{ $status === 'processing' ? 'The AI is writing your form...' : 'Waiting for a worker to pick this up...' }}
                    </p>
                @elseif ($notice)
                    <p class="text-sm font-medium text-emerald-700">{{ $notice }}</p>
                @endif
            </div>

            @if ($error)
                <div class="mt-3 flex items-start gap-3 rounded-md border border-red-200 bg-red-50 px-3 py-2">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>

                    <p class="min-w-0 flex-1 break-words text-sm text-red-800">{{ $error }}</p>

                    <button type="button"
                            wire:click="dismissAiError"
                            class="shrink-0 rounded p-0.5 text-red-500 transition hover:bg-red-100 hover:text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500"
                            aria-label="Dismiss AI error">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            @endif
        </div>
    </section>
@endif

<div class="py-12">
    <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">

        <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4">
                <div class="min-w-0">
                    <h2 class="truncate text-lg font-semibold text-gray-900">Submissions</h2>
                    <p class="mt-0.5 truncate text-sm text-gray-500">{{ $form->title }}</p>
                </div>

                <div class="flex items-center gap-2">
                    <a href="{{ route('forms.submissions.export', $form) }}"
                       class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                        Export CSV
                    </a>
                    <a href="{{ route('forms.versions', $form) }}" wire:navigate
                       class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                        Versions
                    </a>
                    <a href="{{ route('forms.builder', $form) }}" wire:navigate
                       class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-500">
                        Back to Builder
                    </a>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-3 border-b border-gray-200 bg-gray-50 px-6 py-3">
                <div class="min-w-0 flex-1">
                    <label for="submission-search" class="block text-xs font-medium text-gray-600">Search answers</label>
                    <input id="submission-search"
                           type="search"
                           wire:model.live.debounce.400ms="search"
                           placeholder="Search across submitted answers"
                           class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>

                <div>
                    <label for="submission-status" class="block text-xs font-medium text-gray-600">Status</label>
                    <select id="submission-status"
                            wire:model.live="statusFilter"
                            class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All</option>
                        @foreach ($this->statuses as $status)
                            <option value="{{ $status['value'] }}">{{ $status['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div wire:loading wire:target="search, statusFilter" class="pb-2 text-xs text-gray-500">
                    Searching...
                </div>
            </div>

            @if ($this->submissions->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="text-sm font-medium text-gray-900">No submissions found</p>
                    <p class="mt-1 text-sm text-gray-500">
                        @if ($search !== '' || $statusFilter !== '')
                            Nothing matches the current search or filter.
                        @else
                            Responses will appear here once someone fills in the published form.
                        @endif
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th scope="col" class="px-6 py-2.5 font-medium">Submitted At</th>
                                <th scope="col" class="px-6 py-2.5 font-medium">Version</th>
                                <th scope="col" class="px-6 py-2.5 font-medium">Status</th>
                                <th scope="col" class="px-6 py-2.5 font-medium">Answers</th>
                                <th scope="col" class="px-6 py-2.5 font-medium"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($this->submissions as $submission)
                                <tr @class(['bg-indigo-50/50' => $selectedId === $submission->id])>
                                    <td class="whitespace-nowrap px-6 py-3 text-gray-900">
                                        {{ $submission->created_at?->format('j M Y, H:i') }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-3 text-gray-600">v{{ $submission->form_version }}</td>
                                    <td class="whitespace-nowrap px-6 py-3">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize',
                                            'bg-red-100 text-red-800' => $submission->status === \App\Enums\SubmissionStatus::Spam,
                                            'bg-emerald-100 text-emerald-800' => $submission->status !== \App\Enums\SubmissionStatus::Spam,
                                        ])>
                                            {{ $submission->status->value }}
                                        </span>
                                    </td>
                                    <td class="max-w-md px-6 py-3 text-gray-600">
                                        <span class="line-clamp-1">{{ $this->preview($submission) }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-3 text-right">
                                        <button type="button"
                                                wire:click="select('{{ $submission->id }}')"
                                                class="text-sm font-medium text-indigo-600 transition hover:text-indigo-500">
                                            View
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($this->submissions->hasPages())
                    <div class="border-t border-gray-200 px-6 py-3">
                        {{ $this->submissions->links() }}
                    </div>
                @endif
            @endif
        </div>

        @if ($this->selected)
            @php $entry = $this->selected; @endphp

            <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Submission detail</h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{ $entry['submitted_at']?->format('j M Y, H:i') }}
                            &middot; schema version {{ $entry['version'] }}
                            &middot; {{ $entry['status'] }}
                        </p>
                    </div>

                    <button type="button" wire:click="clearSelection"
                            class="text-sm font-medium text-gray-500 transition hover:text-gray-700">Close</button>
                </div>

                <div class="px-6 py-5">
                    @if ($entry['answers'] === [])
                        <p class="text-sm text-gray-500">This submission carries no answers.</p>
                    @else
                        <dl class="divide-y divide-gray-100">
                            @foreach ($entry['answers'] as $answer)
                                <div class="grid gap-1 py-2.5 sm:grid-cols-3 sm:gap-4">
                                    <dt class="text-sm font-medium text-gray-700">
                                        {{ $answer['label'] }}
                                        <span class="block font-mono text-xs font-normal text-gray-400">{{ $answer['key'] }}</span>
                                    </dt>
                                    <dd class="text-sm text-gray-900 sm:col-span-2">
                                        {{ $answer['value'] !== '' ? $answer['value'] : '—' }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    @if ($entry['files'] !== [])
                        <div class="mt-5 border-t border-gray-200 pt-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Attached files</p>
                            <ul class="mt-2 space-y-1">
                                @foreach ($entry['files'] as $file)
                                    <li class="text-sm text-gray-700">
                                        <span class="font-mono text-xs text-gray-400">{{ $file['field_key'] }}</span>
                                        {{ $file['name'] }}
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-2 text-xs text-gray-500">
                                Uploads stay on the private disk; filenames are listed for reference only.
                            </p>
                        </div>
                    @endif
                </div>
            </section>
        @endif
    </div>
</div>

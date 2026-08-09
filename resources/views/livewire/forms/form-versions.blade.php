@php
    $toneFor = fn (string $status): string => match ($status) {
        'added' => 'bg-green-50 text-green-800 ring-green-200',
        'removed' => 'bg-red-50 text-red-800 ring-red-200',
        'changed' => 'bg-amber-50 text-amber-900 ring-amber-200',
        default => 'bg-gray-50 text-gray-600 ring-gray-200',
    };
@endphp

<div class="py-12">
    <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">

        <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4">
                <div class="min-w-0">
                    <h2 class="truncate text-lg font-semibold text-gray-900">Version History</h2>
                    <p class="mt-0.5 truncate text-sm text-gray-500">{{ $form->title }}</p>
                </div>

                <div class="flex items-center gap-2">
                    <a href="{{ route('forms.submissions.index', $form) }}"
                       wire:navigate
                       class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                        Submissions
                    </a>
                    <a href="{{ route('forms.builder', $form) }}"
                       wire:navigate
                       class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-500">
                        Back to Builder
                    </a>
                </div>
            </div>

            @if ($rollbackError)
                <div class="border-b border-red-200 bg-red-50 px-6 py-3" role="alert">
                    <p class="text-sm text-red-800">{{ $rollbackError }}</p>
                </div>
            @endif

            @if ($this->versions->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="text-sm font-medium text-gray-900">No versions yet</p>
                    <p class="mt-1 text-sm text-gray-500">
                        A snapshot is recorded every time you save this form in the builder.
                    </p>
                </div>
            @else
                <ul class="divide-y divide-gray-200">
                    @foreach ($this->versions as $version)
                        @php $isCurrent = $version->version === $form->schema_version; @endphp

                        <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4">
                            <div class="min-w-0">
                                <p class="flex items-center gap-2 text-sm font-medium text-gray-900">
                                    Version {{ $version->version }}
                                    @if ($isCurrent)
                                        <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                                            Current
                                        </span>
                                    @endif
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ $version->created_at?->format('j M Y, H:i') }}
                                    by {{ $version->creator?->name ?? 'Unknown' }}
                                </p>
                                @if ($version->note)
                                    <p class="mt-1 text-sm text-gray-600">{{ $version->note }}</p>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <button type="button"
                                        wire:click="viewVersion('{{ $version->id }}')"
                                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                    View
                                </button>

                                <button type="button"
                                        wire:click="compareVersion('{{ $version->id }}')"
                                        @disabled($isCurrent)
                                        title="{{ $isCurrent ? 'This is the current schema' : 'Compare with the current schema' }}"
                                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50">
                                    Compare
                                </button>

                                <button type="button"
                                        wire:click="rollback('{{ $version->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="rollback"
                                        @disabled($isCurrent)
                                        title="{{ $isCurrent ? 'Already the current schema' : 'Restore this schema as a new version' }}"
                                        class="inline-flex items-center rounded-md bg-gray-900 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-50">
                                    Roll back
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($this->versions->hasPages())
                    <div class="border-t border-gray-200 px-6 py-3">
                        {{ $this->versions->links() }}
                    </div>
                @endif
            @endif
        </div>

        @if ($this->viewing)
            @php $snapshot = $this->viewing; @endphp

            <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">
                            Version {{ $snapshot['version'] }} <span class="font-normal text-gray-500">(read only)</span>
                        </h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{ $snapshot['created_at']?->format('j M Y, H:i') }} by {{ $snapshot['creator'] }}
                            @if ($snapshot['note']) &middot; {{ $snapshot['note'] }} @endif
                        </p>
                    </div>

                    <button type="button" wire:click="closePanel"
                            class="text-sm font-medium text-gray-500 transition hover:text-gray-700">Close</button>
                </div>

                <div class="space-y-6 px-6 py-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Title</p>
                            <p class="mt-1 text-sm text-gray-900">{{ $snapshot['title'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Description</p>
                            <p class="mt-1 text-sm text-gray-900">{{ $snapshot['description'] !== '' ? $snapshot['description'] : '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Submit Button</p>
                            <p class="mt-1 text-sm text-gray-900">{{ $snapshot['settings']['submit_button_text'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Multi Step</p>
                            <p class="mt-1 text-sm text-gray-900">{{ $snapshot['settings']['multi_step'] ? 'Yes' : 'No' }}</p>
                        </div>
                    </div>

                    @forelse ($snapshot['sections'] as $section)
                        <div class="rounded-lg border border-gray-200">
                            <div class="border-b border-gray-200 bg-gray-50 px-4 py-2.5">
                                <p class="text-sm font-semibold text-gray-900">{{ $section['title'] }}</p>
                                @if ($section['description'] !== '')
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $section['description'] }}</p>
                                @endif
                            </div>

                            @if ($section['fields'] === [])
                                <p class="px-4 py-3 text-sm text-gray-500">No fields in this section.</p>
                            @else
                                <ul class="divide-y divide-gray-100">
                                    @foreach ($section['fields'] as $field)
                                        <li class="px-4 py-3">
                                            <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900">
                                                {{ $field['label'] }}
                                                <span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-600">{{ $field['type'] }}</span>
                                                @if ($field['key'] !== '')
                                                    <span class="font-mono text-xs text-gray-400">{{ $field['key'] }}</span>
                                                @endif
                                                @if ($field['required'])
                                                    <span class="text-xs font-medium text-red-600">required</span>
                                                @endif
                                            </p>

                                            <dl class="mt-1 space-y-0.5 text-xs text-gray-500">
                                                @if ($field['placeholder'] !== '')
                                                    <div><dt class="inline">Placeholder:</dt> <dd class="inline">{{ $field['placeholder'] }}</dd></div>
                                                @endif
                                                @if ($field['help_text'] !== '')
                                                    <div><dt class="inline">Help:</dt> <dd class="inline">{{ $field['help_text'] }}</dd></div>
                                                @endif
                                                @if ($field['default'] !== '')
                                                    <div><dt class="inline">Default:</dt> <dd class="inline">{{ $field['default'] }}</dd></div>
                                                @endif
                                                @if ($field['options'] !== [])
                                                    <div>
                                                        <dt class="inline">Options:</dt>
                                                        <dd class="inline">
                                                            {{ collect($field['options'])->map(fn ($o) => $o['label'] !== '' ? $o['label'] : $o['value'])->implode(', ') }}
                                                        </dd>
                                                    </div>
                                                @endif
                                                @foreach ($field['validation'] as $rule)
                                                    <div><dt class="inline capitalize">{{ $rule['label'] }}:</dt> <dd class="inline">{{ $rule['value'] }}</dd></div>
                                                @endforeach
                                                @if ($field['conditions'] > 0)
                                                    <div><dt class="inline">Conditions:</dt> <dd class="inline">{{ $field['conditions'] }}</dd></div>
                                                @endif
                                            </dl>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">This version has no sections.</p>
                    @endforelse
                </div>
            </section>
        @endif

        @if ($this->comparison)
            @php $comparison = $this->comparison; $diff = $comparison['diff']; @endphp

            <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">
                            Version {{ $comparison['version'] }} compared with current
                        </h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Changes are described as they happened since version {{ $comparison['version'] }}.
                        </p>
                    </div>

                    <button type="button" wire:click="closePanel"
                            class="text-sm font-medium text-gray-500 transition hover:text-gray-700">Close</button>
                </div>

                <div class="space-y-5 px-6 py-5">
                    @if (! $diff['summary']['has_changes'])
                        <p class="text-sm text-gray-600">This version is identical to the current schema.</p>
                    @else
                        <p class="text-xs text-gray-500">
                            Sections: {{ $diff['summary']['sections_added'] }} added,
                            {{ $diff['summary']['sections_removed'] }} removed,
                            {{ $diff['summary']['sections_changed'] }} changed &middot;
                            Fields: {{ $diff['summary']['fields_added'] }} added,
                            {{ $diff['summary']['fields_removed'] }} removed,
                            {{ $diff['summary']['fields_changed'] }} changed
                        </p>

                        @foreach (['title' => 'Title', 'description' => 'Description'] as $property => $label)
                            @if ($diff[$property]['changed'])
                                <div class="rounded-md bg-amber-50 px-4 py-2.5 text-sm ring-1 ring-inset ring-amber-200">
                                    <span class="font-medium text-amber-900">{{ $label }}</span>
                                    <span class="text-amber-800">
                                        changed from &ldquo;{{ $diff[$property]['from'] }}&rdquo;
                                        to &ldquo;{{ $diff[$property]['to'] }}&rdquo;
                                    </span>
                                </div>
                            @endif
                        @endforeach

                        @foreach ($diff['sections'] as $section)
                            <div class="rounded-lg border border-gray-200">
                                <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2.5">
                                    <p class="text-sm font-semibold text-gray-900">{{ $section['title'] }}</p>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset {{ $toneFor($section['status']) }}">
                                        {{ $section['status'] }}
                                    </span>
                                </div>

                                @if ($section['changes'] !== [])
                                    <ul class="border-b border-gray-100 px-4 py-2 text-xs text-gray-600">
                                        @foreach ($section['changes'] as $property => $change)
                                            <li>
                                                <span class="capitalize">{{ str_replace('_', ' ', $property) }}</span>:
                                                &ldquo;{{ $change['from'] }}&rdquo; &rarr; &ldquo;{{ $change['to'] }}&rdquo;
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($section['fields'] === [])
                                    <p class="px-4 py-3 text-sm text-gray-500">No fields.</p>
                                @else
                                    <ul class="divide-y divide-gray-100">
                                        @foreach ($section['fields'] as $field)
                                            <li class="px-4 py-2.5">
                                                <p class="flex flex-wrap items-center gap-2 text-sm text-gray-900">
                                                    <span class="font-medium">{{ $field['label'] }}</span>
                                                    @if ($field['key'] !== '')
                                                        <span class="font-mono text-xs text-gray-400">{{ $field['key'] }}</span>
                                                    @endif
                                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset {{ $toneFor($field['status']) }}">
                                                        {{ $field['status'] }}
                                                    </span>
                                                </p>

                                                @if ($field['changes'] !== [])
                                                    <ul class="mt-1 space-y-0.5 text-xs text-gray-600">
                                                        @foreach ($field['changes'] as $property => $change)
                                                            <li>
                                                                <span class="capitalize">{{ str_replace('_', ' ', $property) }}</span>:
                                                                <span class="text-red-700">{{ $this->preview($change['from']) }}</span>
                                                                &rarr;
                                                                <span class="text-green-700">{{ $this->preview($change['to']) }}</span>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    @endif
                </div>
            </section>
        @endif
    </div>
</div>

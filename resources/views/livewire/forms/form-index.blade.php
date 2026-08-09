<div class="py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-gray-900">Your Forms</h2>

                <div class="flex items-center gap-4">
                    <span class="text-sm text-gray-500">{{ $forms->count() }} {{ Str::plural('form', $forms->count()) }}</span>

                    <a href="{{ route('forms.create') }}"
                       wire:navigate
                       class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-500">
                        Create Form
                    </a>
                </div>
            </div>

            @if ($forms->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="text-sm font-medium text-gray-900">No forms yet</p>
                    <p class="mt-1 text-sm text-gray-500">
                        Every form you create will be listed here, ready to open in the builder.
                    </p>

                    <a href="{{ route('forms.create') }}"
                       wire:navigate
                       class="mt-4 inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-500">
                        Create your first form
                    </a>
                </div>
            @else
                <ul class="divide-y divide-gray-200">
                    @foreach ($forms as $form)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900">{{ $form->title }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    Updated {{ $form->updated_at?->diffForHumans() }}
                                </p>

                                @if ($form->status === \App\Enums\FormStatus::Published)
                                    {{-- The public token, never the slug: it is the only identifier
                                         meant to leave the dashboard. --}}
                                    <a href="{{ route('forms.public', $form->public_token) }}"
                                       target="_blank" rel="noopener"
                                       class="mt-1 inline-block max-w-full truncate text-xs font-medium text-emerald-700 underline-offset-2 hover:underline">
                                        {{ route('forms.public', $form->public_token) }}
                                    </a>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize',
                                    'bg-emerald-100 text-emerald-800' => $form->status === \App\Enums\FormStatus::Published,
                                    'bg-gray-100 text-gray-700' => $form->status !== \App\Enums\FormStatus::Published,
                                ])>
                                    {{ $form->status->value }}
                                </span>

                                <a href="{{ route('forms.versions', $form) }}"
                                   wire:navigate
                                   class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                    Versions
                                </a>

                                <a href="{{ route('forms.submissions.index', $form) }}"
                                   wire:navigate
                                   class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                    Submissions
                                </a>

                                {{-- A plain link so the browser handles the streamed download. --}}
                                <a href="{{ route('forms.submissions.export', $form) }}"
                                   class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                    Export CSV
                                </a>

                                <a href="{{ route('forms.builder', $form) }}"
                                   class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-500">
                                    Open Builder
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

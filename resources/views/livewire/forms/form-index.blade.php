<div class="py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-gray-900">Your Forms</h2>
                <span class="text-sm text-gray-500">{{ $forms->count() }} {{ Str::plural('form', $forms->count()) }}</span>
            </div>

            @if ($forms->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="text-sm font-medium text-gray-900">No forms yet</p>
                    <p class="mt-1 text-sm text-gray-500">
                        Every form you create will be listed here, ready to open in the builder.
                    </p>
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
                            </div>

                            <div class="flex items-center gap-3">
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium capitalize text-gray-700">
                                    {{ $form->status->value }}
                                </span>

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

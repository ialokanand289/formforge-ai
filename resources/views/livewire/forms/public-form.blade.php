@php
    $document = $this->document;
@endphp

<div>
    @if ($document['unavailable'])
        <div class="rounded-xl border border-gray-200 bg-white p-8 text-center shadow-sm">
            <h1 class="text-lg font-semibold text-gray-900">This form is not available right now</h1>
            <p class="mt-2 text-sm text-gray-600">
                Please check back later, or contact whoever shared this link with you.
            </p>
        </div>
    @else
        <header class="mb-6">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $document['title'] }}</h1>

            @if ($document['description'] !== '')
                <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $document['description'] }}</p>
            @endif
        </header>

        @if ($submitted)
            <div role="status"
                 class="rounded-xl border border-emerald-200 bg-emerald-50 p-8 text-center shadow-sm">
                <h2 class="text-base font-semibold text-emerald-900">{{ $successMessage }}</h2>
                <p class="mt-2 text-sm text-emerald-800">You can close this page now.</p>
            </div>
        @elseif ($document['hasFields'])
            @if ($submitError)
                <div role="alert"
                     class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
                    {{ $submitError }}
                </div>
            @endif

            <form wire:submit="submit">
                <div class="space-y-6">
                    @foreach ($document['sections'] as $section)
                        <x-form::section :section="$section" wire:key="section-{{ $loop->index }}" />
                    @endforeach
                </div>

                <x-form::submit :label="$document['submitLabel']" />
            </form>
        @else
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center">
                <p class="text-sm font-medium text-gray-900">This form has no questions yet</p>
                <p class="mt-1 text-sm text-gray-600">There is nothing to fill in at the moment.</p>
            </div>
        @endif
    @endif
</div>

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

        @if ($document['hasFields'])
            <div class="space-y-6">
                @foreach ($document['sections'] as $section)
                    <x-form::section :section="$section" wire:key="section-{{ $loop->index }}" />
                @endforeach
            </div>

            <x-form::submit :label="$document['submitLabel']" />
        @else
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center">
                <p class="text-sm font-medium text-gray-900">This form has no questions yet</p>
                <p class="mt-1 text-sm text-gray-600">There is nothing to fill in at the moment.</p>
            </div>
        @endif
    @endif
</div>

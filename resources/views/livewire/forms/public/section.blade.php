@props([
    'section',
])

<section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
    <h2 class="text-base font-semibold text-gray-900">{{ $section['title'] }}</h2>

    @if (($section['description'] ?? null) !== null)
        <p class="mt-1 text-sm text-gray-600">{{ $section['description'] }}</p>
    @endif

    @if ($section['fields'] !== [])
        <div class="mt-5 space-y-5">
            @foreach ($section['fields'] as $field)
                <x-form::field :field="$field" wire:key="field-{{ $field['id'] }}" />
            @endforeach
        </div>
    @endif
</section>

@props([
    'label' => 'Submit',
])

{{-- Presentation only: submission handling arrives in a later phase. --}}
<div class="mt-6">
    <button type="button"
            disabled
            title="Submissions are not accepted yet"
            class="inline-flex w-full cursor-not-allowed items-center justify-center rounded-lg bg-indigo-600/60 px-4 py-2.5 text-sm font-semibold text-white sm:w-auto">
        {{ $label }}
    </button>
</div>

@props([
    'label' => 'Submit',
])

<div class="mt-6">
    <button type="submit"
            wire:target="submit"
            wire:loading.attr="disabled"
            class="inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-70 sm:w-auto">
        <span wire:target="submit" wire:loading.remove>{{ $label }}</span>
        <span wire:target="submit" wire:loading>Sending...</span>
    </button>
</div>

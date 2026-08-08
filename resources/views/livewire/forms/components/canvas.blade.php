@props([
    'sections' => [],
])

<main class="flex min-w-0 flex-1 flex-col bg-gray-100"
      x-data="{
          draggingId: null,
          overId: null,
          overEdge: null,
          overSectionId: null,
          reset() {
              this.draggingId = null;
              this.overId = null;
              this.overEdge = null;
              this.overSectionId = null;
          },
      }">
    <div class="flex-1 overflow-y-auto p-4 sm:p-6">
        <div class="mx-auto max-w-3xl space-y-4">
            @forelse ($sections as $section)
                <x-builder::section-card
                    :section="$section"
                    wire:key="section-{{ $section['id'] }}" />
            @empty
                <div class="flex min-h-[24rem] items-center justify-center rounded-xl border-2 border-dashed border-gray-300 bg-white p-8">
                    <div class="text-center">
                        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                        </span>

                        <h2 class="mt-4 text-base font-semibold text-gray-900">Start building your form</h2>
                        <p class="mt-1 text-sm text-gray-500">Select a field from the left to see it here.</p>
                    </div>
                </div>
            @endforelse

            <button type="button"
                    wire:click="addSection"
                    class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 bg-white px-4 py-3 text-sm font-medium text-gray-600 transition hover:border-indigo-300 hover:text-indigo-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Add Section
            </button>
        </div>
    </div>
</main>

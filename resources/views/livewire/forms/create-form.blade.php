<div class="py-12">
    <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
        <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-gray-900">Create a form</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Give it a name to get started. You can change everything later in the builder.
                </p>
            </div>

            @if ($createError)
                <div class="border-b border-red-200 bg-red-50 px-6 py-3" role="alert">
                    <p class="text-sm text-red-800">{{ $createError }}</p>
                </div>
            @endif

            <form wire:submit="create" class="space-y-6 px-6 py-6">
                <div>
                    <label for="form-title" class="block text-sm font-medium text-gray-700">Title</label>
                    <input id="form-title"
                           type="text"
                           wire:model="title"
                           autofocus
                           maxlength="255"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                           placeholder="Employee Registration" />
                    @error('title')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="form-description" class="block text-sm font-medium text-gray-700">
                        Description <span class="font-normal text-gray-400">(optional)</span>
                    </label>
                    <textarea id="form-description"
                              wire:model="description"
                              rows="3"
                              maxlength="2000"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                              placeholder="Shown to people filling in the form."></textarea>
                    @error('description')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-gray-200 pt-6">
                    <a href="{{ route('forms.index') }}"
                       wire:navigate
                       class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                        Cancel
                    </a>

                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:target="create"
                            class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                        <span wire:loading.remove wire:target="create">Create Form</span>
                        <span wire:loading wire:target="create">Creating...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

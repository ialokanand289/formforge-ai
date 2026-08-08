@props([
    'editor' => null,
    'form' => [],
])

<aside class="flex w-full shrink-0 flex-col border-t border-gray-200 bg-white lg:h-full lg:w-80 lg:border-l lg:border-t-0"
       aria-labelledby="properties-panel-heading">
    <div class="shrink-0 border-b border-gray-200 px-4 py-3">
        <h2 id="properties-panel-heading" class="text-sm font-semibold text-gray-900">Properties</h2>
        <p class="mt-0.5 text-xs text-gray-500">
            {{ $editor ? $editor['typeLabel'].' field' : 'Field settings' }}
        </p>
    </div>

    <div class="flex-1 overflow-y-auto p-4">
        @if ($editor === null)
            <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-6 text-center">
                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-white text-gray-400 ring-1 ring-gray-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.398.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.107-1.204l-.527-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                </span>

                <p class="mt-3 text-sm font-medium text-gray-900">No field selected</p>
                <p class="mt-1 text-xs text-gray-500">
                    Choose a field on the canvas to edit its label, validation, and options.
                </p>
            </div>
        @else
            <div class="space-y-3">
                <x-builder::property-card title="General" :open="true">
                    <x-builder::property-input
                        :label="$editor['isHeading'] ? 'Heading Text' : 'Label'"
                        model="fieldForm.label"
                        :value="$form['label'] ?? ''"
                        :maxlength="120" />

                    @if ($editor['showKey'])
                        <x-builder::property-input
                            label="Key"
                            model="fieldForm.key"
                            :value="$form['key'] ?? ''"
                            hint="Lowercase letters, numbers, and underscores." />
                    @endif

                    @if ($editor['showPlaceholder'])
                        <x-builder::property-input
                            label="Placeholder"
                            model="fieldForm.placeholder"
                            :value="$form['placeholder'] ?? ''"
                            :maxlength="120" />
                    @endif

                    @if ($editor['showHelpText'])
                        <x-builder::property-input
                            label="Help Text"
                            model="fieldForm.help_text"
                            type="textarea"
                            :value="$form['help_text'] ?? ''"
                            :maxlength="250" />
                    @endif

                    @if ($editor['showDefault'])
                        <x-builder::property-input
                            label="Default Value"
                            model="fieldForm.default"
                            :value="$form['default'] ?? ''" />
                    @endif
                </x-builder::property-card>

                @if ($editor['showValidation'])
                    <x-builder::property-card title="Validation">
                        <x-builder::property-toggle
                            label="Required"
                            model="fieldForm.required"
                            hint="Respondents must complete this field." />

                        @if ($editor['showLengthRules'])
                            <div class="grid grid-cols-2 gap-2">
                                <x-builder::property-input label="Min Length" model="fieldForm.min_length" type="number" min="0" />
                                <x-builder::property-input label="Max Length" model="fieldForm.max_length" type="number" min="0" />
                            </div>
                        @endif

                        @if ($editor['showNumberRange'])
                            <div class="grid grid-cols-2 gap-2">
                                <x-builder::property-input label="Min" model="fieldForm.min" type="number" min="0" />
                                <x-builder::property-input label="Max" model="fieldForm.max" type="number" min="0" />
                            </div>
                        @endif

                        @if ($editor['showRatingRange'])
                            <div class="grid grid-cols-2 gap-2">
                                <x-builder::property-input label="Minimum Rating" model="fieldForm.min" type="number" min="0" />
                                <x-builder::property-input label="Maximum Rating" model="fieldForm.max" type="number" min="0" />
                            </div>
                        @endif

                        @if ($editor['showFileSize'])
                            <x-builder::property-input
                                label="Maximum File Size (KB)"
                                model="fieldForm.max_file_size_kb"
                                type="number"
                                min="0" />
                        @endif
                    </x-builder::property-card>
                @endif

                @if ($editor['showOptions'])
                    <x-builder::property-card title="Options ({{ $editor['optionCount'] }})">
                        @error('fieldForm.options')
                            <p class="text-[11px] font-medium text-red-600" role="alert">{{ $message }}</p>
                        @enderror

                        @foreach ($form['options'] ?? [] as $index => $option)
                            <x-builder::option-row
                                :index="$index"
                                :is-first="$index === 0"
                                :is-last="$index === count($form['options']) - 1"
                                wire:key="option-{{ $index }}" />
                        @endforeach

                        <button type="button"
                                wire:click="addOption"
                                wire:target="addOption"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-60"
                                class="flex w-full items-center justify-center gap-1.5 rounded-md border border-dashed border-gray-300 px-3 py-2 text-xs font-medium text-gray-600 transition hover:border-indigo-300 hover:text-indigo-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:cursor-wait">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Add Option
                        </button>
                    </x-builder::property-card>
                @endif

                @if ($editor['showAdvanced'])
                    <x-builder::property-card title="Advanced">
                        @if ($editor['showRegex'])
                            <x-builder::property-input
                                label="Regex"
                                model="fieldForm.regex"
                                placeholder="/^[A-Z]{2}\d+$/"
                                hint="Leave blank for no pattern." />
                        @endif

                        @if ($editor['showFileTypes'])
                            <x-builder::property-input
                                label="Allowed Types"
                                model="fieldForm.file_types"
                                placeholder="pdf, docx, png"
                                hint="Comma separated extensions." />
                        @endif
                    </x-builder::property-card>
                @endif
            </div>
        @endif
    </div>
</aside>

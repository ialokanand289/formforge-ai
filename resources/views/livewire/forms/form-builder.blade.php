<div class="flex h-full min-h-0 flex-col">
    <x-builder::toolbar :title="$title" :status="$status" :back-url="route('forms.index')" />

    <div class="flex min-h-0 flex-1 flex-col overflow-y-auto lg:flex-row lg:overflow-hidden">
        <x-builder::field-palette :fields="$paletteFields" />

        <x-builder::canvas />

        <x-builder::properties-panel />
    </div>

    <x-builder::json-preview :json="$schemaJson" />
</div>

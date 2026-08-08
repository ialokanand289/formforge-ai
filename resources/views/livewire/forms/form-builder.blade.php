<div class="flex h-full min-h-0 flex-col">
    <x-builder::toolbar :title="$title" :status="$status" :back-url="route('forms.index')" />

    <x-builder::schema-alert :message="$schemaError" />

    <div class="flex min-h-0 flex-1 flex-col overflow-y-auto lg:flex-row lg:overflow-hidden">
        <x-builder::field-palette :fields="$paletteFields" />

        <x-builder::canvas :sections="$this->sections" />

        <x-builder::properties-panel :editor="$this->fieldEditor" :form="$fieldForm" />
    </div>

    <x-builder::json-preview :json="$this->schemaJson" />
</div>

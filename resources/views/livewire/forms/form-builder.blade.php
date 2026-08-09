<div class="flex h-full min-h-0 flex-col"
     x-data="{
         isEditable(el) {
             if (typeof el?.closest !== 'function') return false;

             return el.closest('input, textarea, select, [contenteditable=&quot;true&quot;]') !== null;
         },
     }"
     x-on:keydown.window="
         if (isEditable($event.target)) return;

         if ($event.key === 'Delete' || $event.key === 'Backspace') {
             if (! $wire.selectedFieldId) return;
             $event.preventDefault();
             $wire.deleteSelectedField();
         }

         if ($event.key === 'Escape') {
             $wire.deselect();
         }
     ">
    <x-builder::toolbar
        :title="$title"
        :status="$status"
        :unsaved="$dirty"
        :saved="$saveMessage"
        :import-label="$this->importLabel()"
        :back-url="route('forms.index')"
        :versions-url="route('forms.versions', $form)"
        :submissions-url="route('forms.submissions.index', $form)"
        :public-url="$this->publicUrl" />

    <x-builder::schema-alert :message="$schemaError" />

    <x-builder::ai-panel
        :open="$aiPanelOpen"
        :mode="$aiMode"
        :running="$this->aiRunning"
        :status="$aiStatus"
        :notice="$aiMessage"
        :error="$aiError"
        :max-chars="$this->maxPromptChars()" />

    <x-builder::import-panel
        :open="$showImport"
        :running="$this->importRunning"
        :status="$importStatus"
        :preview="$importPreview"
        :filename="$importFilename"
        :source="$importSource"
        :notice="$importMessage"
        :error="$importError"
        :max-kb="$this->maxImportKb()" />

    <div class="flex min-h-0 flex-1 flex-col overflow-y-auto lg:flex-row lg:overflow-hidden">
        <x-builder::field-palette :fields="$paletteFields" />

        <x-builder::canvas :sections="$this->sections" />

        <x-builder::properties-panel :editor="$this->fieldEditor" :form="$fieldForm" />
    </div>

    <x-builder::json-preview :json="$schemaDraft" :error="$jsonError" :message="$jsonMessage" />
</div>

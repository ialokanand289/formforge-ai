<?php

namespace App\Livewire\Forms;

use App\Enums\FieldType;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Jobs\ProcessAiGenerationJob;
use App\Jobs\ProcessImportJob;
use App\Models\AiGenerationLog;
use App\Models\Form;
use App\Models\ImportJob;
use App\Services\AiService;
use App\Services\ImportArchiveGuard;
use App\Services\SchemaCandidateGate;
use App\Services\SchemaService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

class FormBuilder extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public Form $form;

    public string $title = '';

    public string $status = '';

    /**
     * In-memory working schema. Never persisted in this phase.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $schema = [];

    public ?string $selectedSectionId = null;

    public ?string $selectedFieldId = null;

    public ?string $schemaError = null;

    /**
     * Palette metadata, prepared here so Blade stays presentation only.
     *
     * @var list<array{type: string, label: string, description: string, icon: string}>
     */
    public array $paletteFields = [];

    /**
     * Editable mirror of the selected field, bound to the property editor.
     *
     * @var array<string, mixed>
     */
    public array $fieldForm = [];

    /**
     * Reserved for Phase 4D undo support. Not used in this phase.
     *
     * @var list<array<string, mixed>>
     */
    protected array $history = [];

    /**
     * True once the in-memory schema has diverged from its initial state.
     *
     * Server-controlled: commit() is the only writer, and only after the
     * schema validates. Locked so a request payload cannot set it, never
     * bound with wire:model, and never taken as an action argument. There is
     * no reset path because persistence is out of scope.
     */
    #[Locked]
    public bool $dirty = false;

    /**
     * Transient success feedback. Server-set only, cleared by the next mutation.
     */
    #[Locked]
    public ?string $saveMessage = null;

    public bool $aiPanelOpen = false;

    /**
     * Either "generate" or "edit". Client-bound, so runAi() re-derives the
     * GenerationType rather than trusting the string.
     */
    public string $aiMode = 'generate';

    public string $aiPrompt = '';

    /**
     * The in-flight AiGenerationLog. Locked so a forged payload cannot point
     * the poller at a log this user does not own; pollAi() re-scopes the query
     * to the signed-in user regardless.
     */
    #[Locked]
    public ?string $aiLogId = null;

    #[Locked]
    public ?string $aiStatus = null;

    #[Locked]
    public ?string $aiError = null;

    #[Locked]
    public ?string $aiMessage = null;

    public bool $showImport = false;

    /**
     * The pending upload. Livewire keeps this on the temporary upload disk,
     * which resolves to the private local disk, until startImport() moves it.
     */
    public mixed $importFile = null;

    /**
     * The tracked ImportJob. Locked so a forged payload cannot point the poller
     * or acceptImport() at a job this user does not own; every read re-scopes
     * to the signed-in user and this form regardless.
     */
    #[Locked]
    public ?string $importJobId = null;

    #[Locked]
    public ?string $importStatus = null;

    #[Locked]
    public ?string $importError = null;

    /**
     * Display-only summary of what the import found. Never the schema itself.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $importPreview = null;

    #[Locked]
    public ?string $importFilename = null;

    #[Locked]
    public ?string $importSource = null;

    #[Locked]
    public ?string $importMessage = null;

    public function mount(Form $form, SchemaService $schemaService): void
    {
        $this->authorize('view', $form);

        $this->form = $form;
        $this->title = $form->title;
        $this->status = $form->status->value;
        $this->schema = $schemaService->load($form);
        $this->paletteFields = $this->buildPalette();
        $this->selectedSectionId = $this->schema['sections'][0]['id'] ?? null;
        $this->loadFieldForm();
    }

    public function addSection(): void
    {
        $before = $this->sectionIds();

        $this->commit($this->schemaService()->addSection($this->schema));

        $added = array_values(array_diff($this->sectionIds(), $before));

        if ($added !== []) {
            $this->selectedSectionId = $added[0];
            $this->selectedFieldId = null;
        }

        $this->loadFieldForm();
    }

    public function removeSection(string $sectionId): void
    {
        $this->commit($this->schemaService()->removeSection($this->schema, $sectionId));
        $this->clearSelection();
        $this->loadFieldForm();
    }

    public function addField(string $type, ?string $sectionId = null): void
    {
        $fieldType = FieldType::tryFrom($type);

        if ($fieldType === null) {
            $this->schemaError = 'That field type is not supported.';

            return;
        }

        $targetId = $this->resolveTargetSection($sectionId);

        if ($targetId === null) {
            return;
        }

        $before = $this->fieldIds();

        $this->commit($this->schemaService()->addField(
            $this->schema,
            $targetId,
            $fieldType,
            $this->defaultAttributesFor($fieldType)
        ));

        $added = array_values(array_diff($this->fieldIds(), $before));

        if ($added !== []) {
            $this->selectedSectionId = $targetId;
            $this->selectedFieldId = $added[0];
        }

        $this->loadFieldForm();
    }

    public function removeField(string $fieldId): void
    {
        $this->commit($this->schemaService()->removeField($this->schema, $fieldId));
        $this->clearSelection();
        $this->loadFieldForm();
    }

    public function duplicateField(string $fieldId): void
    {
        $before = $this->fieldIds();

        $this->commit($this->schemaService()->duplicateField($this->schema, $fieldId));

        $added = array_values(array_diff($this->fieldIds(), $before));

        if ($added !== []) {
            $this->selectedFieldId = $added[0];
        }

        $this->loadFieldForm();
    }

    /**
     * Drag and drop entry point. Position is the drop index reported by the DOM.
     */
    public function moveField(string $fieldId, string $toSectionId, int $position): void
    {
        $this->moveFieldToPosition($fieldId, $toSectionId, $position);
    }

    public function moveFieldUp(string $fieldId): void
    {
        $located = $this->locateField($fieldId);

        if ($located === null || $located['index'] === 0) {
            return;
        }

        $this->moveFieldToPosition($fieldId, $located['sectionId'], $located['index'] - 1);
    }

    public function moveFieldDown(string $fieldId): void
    {
        $located = $this->locateField($fieldId);

        if ($located === null || $located['index'] === $located['lastIndex']) {
            return;
        }

        // Insert before the field two slots down; the same-section adjustment
        // in moveFieldToPosition() turns this into index + 1.
        $this->moveFieldToPosition($fieldId, $located['sectionId'], $located['index'] + 2);
    }

    public function selectSection(string $sectionId): void
    {
        $this->selectedSectionId = $sectionId;
        $this->selectedFieldId = null;
        $this->loadFieldForm();
    }

    public function selectField(string $fieldId): void
    {
        $located = $this->locateField($fieldId);

        if ($located === null) {
            return;
        }

        $this->selectedSectionId = $located['sectionId'];
        $this->selectedFieldId = $fieldId;
        $this->loadFieldForm($located['field']);
    }

    /**
     * Delete shortcut target. Takes no id so the client cannot name a victim.
     */
    public function deleteSelectedField(): void
    {
        if ($this->selectedFieldId === null) {
            return;
        }

        $this->removeField($this->selectedFieldId);
    }

    public function deselect(): void
    {
        $this->selectedFieldId = null;
        $this->selectedSectionId = null;
        $this->loadFieldForm();
    }

    /**
     * Persist the working schema through the single persistence entry point.
     */
    public function save(): void
    {
        $this->authorize('update', $this->form);

        // Nothing changed, so there is nothing to version.
        if (! $this->dirty) {
            return;
        }

        try {
            $form = $this->schemaService()->save($this->form, $this->schema, Auth::user());
        } catch (ValidationException $exception) {
            $this->schemaError = 'Save was rejected: '
                .(collect($exception->errors())->flatten()->first() ?? 'the schema is invalid.');

            return;
        } catch (Throwable $exception) {
            Log::error('Failed to save form schema.', [
                'form_id' => $this->form->id,
                'exception' => $exception,
            ]);

            // The rolled-back write left mutated attributes on the instance.
            try {
                $this->form->refresh();
            } catch (Throwable) {
                // The database is unreachable; the stale instance is the lesser problem.
            }

            $this->schemaError = 'Your changes could not be saved. Please try again.';

            return;
        }

        $selectedFieldId = $this->selectedFieldId;
        $selectedSectionId = $this->selectedSectionId;

        $this->form = $form;
        $this->title = $form->title;
        $this->status = $form->status->value;
        $this->schema = $this->schemaService()->load($form);
        $this->dirty = false;
        $this->schemaError = null;
        $this->saveMessage = 'Form saved successfully.';

        $this->restoreSelection($selectedFieldId, $selectedSectionId);

        unset($this->schemaJson, $this->sections, $this->fieldEditor);
    }

    public function dismissSchemaError(): void
    {
        $this->schemaError = null;
    }

    public function toggleAiPanel(): void
    {
        $this->aiPanelOpen = ! $this->aiPanelOpen;
    }

    public function dismissAiError(): void
    {
        $this->aiError = null;
    }

    /**
     * Queue an AI generation or edit for this form.
     *
     * Nothing here talks to the provider: the request is recorded and handed to
     * a queued job, so the browser never waits on a model.
     */
    public function runAi(AiService $ai): void
    {
        $this->authorize('update', $this->form);

        $this->aiError = null;
        $this->aiMessage = null;

        if (! $ai->isConfigured()) {
            $this->aiError = 'AI is not configured on this server.';

            return;
        }

        // Generation replaces the whole schema, so unsaved work would vanish.
        if ($this->dirty) {
            $this->aiError = 'Save or discard your unsaved changes before running AI.';

            return;
        }

        if ($this->aiInFlight()) {
            $this->aiError = 'An AI request is already running for this form.';

            return;
        }

        $this->validate([
            'aiPrompt' => ['required', 'string', 'min:3', 'max:'.$this->maxPromptChars()],
        ], [], ['aiPrompt' => 'instruction']);

        $log = AiGenerationLog::query()->create([
            // Never from the request: the owner is whoever is signed in.
            'user_id' => Auth::id(),
            'form_id' => $this->form->id,
            'type' => $this->aiMode === 'edit' ? GenerationType::Edit : GenerationType::Generate,
            'prompt' => trim($this->aiPrompt),
            'status' => GenerationStatus::Queued,
        ]);

        $this->aiLogId = $log->id;

        ProcessAiGenerationJob::dispatch($log->id)
            ->onQueue(config('formforge.queue.ai'));

        // Read the row back rather than assuming "queued": on a sync queue the
        // job has already finished by the time dispatch() returns.
        $this->refreshAiState();
    }

    /**
     * Polled by the panel while a request is in flight.
     */
    public function pollAi(): void
    {
        $this->refreshAiState();
    }

    #[Computed]
    public function aiRunning(): bool
    {
        return in_array($this->aiStatus, [
            GenerationStatus::Queued->value,
            GenerationStatus::Processing->value,
        ], true);
    }

    public function maxPromptChars(): int
    {
        return max(1, (int) config('formforge.ai.max_prompt_chars', 2000));
    }

    /**
     * Read the tracked log and react when it reaches a terminal state.
     */
    protected function refreshAiState(): void
    {
        if ($this->aiLogId === null) {
            return;
        }

        // Scoped to the signed-in user and this form, so even a forged id can
        // only ever read a log the viewer already owns.
        $log = AiGenerationLog::query()
            ->whereKey($this->aiLogId)
            ->where('user_id', Auth::id())
            ->where('form_id', $this->form->id)
            ->first();

        if ($log === null) {
            $this->stopTracking(null);

            return;
        }

        $this->aiStatus = $log->status->value;

        if ($log->status === GenerationStatus::Completed) {
            $this->applyAiResult();
            $this->stopTracking(GenerationStatus::Completed->value);

            return;
        }

        if ($log->status === GenerationStatus::Failed) {
            $this->aiError = $log->error_message ?: 'The AI request could not be completed.';
            $this->stopTracking(GenerationStatus::Failed->value);
        }
    }

    /**
     * Drop the tracked log so the panel stops polling.
     */
    protected function stopTracking(?string $status): void
    {
        $this->aiLogId = null;
        $this->aiStatus = $status;
    }

    /**
     * Pull the AI's saved schema back out of the database.
     *
     * The job persisted through SchemaService, so this re-reads rather than
     * trusting anything carried over from the request that started it.
     */
    protected function applyAiResult(): void
    {
        $before = $this->fieldKeys();

        $this->form->refresh();
        $this->title = $this->form->title;
        $this->status = $this->form->status->value;
        $this->schema = $this->schemaService()->load($this->form);
        $this->dirty = false;
        $this->schemaError = null;
        $this->saveMessage = null;
        $this->aiPrompt = '';

        $this->clearSelection();
        $this->loadFieldForm();

        unset($this->schemaJson, $this->sections, $this->fieldEditor, $this->aiRunning);

        $this->aiMessage = $this->summarizeKeyChanges($before, $this->fieldKeys());
    }

    protected function aiInFlight(): bool
    {
        return AiGenerationLog::query()
            ->where('form_id', $this->form->id)
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->exists();
    }

    /**
     * @return list<string>
     */
    protected function fieldKeys(): array
    {
        $keys = [];

        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $keys[] = (string) ($field['key'] ?? '');
            }
        }

        return $keys;
    }

    /**
     * Name what the edit added and removed.
     *
     * A removal is legitimate but must never be silent, because answers already
     * filed under a removed key stop appearing beside the live fields.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    protected function summarizeKeyChanges(array $before, array $after): string
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        $parts = ['The form was updated by AI.'];

        if ($added !== []) {
            $parts[] = Str::plural('Field', $added).' added: '.implode(', ', $added).'.';
        }

        if ($removed !== []) {
            $parts[] = Str::plural('Field', $removed).' removed: '.implode(', ', $removed).'.';
        }

        return implode(' ', $parts);
    }

    public function toggleImport(): void
    {
        $this->showImport = ! $this->showImport;
    }

    public function dismissImportError(): void
    {
        $this->importError = null;
    }

    /**
     * Validate the upload, store it privately, and hand it to a queued job.
     *
     * Nothing is parsed here and nothing about the form changes: the browser
     * never waits on a document reader or a model.
     */
    public function startImport(AiService $ai, ImportArchiveGuard $guard): void
    {
        $this->authorize('update', $this->form);

        $this->importError = null;
        $this->importMessage = null;

        if (! $ai->isConfigured()) {
            $this->importError = 'AI is not configured on this server, so documents cannot be imported.';

            return;
        }

        // An import replaces the whole schema, so unsaved work would vanish.
        if ($this->dirty) {
            $this->importError = 'Save or discard your unsaved changes before importing a document.';

            return;
        }

        if ($this->importInFlight()) {
            $this->importError = 'An import is already running for this form.';

            return;
        }

        $extensions = implode(',', (array) config('formforge.import.allowed_extensions', ['docx', 'xlsx']));

        $this->validate([
            'importFile' => [
                'required',
                'file',
                'extensions:'.$extensions,
                // Guesses from content, but both formats are ZIP containers and
                // some systems report application/zip for each, so this narrows
                // the field rather than settling it. The archive guard below is
                // what actually defeats a renamed file.
                'mimes:'.$extensions,
                'max:'.$this->maxImportKb(),
            ],
        ], [], ['importFile' => 'document']);

        $source = ImportSource::tryFrom(strtolower((string) $this->importFile->getClientOriginalExtension()));

        if ($source === null) {
            $this->importError = 'Only Word (.docx) and Excel (.xlsx) documents can be imported.';

            return;
        }

        $disk = null;
        $path = null;

        try {
            // Both the temporary upload and its destination must be private, so
            // a misconfigured environment fails loudly rather than quietly
            // publishing someone's document.
            $guard->assertPrivate((string) config('livewire.temporary_file_upload.disk') ?: config('filesystems.default'));

            $disk = $guard->disk();
            $path = $this->importFile->storeAs(
                $this->importDirectory(),
                // The client filename is never a path component. It survives
                // only as a label on the row.
                Str::ulid().'.'.$source->value,
                ['disk' => $disk],
            );

            // Inspected at its destination rather than at Livewire's temporary
            // path, which encodes the original filename and can exceed the
            // platform path limit that ZipArchive is subject to. This also
            // inspects the exact bytes the worker will later read.
            $guard->assertSafe(Storage::disk($disk)->path($path), $source);
        } catch (RuntimeException $exception) {
            $this->discardImportUpload($disk, $path);
            $this->importError = $exception->getMessage();

            return;
        } catch (Throwable $exception) {
            $this->discardImportUpload($disk, $path);

            Log::error('Failed to store an import upload.', [
                'form_id' => $this->form->id,
                'exception' => $exception,
            ]);

            $this->importError = 'That document could not be uploaded. Please try again.';

            return;
        }

        $job = ImportJob::query()->create([
            // Never from the request: the owner is whoever is signed in.
            'user_id' => Auth::id(),
            'form_id' => $this->form->id,
            'source' => $source,
            'original_filename' => Str::limit(
                (string) $this->importFile->getClientOriginalName(), 255, ''
            ),
            'disk_path' => $path,
            'status' => ImportStatus::Queued,
        ]);

        $this->importJobId = $job->id;
        $this->importFilename = $job->original_filename;
        $this->importSource = $source->value;
        $this->importPreview = null;
        $this->reset('importFile');

        ProcessImportJob::dispatch($job->id)
            ->onQueue(config('formforge.queue.import'));

        // Read the row back rather than assuming "queued": on a sync queue the
        // job has already finished by the time dispatch() returns.
        $this->refreshImportState();
    }

    /**
     * Polled by the panel while an import is in flight.
     */
    public function pollImport(): void
    {
        $this->refreshImportState();
    }

    /**
     * Apply a reviewed import. The only path from an import to forms.schema.
     */
    public function acceptImport(): void
    {
        $this->authorize('update', $this->form);

        $this->importError = null;

        // Edits made while the import was running would be replaced without a
        // word, so the same guard that blocks starting an import blocks
        // applying one.
        if ($this->dirty) {
            $this->importError = 'Save or discard your unsaved changes before applying this import.';

            return;
        }

        $job = $this->trackedImport();

        if ($job === null || $job->status !== ImportStatus::Preview) {
            $this->importError = 'That import is no longer available to apply.';

            return;
        }

        $schema = $job->final_schema;

        if (! is_array($schema) || $schema === []) {
            $this->importError = 'That import did not produce a schema to apply.';

            return;
        }

        // Re-run the gates on the way in. The row has been sitting in the
        // database since the worker wrote it, and it is not the browser's word
        // for it that we trust, nor our own from ten minutes ago.
        $errors = app(SchemaCandidateGate::class)->errorsFor($schema);

        if ($errors === []) {
            $errors = $this->schemaService()->validationErrors($schema);
        }

        if ($errors !== []) {
            $this->importError = 'That import is no longer valid: '
                .(collect($errors)->flatten()->first() ?? 'the schema was rejected.');

            return;
        }

        try {
            $form = $this->schemaService()->save(
                $this->form,
                $schema,
                Auth::user(),
                'Imported from '.$job->original_filename,
            );
        } catch (ValidationException $exception) {
            $this->importError = 'That import could not be applied: '
                .(collect($exception->errors())->flatten()->first() ?? 'the schema is invalid.');

            return;
        } catch (Throwable $exception) {
            Log::error('Failed to apply an imported schema.', [
                'form_id' => $this->form->id,
                'import_job_id' => $job->id,
                'exception' => $exception,
            ]);

            try {
                $this->form->refresh();
            } catch (Throwable) {
                // The database is unreachable; the stale instance is the lesser problem.
            }

            $this->importError = 'That import could not be applied. Please try again.';

            return;
        }

        $job->forceFill(['status' => ImportStatus::Committed])->save();

        $this->form = $form;
        $this->title = $form->title;
        $this->status = $form->status->value;
        $this->schema = $this->schemaService()->load($form);
        // Already persisted through SchemaService, so there is nothing pending.
        $this->dirty = false;
        $this->schemaError = null;
        $this->saveMessage = null;

        $this->clearSelection();
        $this->selectedSectionId = $this->schema['sections'][0]['id'] ?? null;
        $this->loadFieldForm();

        $this->importStatus = ImportStatus::Committed->value;
        $this->importMessage = 'Imported '.$job->original_filename.' successfully.';
        $this->importPreview = null;
        $this->importJobId = null;
        $this->showImport = false;

        unset($this->schemaJson, $this->sections, $this->fieldEditor, $this->importRunning);
    }

    /**
     * Stop tracking an import without ever rewriting its status.
     *
     * A queued or processing job may still be picked up by a worker that needs
     * its row and its file, so cancelling clears the panel and nothing else.
     * A preview left behind is inert: its file is already gone, importJobId is
     * locked and cleared, and mount() never re-attaches to an import.
     */
    public function cancelImport(): void
    {
        $this->importJobId = null;
        $this->importStatus = null;
        $this->importPreview = null;
        $this->importFilename = null;
        $this->importSource = null;
        $this->importError = null;
        $this->importMessage = null;
        $this->reset('importFile');
        $this->resetErrorBag('importFile');

        unset($this->importRunning);
    }

    #[Computed]
    public function importRunning(): bool
    {
        return in_array($this->importStatus, [
            ImportStatus::Queued->value,
            ImportStatus::Processing->value,
        ], true);
    }

    /**
     * The toolbar button doubles as the import's status line.
     */
    public function importLabel(): string
    {
        return match ($this->importStatus) {
            ImportStatus::Queued->value => 'Uploading...',
            ImportStatus::Processing->value => 'Importing...',
            ImportStatus::Preview->value => 'Review Import',
            ImportStatus::Committed->value => 'Imported successfully',
            ImportStatus::Failed->value => 'Import failed',
            default => 'Import',
        };
    }

    public function maxImportKb(): int
    {
        return max(1, (int) config('formforge.import.max_file_size_kb', 10240));
    }

    /**
     * Read the tracked import and react when it reaches a terminal state.
     */
    protected function refreshImportState(): void
    {
        $job = $this->trackedImport();

        if ($job === null) {
            $this->importStatus = null;
            $this->importJobId = null;

            unset($this->importRunning);

            return;
        }

        $this->importStatus = $job->status->value;
        $this->importFilename = $job->original_filename;
        $this->importSource = $job->source->value;

        if ($job->status === ImportStatus::Preview) {
            $this->importPreview = $job->preview;
        }

        if ($job->status === ImportStatus::Failed) {
            $this->importError = $job->errors['message'] ?? 'The document could not be imported.';
            $this->importPreview = null;
            $this->importJobId = null;
        }

        unset($this->importRunning);
    }

    /**
     * The tracked import, scoped so even a forged id can only ever read a job
     * the viewer already owns on the form they already have open.
     */
    protected function trackedImport(): ?ImportJob
    {
        if ($this->importJobId === null) {
            return null;
        }

        return ImportJob::query()
            ->whereKey($this->importJobId)
            ->where('user_id', Auth::id())
            ->where('form_id', $this->form->id)
            ->first();
    }

    protected function importInFlight(): bool
    {
        return ImportJob::query()
            ->where('form_id', $this->form->id)
            ->whereIn('status', [ImportStatus::Queued, ImportStatus::Processing])
            ->exists();
    }

    /**
     * Leave nothing behind when an upload is refused after it was written.
     */
    protected function discardImportUpload(?string $disk, ?string $path): void
    {
        if ($disk === null || $path === null) {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Cleanup is best effort; the refusal is what matters.
        }
    }

    protected function importDirectory(): string
    {
        return trim((string) config('formforge.uploads.import_dir', 'imports'), '/')
            .'/'.$this->form->id;
    }

    public function updatedFieldForm(mixed $value, string $name): void
    {
        $this->applyFieldUpdate();
    }

    public function addOption(): void
    {
        if (! $this->selectedFieldSupportsOptions()) {
            return;
        }

        $next = count($this->fieldForm['options'] ?? []) + 1;

        $this->fieldForm['options'][] = [
            'value' => 'option_'.$next,
            'label' => 'Option '.$next,
        ];

        $this->applyFieldUpdate();
    }

    public function removeOption(int $index): void
    {
        if (! array_key_exists($index, $this->fieldForm['options'] ?? [])) {
            return;
        }

        unset($this->fieldForm['options'][$index]);
        $this->fieldForm['options'] = array_values($this->fieldForm['options']);

        $this->applyFieldUpdate();
    }

    public function moveOptionUp(int $index): void
    {
        $this->moveOptionBy($index, -1);
    }

    public function moveOptionDown(int $index): void
    {
        $this->moveOptionBy($index, 1);
    }

    /**
     * Push the edited properties into the schema through SchemaService.
     */
    protected function applyFieldUpdate(): void
    {
        $field = $this->selectedField();

        if ($field === null) {
            return;
        }

        $type = FieldType::tryFrom($field['type']) ?? FieldType::Text;

        try {
            $this->validate($this->rulesForFieldForm($type), [], $this->fieldFormAttributeNames());
        } catch (ValidationException $exception) {
            $this->loadFieldForm();

            throw $exception;
        }

        $this->commit($this->schemaService()->updateField(
            $this->schema,
            $this->selectedFieldId,
            $this->attributesForType($type)
        ));

        $this->loadFieldForm();
    }

    protected function moveOptionBy(int $index, int $offset): void
    {
        $options = $this->fieldForm['options'] ?? [];
        $target = $index + $offset;

        if (! array_key_exists($index, $options) || ! array_key_exists($target, $options)) {
            return;
        }

        [$options[$index], $options[$target]] = [$options[$target], $options[$index]];

        $this->fieldForm['options'] = array_values($options);

        $this->applyFieldUpdate();
    }

    /**
     * Mirror the selected field into the editable form, or empty it.
     *
     * Callers that already hold the field can pass it to skip the lookup.
     *
     * @param  array<string, mixed>|null  $field
     */
    protected function loadFieldForm(?array $field = null): void
    {
        unset($this->fieldEditor);

        $field ??= $this->selectedField();

        if ($field === null) {
            $this->fieldForm = [];
            $this->resetErrorBag();

            return;
        }

        $validation = $field['validation'] ?? [];

        $this->fieldForm = [
            'label' => $field['label'],
            'key' => $field['key'],
            'placeholder' => $field['placeholder'] ?? '',
            'help_text' => $field['help_text'] ?? '',
            'default' => $field['default'] === null ? '' : (string) $field['default'],
            'required' => (bool) ($field['required'] ?? false),
            'min' => $this->formNumber($validation['min'] ?? null),
            'max' => $this->formNumber($validation['max'] ?? null),
            'min_length' => $this->formNumber($validation['min_length'] ?? null),
            'max_length' => $this->formNumber($validation['max_length'] ?? null),
            'regex' => $validation['regex'] ?? '',
            'file_types' => implode(', ', $validation['file_types'] ?? []),
            'max_file_size_kb' => $this->formNumber($validation['max_file_size_kb'] ?? null),
            'options' => $field['options'] ?? [],
        ];
    }

    /**
     * Build the attribute payload handed to SchemaService::updateField.
     *
     * @return array<string, mixed>
     */
    protected function attributesForType(FieldType $type): array
    {
        $attributes = [
            'label' => $this->fieldForm['label'] ?? '',
            'key' => $this->fieldForm['key'] ?? '',
        ];

        if ($type === FieldType::Heading) {
            return $attributes;
        }

        $attributes['help_text'] = $this->fieldForm['help_text'] ?? '';
        $attributes['required'] = (bool) ($this->fieldForm['required'] ?? false);
        $attributes['default'] = ($this->fieldForm['default'] ?? '') === ''
            ? null
            : $this->fieldForm['default'];

        if ($this->typeSupportsPlaceholder($type)) {
            $attributes['placeholder'] = $this->fieldForm['placeholder'] ?? '';
        }

        if ($type->requiresOptions()) {
            $attributes['options'] = array_values($this->fieldForm['options'] ?? []);
        }

        $validation = [];

        if ($this->typeSupportsLengthRules($type)) {
            $validation['min_length'] = $this->schemaNumber($this->fieldForm['min_length'] ?? null);
            $validation['max_length'] = $this->schemaNumber($this->fieldForm['max_length'] ?? null);
        }

        if ($type === FieldType::Number || $type === FieldType::Rating) {
            $validation['min'] = $this->schemaNumber($this->fieldForm['min'] ?? null);
            $validation['max'] = $this->schemaNumber($this->fieldForm['max'] ?? null);
        }

        if ($this->typeSupportsRegex($type)) {
            $regex = trim((string) ($this->fieldForm['regex'] ?? ''));
            $validation['regex'] = $regex === '' ? null : $regex;
        }

        if ($type === FieldType::File) {
            $validation['file_types'] = $this->parseFileTypes($this->fieldForm['file_types'] ?? '');
            $validation['max_file_size_kb'] = $this->schemaNumber($this->fieldForm['max_file_size_kb'] ?? null);
        }

        if ($validation !== []) {
            $attributes['validation'] = $validation;
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rulesForFieldForm(FieldType $type): array
    {
        $rules = [
            'fieldForm.label' => ['required', 'string', 'max:255'],
            'fieldForm.key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9_]+$/',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (in_array((string) $value, $this->otherFieldKeys(), true)) {
                        $fail('Another field already uses this key.');
                    }
                },
            ],
        ];

        if ($type === FieldType::Heading) {
            return $rules;
        }

        $rules['fieldForm.placeholder'] = ['nullable', 'string', 'max:255'];
        $rules['fieldForm.help_text'] = ['nullable', 'string', 'max:500'];

        if ($this->typeSupportsLengthRules($type)) {
            $rules['fieldForm.min_length'] = ['nullable', 'numeric', 'min:0'];
            $rules['fieldForm.max_length'] = ['nullable', 'numeric', 'min:0'];
        }

        if ($type === FieldType::Number || $type === FieldType::Rating) {
            $rules['fieldForm.min'] = ['nullable', 'numeric', 'min:0'];
            $rules['fieldForm.max'] = ['nullable', 'numeric', 'min:0'];
        }

        if ($type === FieldType::File) {
            $rules['fieldForm.max_file_size_kb'] = ['nullable', 'numeric', 'min:0'];
        }

        if ($type->requiresOptions()) {
            $rules['fieldForm.options'] = ['array', 'min:1'];
            $rules['fieldForm.options.*.value'] = ['required', 'string', 'max:191'];
            $rules['fieldForm.options.*.label'] = ['required', 'string', 'max:191'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function fieldFormAttributeNames(): array
    {
        return [
            'fieldForm.label' => 'label',
            'fieldForm.key' => 'key',
            'fieldForm.placeholder' => 'placeholder',
            'fieldForm.help_text' => 'help text',
            'fieldForm.default' => 'default value',
            'fieldForm.min' => 'minimum',
            'fieldForm.max' => 'maximum',
            'fieldForm.min_length' => 'minimum length',
            'fieldForm.max_length' => 'maximum length',
            'fieldForm.regex' => 'regex',
            'fieldForm.file_types' => 'allowed file types',
            'fieldForm.max_file_size_kb' => 'maximum file size',
            'fieldForm.options' => 'options',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function selectedField(): ?array
    {
        if ($this->selectedFieldId === null) {
            return null;
        }

        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['id'] === $this->selectedFieldId) {
                    return $field;
                }
            }
        }

        return null;
    }

    protected function selectedFieldSupportsOptions(): bool
    {
        $field = $this->selectedField();

        if ($field === null) {
            return false;
        }

        return (FieldType::tryFrom($field['type']) ?? FieldType::Text)->requiresOptions();
    }

    /**
     * Keys owned by fields other than the selected one.
     *
     * @return list<string>
     */
    protected function otherFieldKeys(): array
    {
        $keys = [];

        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['id'] !== $this->selectedFieldId) {
                    $keys[] = $field['key'];
                }
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    protected function parseFileTypes(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $item): string => strtolower(trim($item, " \t\n\r\0\x0B.")),
            explode(',', $raw)
        ))));
    }

    protected function formNumber(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    protected function schemaNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value + 0;
    }

    protected function typeSupportsPlaceholder(FieldType $type): bool
    {
        return in_array($type, [
            FieldType::Text,
            FieldType::Textarea,
            FieldType::Number,
            FieldType::Email,
            FieldType::Phone,
            FieldType::Dropdown,
        ], true);
    }

    protected function typeSupportsLengthRules(FieldType $type): bool
    {
        return in_array($type, [FieldType::Text, FieldType::Textarea], true);
    }

    protected function typeSupportsRegex(FieldType $type): bool
    {
        return in_array($type, [
            FieldType::Text,
            FieldType::Textarea,
            FieldType::Email,
            FieldType::Phone,
        ], true);
    }

    #[Computed]
    public function schemaJson(): string
    {
        return json_encode(
            $this->schema,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '{}';
    }

    /**
     * Property editor view model. Null when no field is selected.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function fieldEditor(): ?array
    {
        $field = $this->selectedField();

        if ($field === null) {
            return null;
        }

        $type = FieldType::tryFrom($field['type']) ?? FieldType::Text;
        $isHeading = $type === FieldType::Heading;

        return [
            'type' => $type->value,
            'typeLabel' => $this->labelFor($type),
            'icon' => 'builder::icons.'.$type->value,
            'isHeading' => $isHeading,
            'showKey' => ! $isHeading,
            'showPlaceholder' => ! $isHeading && $this->typeSupportsPlaceholder($type),
            'showDefault' => ! $isHeading,
            'showHelpText' => ! $isHeading,
            'showValidation' => ! $isHeading,
            'showLengthRules' => $this->typeSupportsLengthRules($type),
            'showNumberRange' => $type === FieldType::Number,
            'showRatingRange' => $type === FieldType::Rating,
            'showFileSize' => $type === FieldType::File,
            'showFileTypes' => $type === FieldType::File,
            'showRegex' => $this->typeSupportsRegex($type),
            'showOptions' => $type->requiresOptions(),
            'showAdvanced' => ! $isHeading && ($this->typeSupportsRegex($type) || $type === FieldType::File),
            'optionCount' => count($field['options'] ?? []),
        ];
    }

    /**
     * Canvas view model, so Blade never reads raw schema arrays.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function sections(): array
    {
        return array_map(function (array $section): array {
            $fields = $section['fields'] ?? [];
            $lastIndex = count($fields) - 1;

            return [
                'id' => $section['id'],
                'title' => $section['title'],
                'fieldCount' => count($fields),
                'selected' => $section['id'] === $this->selectedSectionId,
                'fields' => array_map(function (array $field, int $index) use ($section, $lastIndex): array {
                    $type = FieldType::tryFrom($field['type']) ?? FieldType::Text;

                    return [
                        'id' => $field['id'],
                        'sectionId' => $section['id'],
                        'index' => $index,
                        'label' => $field['label'],
                        'type' => $type->value,
                        'typeLabel' => $this->labelFor($type),
                        'icon' => 'builder::icons.'.$type->value,
                        'selected' => $field['id'] === $this->selectedFieldId,
                        'isFirst' => $index === 0,
                        'isLast' => $index === $lastIndex,
                    ];
                }, $fields, array_keys($fields)),
            ];
        }, $this->schema['sections'] ?? []);
    }

    /**
     * Validate before assigning so an invalid schema can never be committed.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function commit(array $schema): void
    {
        try {
            $normalized = $this->schemaService()->normalize($schema);
            $this->schemaService()->assertValid($normalized);

            $this->schema = $normalized;
            $this->schemaError = null;
            $this->dirty = true;
            $this->saveMessage = null;
        } catch (ValidationException $exception) {
            $this->schemaError = 'That change was rejected: '
                .(collect($exception->errors())->flatten()->first() ?? 'the schema would become invalid.');
        }

        unset($this->schemaJson, $this->sections, $this->fieldEditor);
    }

    /**
     * Reapply a selection captured before the schema was replaced.
     *
     * The containing section is resolved from the new schema rather than
     * trusted from the captured value, so a field that changed sections still
     * highlights in the right place.
     */
    protected function restoreSelection(?string $fieldId, ?string $sectionId): void
    {
        $located = $fieldId !== null ? $this->locateField($fieldId) : null;

        if ($located !== null) {
            $this->selectedFieldId = $fieldId;
            $this->selectedSectionId = $located['sectionId'];
            $this->loadFieldForm($located['field']);

            return;
        }

        $this->selectedFieldId = null;
        $this->selectedSectionId = in_array($sectionId, $this->sectionIds(), true)
            ? $sectionId
            : null;
        $this->loadFieldForm();
    }

    /**
     * Drop selection pointing at sections or fields that no longer exist.
     */
    protected function clearSelection(): void
    {
        if ($this->selectedFieldId !== null && $this->locateField($this->selectedFieldId) === null) {
            $this->selectedFieldId = null;
        }

        if ($this->selectedSectionId !== null && ! in_array($this->selectedSectionId, $this->sectionIds(), true)) {
            $this->selectedSectionId = null;
        }
    }

    /**
     * The single movement pipeline for both drag and drop and the arrow buttons.
     *
     * $position is an insert-before index measured against the field list as it
     * currently stands, which is what the DOM reports on drop. SchemaService
     * removes the field before splicing it back, so a same-section move to a
     * later slot has to shed one index.
     */
    protected function moveFieldToPosition(string $fieldId, string $toSectionId, int $position): void
    {
        $located = $this->locateField($fieldId);

        if ($located === null) {
            $this->schemaError = 'That field could not be found.';

            return;
        }

        // A destination that does not exist would drop the field entirely,
        // since SchemaService removes it before looking for the target section.
        if (! in_array($toSectionId, $this->sectionIds(), true)) {
            $this->schemaError = 'That section could not be found.';

            return;
        }

        if ($located['sectionId'] === $toSectionId && $located['index'] < $position) {
            $position--;
        }

        $this->commit($this->schemaService()->moveField(
            $this->schema,
            $fieldId,
            $toSectionId,
            $position
        ));

        // The field keeps its selection; the highlighted section follows it.
        if ($this->selectedFieldId === $fieldId) {
            $this->selectedSectionId = $toSectionId;
        }

        $this->loadFieldForm();
    }

    /**
     * Add to the selected section, else the last section, else a fresh one.
     */
    protected function resolveTargetSection(?string $sectionId): ?string
    {
        $sectionIds = $this->sectionIds();

        if ($sectionId !== null && in_array($sectionId, $sectionIds, true)) {
            return $sectionId;
        }

        if ($this->selectedSectionId !== null && in_array($this->selectedSectionId, $sectionIds, true)) {
            return $this->selectedSectionId;
        }

        if ($sectionIds !== []) {
            return end($sectionIds);
        }

        $this->commit($this->schemaService()->addSection($this->schema));

        return $this->sectionIds()[0] ?? null;
    }

    /**
     * @return array{sectionId: string, index: int, lastIndex: int, field: array<string, mixed>}|null
     */
    protected function locateField(string $fieldId): ?array
    {
        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] as $index => $field) {
                if ($field['id'] === $fieldId) {
                    return [
                        'sectionId' => $section['id'],
                        'index' => $index,
                        'lastIndex' => count($section['fields']) - 1,
                        'field' => $field,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function sectionIds(): array
    {
        return array_column($this->schema['sections'] ?? [], 'id');
    }

    /**
     * @return list<string>
     */
    protected function fieldIds(): array
    {
        $ids = [];

        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] as $field) {
                $ids[] = $field['id'];
            }
        }

        return $ids;
    }

    /**
     * Defaults handed to SchemaService::addField. Choice types need starter
     * options because an empty option list fails schema validation.
     *
     * @return array<string, mixed>
     */
    protected function defaultAttributesFor(FieldType $type): array
    {
        $attributes = ['label' => $this->defaultLabelFor($type)];

        if ($type->requiresOptions()) {
            $attributes['options'] = [
                ['value' => 'option_1', 'label' => 'Option 1'],
                ['value' => 'option_2', 'label' => 'Option 2'],
            ];
        }

        return $attributes;
    }

    protected function defaultLabelFor(FieldType $type): string
    {
        return match ($type) {
            FieldType::Heading => 'Section Heading',
            default => $this->labelFor($type).' Field',
        };
    }

    protected function schemaService(): SchemaService
    {
        return app(SchemaService::class);
    }

    /**
     * @return list<array{type: string, label: string, description: string, icon: string}>
     */
    protected function buildPalette(): array
    {
        return array_map(
            fn (FieldType $type): array => [
                'type' => $type->value,
                'label' => $this->labelFor($type),
                'description' => $this->descriptionFor($type),
                'icon' => 'builder::icons.'.$type->value,
            ],
            FieldType::cases()
        );
    }

    protected function labelFor(FieldType $type): string
    {
        return match ($type) {
            FieldType::Text => 'Text',
            FieldType::Textarea => 'Textarea',
            FieldType::Number => 'Number',
            FieldType::Email => 'Email',
            FieldType::Phone => 'Phone',
            FieldType::Date => 'Date',
            FieldType::Dropdown => 'Dropdown',
            FieldType::Radio => 'Radio',
            FieldType::Checkbox => 'Checkbox',
            FieldType::File => 'File Upload',
            FieldType::Heading => 'Heading',
            FieldType::Rating => 'Rating',
        };
    }

    protected function descriptionFor(FieldType $type): string
    {
        return match ($type) {
            FieldType::Text => 'Single line input',
            FieldType::Textarea => 'Multi-line input',
            FieldType::Number => 'Numeric input',
            FieldType::Email => 'Email address',
            FieldType::Phone => 'Phone number',
            FieldType::Date => 'Date picker',
            FieldType::Dropdown => 'Select one option',
            FieldType::Radio => 'Choose one option',
            FieldType::Checkbox => 'Choose multiple options',
            FieldType::File => 'Upload file',
            FieldType::Heading => 'Section heading',
            FieldType::Rating => 'Star rating',
        };
    }

    #[Layout('layouts.builder')]
    #[Title('Form Builder')]
    public function render()
    {
        return view('livewire.forms.form-builder');
    }
}

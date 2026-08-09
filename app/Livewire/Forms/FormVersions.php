<?php

namespace App\Livewire\Forms;

use App\Models\Form;
use App\Models\FormVersion;
use App\Services\SchemaService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Read the history of a form, compare any point in it against the present, and
 * roll back to one.
 *
 * The page is read-only apart from rollback, and rollback itself writes nothing
 * directly: it hands the historical schema to SchemaService::save(), which
 * appends a new version rather than reopening an old one. A stored snapshot is
 * never normalized, revalidated into place, or written back, because the whole
 * value of a history is that it records what was actually saved.
 */
class FormVersions extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    private const PER_PAGE = 15;

    public Form $form;

    /**
     * The form's version at the moment this page was opened.
     *
     * Rollback compares this against the stored value before it writes. The
     * builder's own dirty flag cannot help here, because that state lives on
     * the builder component and is gone the moment the browser navigates away;
     * this is the guard that survives the trip.
     */
    #[Locked]
    public int $mountedVersion = 0;

    /**
     * The version being read, and the version being compared. Locked so a
     * forged payload cannot aim either at a row this user does not own; every
     * read re-resolves through the form's own relation regardless.
     */
    #[Locked]
    public ?string $viewingId = null;

    #[Locked]
    public ?string $comparingId = null;

    #[Locked]
    public ?string $rollbackError = null;

    public function mount(Form $form): void
    {
        $this->authorize('view', $form);

        $this->form = $form;
        $this->mountedVersion = (int) $form->schema_version;
    }

    /**
     * @return LengthAwarePaginator<FormVersion>
     */
    #[Computed]
    public function versions(): LengthAwarePaginator
    {
        return $this->form->versions()
            ->with('creator')
            ->orderByDesc('version')
            ->paginate(self::PER_PAGE);
    }

    public function viewVersion(string $id): void
    {
        $this->comparingId = null;
        $this->rollbackError = null;
        $this->viewingId = $this->resolve($id)?->id;

        unset($this->viewing, $this->comparison);
    }

    public function compareVersion(string $id): void
    {
        $this->viewingId = null;
        $this->rollbackError = null;
        $this->comparingId = $this->resolve($id)?->id;

        unset($this->viewing, $this->comparison);
    }

    public function closePanel(): void
    {
        $this->viewingId = null;
        $this->comparingId = null;
        $this->rollbackError = null;

        unset($this->viewing, $this->comparison);
    }

    /**
     * A read-only presentation of one stored snapshot.
     *
     * Built defensively straight from what is on the row: anything missing
     * reads as blank and anything malformed is skipped, so a legacy or
     * hand-damaged schema still renders instead of throwing. normalize() is
     * deliberately not used, since repairing the document here would show the
     * reader a version that was never saved.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function viewing(): ?array
    {
        $version = $this->viewingId !== null ? $this->resolve($this->viewingId) : null;

        if ($version === null) {
            return null;
        }

        $schema = is_array($version->schema) ? $version->schema : [];

        return [
            'version' => $version->version,
            'created_at' => $version->created_at,
            'creator' => $version->creator?->name ?? 'Unknown',
            'note' => $version->note,
            'title' => $this->text($schema['title'] ?? null, 'Untitled Form'),
            'description' => $this->text($schema['description'] ?? null, ''),
            'settings' => $this->settingsOf($schema),
            'sections' => $this->sectionsOf($schema),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function comparison(): ?array
    {
        $version = $this->comparingId !== null ? $this->resolve($this->comparingId) : null;

        if ($version === null) {
            return null;
        }

        $historical = is_array($version->schema) ? $version->schema : [];
        $current = is_array($this->form->schema) ? $this->form->schema : [];

        return [
            'version' => $version->version,
            'created_at' => $version->created_at,
            // Historical first, so "added" reads as "added since that version".
            'diff' => app(SchemaService::class)->diff($historical, $current),
        ];
    }

    /**
     * Replace the working schema with an older snapshot.
     *
     * Nothing here writes: every gate is checked first, and the write itself is
     * SchemaService::save(), whose transaction is the atomicity guarantee. The
     * target row is only ever read.
     */
    public function rollback(string $id, SchemaService $schemas): mixed
    {
        $this->authorize('rollback', $this->form);

        $this->rollbackError = null;

        $version = $this->resolve($id);

        if ($version === null) {
            $this->rollbackError = 'That version could not be found for this form.';

            return null;
        }

        // Another tab, or a queued AI or import job, may have saved since this
        // page was rendered. Rolling back now would discard work the user has
        // not seen, so it is refused rather than silently overwritten.
        if ((int) $this->form->fresh()->schema_version !== $this->mountedVersion) {
            $this->rollbackError = 'This form changed since you opened this page. Reload and try again.';

            return null;
        }

        $schema = is_array($version->schema) ? $version->schema : [];

        if ($schemas->validationErrors($schema) !== []) {
            $this->rollbackError = 'That version cannot be restored because its schema is no longer valid.';

            return null;
        }

        try {
            $schemas->save($this->form, $schema, Auth::user(), "Rolled back to version {$version->version}");
        } catch (ValidationException) {
            $this->rollbackError = 'That version cannot be restored because its schema is no longer valid.';

            return null;
        } catch (Throwable $exception) {
            Log::error('Failed to roll a form back to an earlier version.', [
                'form_id' => $this->form->id,
                'version' => $version->version,
                'exception' => $exception,
            ]);

            $this->rollbackError = 'That version could not be restored. Please try again.';

            return null;
        }

        session()->flash(
            'builderMessage',
            "Rolled back to version {$version->version}. This is now version ".($this->mountedVersion + 1).'.'
        );

        return $this->redirectRoute('forms.builder', $this->form, navigate: true);
    }

    /**
     * Render one side of a change as a short, printable string.
     *
     * Options, validation blocks and conditions arrive as arrays, and a raw
     * dump of one would drown the row it is meant to explain, so they are
     * summarised instead. Escaping stays with Blade; nothing here is trusted
     * as markup.
     */
    public function preview(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if ($value === null || $value === '') {
            return 'empty';
        }

        if (is_array($value)) {
            if ($value === []) {
                return 'none';
            }

            $parts = [];

            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $parts[] = $this->text($item['label'] ?? $item['value'] ?? null, 'item');

                    continue;
                }

                if ($item === null || $item === '' || $item === false) {
                    continue;
                }

                $parts[] = is_string($key)
                    ? str_replace('_', ' ', $key).' '.$this->scalarText($item)
                    : $this->scalarText($item);
            }

            return $parts === [] ? 'none' : Str::limit(implode(', ', $parts), 120);
        }

        return Str::limit($this->scalarText($value), 120);
    }

    /**
     * Resolve a version through the form's own relation.
     *
     * Scoping the lookup this way makes ownership and membership a single
     * question: a row belonging to another form, or to another user's form,
     * simply is not found.
     */
    protected function resolve(string $id): ?FormVersion
    {
        return $this->form->versions()->whereKey($id)->first();
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function settingsOf(array $schema): array
    {
        $settings = is_array($schema['settings'] ?? null) ? $schema['settings'] : [];

        return [
            'multi_step' => (bool) ($settings['multi_step'] ?? false),
            'submit_button_text' => $this->text($settings['submit_button_text'] ?? null, 'Submit'),
            'success_message' => $this->text($settings['success_message'] ?? null, ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>>
     */
    protected function sectionsOf(array $schema): array
    {
        $sections = [];

        foreach ($this->arrayOf($schema['sections'] ?? null) as $section) {
            $fields = [];

            foreach ($this->arrayOf($section['fields'] ?? null) as $field) {
                $fields[] = [
                    'label' => $this->text($field['label'] ?? null, 'Untitled Field'),
                    'key' => $this->text($field['key'] ?? null, ''),
                    'type' => $this->text($field['type'] ?? null, 'unknown'),
                    'placeholder' => $this->text($field['placeholder'] ?? null, ''),
                    'help_text' => $this->text($field['help_text'] ?? null, ''),
                    'default' => $this->scalarText($field['default'] ?? null),
                    'required' => (bool) ($field['required'] ?? false),
                    'options' => $this->optionsOf($field['options'] ?? null),
                    'validation' => $this->pairsOf($field['validation'] ?? null),
                    'conditions' => count($this->arrayOf($field['conditions'] ?? null)),
                ];
            }

            $sections[] = [
                'title' => $this->text($section['title'] ?? null, 'Untitled Section'),
                'description' => $this->text($section['description'] ?? null, ''),
                'fields' => $fields,
            ];
        }

        return $sections;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function optionsOf(mixed $options): array
    {
        $resolved = [];

        foreach ($this->arrayOf($options) as $option) {
            $resolved[] = [
                'value' => $this->scalarText($option['value'] ?? null),
                'label' => $this->text($option['label'] ?? null, ''),
            ];
        }

        return $resolved;
    }

    /**
     * Flatten a validation block into printable pairs, dropping empties so the
     * reader sees only the rules that were actually set.
     *
     * @return list<array{label: string, value: string}>
     */
    protected function pairsOf(mixed $validation): array
    {
        if (! is_array($validation)) {
            return [];
        }

        $pairs = [];

        foreach ($validation as $rule => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $pairs[] = [
                'label' => str_replace('_', ' ', (string) $rule),
                'value' => is_array($value) ? implode(', ', array_map(
                    fn ($item): string => $this->scalarText($item),
                    $value
                )) : $this->scalarText($value),
            ];
        }

        return $pairs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function arrayOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    protected function text(mixed $value, string $fallback): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? $fallback : $text;
    }

    protected function scalarText(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    #[Layout('layouts.app')]
    #[Title('Version History')]
    public function render()
    {
        return view('livewire.forms.form-versions');
    }
}

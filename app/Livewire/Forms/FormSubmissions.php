<?php

namespace App\Livewire\Forms;

use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The owner's read-only view of what people have submitted.
 *
 * Strictly a reader: nothing here writes, and SubmissionService is untouched.
 * Answers are labelled from the schema snapshot the submission was filed
 * against rather than from the current schema, so a response written before a
 * field was renamed still reads the way its author saw it.
 */
class FormSubmissions extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    private const PER_PAGE = 15;

    private const PREVIEW_LENGTH = 90;

    /**
     * Not a backslash: MySQL also treats one as an escape inside string
     * literals, which makes the same pattern read two different ways depending
     * on how it reaches the driver.
     */
    private const LIKE_ESCAPE = '~';

    public Form $form;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    /**
     * Locked so a forged payload cannot open a submission belonging to another
     * form; the lookup is scoped to this form regardless.
     */
    #[Locked]
    public ?string $selectedId = null;

    public function mount(Form $form): void
    {
        $this->authorize('view', $form);

        $this->form = $form;
    }

    public function updatedSearch(): void
    {
        $this->selectedId = null;
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->selectedId = null;
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<FormSubmission>
     */
    #[Computed]
    public function submissions(): LengthAwarePaginator
    {
        $query = FormSubmission::query()
            ->where('form_id', $this->form->id)
            ->latest('created_at');

        $term = trim($this->search);

        if ($term !== '') {
            // search_text is written by SubmissionService at submit time and
            // holds the answers plus the labels of any chosen options.
            //
            // The escape character is declared rather than assumed: MySQL
            // defaults to a backslash and SQLite has no default at all, so
            // without this clause the same search would behave differently in
            // the test suite and in production.
            $query->whereRaw(
                'search_text like ? escape ?',
                ['%'.$this->escapeLike($term).'%', self::LIKE_ESCAPE]
            );
        }

        $status = SubmissionStatus::tryFrom($this->statusFilter);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate(self::PER_PAGE);
    }

    /**
     * Escape the wildcards so a term containing % or _ is matched literally.
     *
     * The escape character itself goes first, otherwise it would escape the
     * escapes added after it. Without any of this, a search for "50%" would
     * return every row in the table.
     */
    protected function escapeLike(string $term): string
    {
        $escape = self::LIKE_ESCAPE;

        return str_replace(
            [$escape, '%', '_'],
            [$escape.$escape, $escape.'%', $escape.'_'],
            $term
        );
    }

    public function select(string $id): void
    {
        $this->selectedId = $id;

        unset($this->selected);
    }

    public function clearSelection(): void
    {
        $this->selectedId = null;

        unset($this->selected);
    }

    /**
     * The opened submission, rendered against its own schema version.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function selected(): ?array
    {
        if ($this->selectedId === null) {
            return null;
        }

        $submission = FormSubmission::query()
            ->where('form_id', $this->form->id)
            ->with('files')
            ->whereKey($this->selectedId)
            ->first();

        if ($submission === null) {
            return null;
        }

        $labels = $this->labelsForVersion($submission->form_version);
        $payload = is_array($submission->payload) ? $submission->payload : [];

        $answers = [];

        foreach ($payload as $key => $value) {
            $answers[] = [
                // No snapshot, or a field added since, falls back to the raw key.
                'label' => $labels[$key] ?? $key,
                'key' => (string) $key,
                'value' => $this->readable($value),
            ];
        }

        return [
            'id' => $submission->id,
            'submitted_at' => $submission->created_at,
            'version' => $submission->form_version,
            'status' => $submission->status->value,
            'answers' => $answers,
            // Names only. There is no download route, so nothing here exposes a path.
            'files' => $submission->files
                ->map(fn ($file): array => [
                    'field_key' => $file->field_key,
                    'name' => $file->original_name,
                ])
                ->all(),
        ];
    }

    /**
     * Field labels from the snapshot this submission was filed against.
     *
     * Read tolerantly and never repaired: an old snapshot is evidence of what
     * the form once was, so anything unreadable is skipped rather than fixed.
     *
     * @return array<string, string>
     */
    protected function labelsForVersion(int $version): array
    {
        $snapshot = FormVersion::query()
            ->where('form_id', $this->form->id)
            ->where('version', $version)
            ->value('schema');

        if (! is_array($snapshot)) {
            return [];
        }

        $labels = [];

        foreach ($snapshot['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }

            foreach ($section['fields'] ?? [] as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $key = $field['key'] ?? null;
                $label = $field['label'] ?? null;

                if (is_string($key) && $key !== '' && is_string($label) && $label !== '') {
                    $labels[$key] = $label;
                }
            }
        }

        return $labels;
    }

    /**
     * A one-line summary of a submission for the list.
     */
    public function preview(FormSubmission $submission): string
    {
        $text = trim((string) $submission->search_text);

        return $text === '' ? 'No answers' : Str::limit($text, self::PREVIEW_LENGTH);
    }

    protected function readable(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            $parts = array_map(
                fn ($item): string => is_scalar($item) ? (string) $item : '',
                $value
            );

            return implode(', ', array_filter($parts, fn (string $part): bool => $part !== ''));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function statuses(): array
    {
        return array_map(
            fn (SubmissionStatus $status): array => [
                'value' => $status->value,
                'label' => ucfirst($status->value),
            ],
            SubmissionStatus::cases()
        );
    }

    #[Layout('layouts.app')]
    #[Title('Submissions')]
    public function render()
    {
        return view('livewire.forms.form-submissions');
    }
}

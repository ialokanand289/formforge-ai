<?php

namespace App\Livewire\Forms;

use App\Enums\FormStatus;
use App\Models\Form;
use App\Services\SchemaService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The only path in the application that creates a Form.
 *
 * Every identifier is minted here rather than accepted from the browser: the
 * owner comes from the session, the slug from the title, and the public token
 * from a fresh UUID. The database constraints are treated as the authority on
 * uniqueness, with the read-time checks below as an optimisation that keeps the
 * common case from ever reaching them.
 */
class FormCreate extends Component
{
    use AuthorizesRequests;

    /**
     * Bounded so a pathological collision streak fails instead of spinning.
     */
    private const MAX_CREATE_ATTEMPTS = 3;

    /**
     * The slug column is varchar(255); the cap leaves room for a suffix.
     */
    private const MAX_SLUG_LENGTH = 200;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|string|max:2000')]
    public string $description = '';

    /**
     * Server-set only. A creation failure is reported without any database
     * detail reaching the page.
     */
    #[Locked]
    public ?string $createError = null;

    public function mount(): void
    {
        $this->authorize('create', Form::class);
    }

    public function create(SchemaService $schemas)
    {
        $this->authorize('create', Form::class);

        $this->validate();

        $this->createError = null;

        try {
            $form = $this->store($schemas);
        } catch (UniqueConstraintViolationException $exception) {
            Log::warning('Exhausted the retry budget creating a form.', [
                'user_id' => Auth::id(),
                'exception' => $exception,
            ]);

            $this->createError = 'That form could not be created. Please try again.';

            return null;
        }

        // Only once the transaction has returned a row.
        return $this->redirectRoute('forms.builder', $form, navigate: true);
    }

    /**
     * Insert the form, retrying a bounded number of times if either unique
     * index rejects it.
     *
     * The slug and the token are both computed inside the closure, so one
     * retry path covers both constraints without having to work out which one
     * fired: the next pass re-runs the slug query, which now sees the row that
     * beat it, and draws a fresh UUID at the same time.
     */
    protected function store(SchemaService $schemas): Form
    {
        $title = trim($this->title);
        $description = trim($this->description);

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): Form => Form::create([
                    'user_id' => Auth::id(),
                    'title' => $title,
                    'description' => $description !== '' ? $description : null,
                    'slug' => $this->uniqueSlug($title),
                    'public_token' => (string) Str::uuid(),
                    'status' => FormStatus::Draft,
                    // The service owns schema construction; nothing is hand built here.
                    'schema' => $schemas->blank($title, $description),
                    'schema_version' => 1,
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::MAX_CREATE_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * A slug that is free for this user at read time.
     *
     * Scoped to the owner because the constraint is unique(user_id, slug), so
     * two users may each hold "contact-us". Soft-deleted rows still occupy the
     * pair, hence withTrashed(): without it the query would report a slug as
     * free and the insert would then fail against a row it could not see.
     */
    protected function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), self::MAX_SLUG_LENGTH, '') ?: 'form';
        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($slug)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    protected function slugTaken(string $slug): bool
    {
        return Form::withTrashed()
            ->where('user_id', Auth::id())
            ->where('slug', $slug)
            ->exists();
    }

    #[Layout('layouts.app')]
    #[Title('Create Form')]
    public function render()
    {
        return view('livewire.forms.create-form');
    }
}

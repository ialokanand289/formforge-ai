<?php

namespace App\Jobs;

use App\Enums\FieldType;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\AiGenerationLog;
use App\Services\AiService;
use App\Services\SchemaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Turns one AiGenerationLog row into a saved form schema, or into a recorded
 * failure that leaves the form exactly as it was.
 *
 * Generation and editing share this class because they differ only in which
 * prompt is built. The status transitions, the gates, the repair loop, the save
 * and the failure handling are identical, and splitting them would mean
 * duplicating the loop or inventing a base class to avoid duplicating it.
 */
class ProcessAiGenerationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The repair loop is internal, so a queue-level retry would restart the
     * whole thing and pay the provider twice for work already done.
     */
    public int $tries = 1;

    public int $timeout;

    /**
     * Only the id is serialized, so the job always reads fresh state.
     */
    public function __construct(public readonly string $logId)
    {
        // Budget for the initial call plus every permitted repair call, so the
        // worker always outlives the loop it is supervising.
        $this->timeout = ((int) config('formforge.ai.timeout_seconds', 60))
            * ((int) config('formforge.ai.max_repair_attempts', 3) + 1)
            + 30;
    }

    public function handle(AiService $ai, SchemaService $schemas): void
    {
        $log = AiGenerationLog::query()->find($this->logId);

        if ($log === null || $log->status !== GenerationStatus::Queued) {
            return;
        }

        $form = $log->form;

        // Defence in depth. The Livewire action already ran the policy, but a
        // job runs outside the request that authorized it.
        if ($form === null || $form->user_id !== $log->user_id) {
            $this->markFailed($log, 'This form is no longer available.');

            return;
        }

        $log->forceFill(['status' => GenerationStatus::Processing])->save();

        // The schema under edit is read from the database here, never from the
        // browser, and it is the yardstick for the key preservation gate.
        $stored = $log->type === GenerationType::Edit ? $schemas->load($form) : null;

        $maxRepairs = max(0, (int) config('formforge.ai.max_repair_attempts', 3));
        $repairs = 0;

        try {
            $result = $this->callProvider(
                $log,
                fn (): array => $stored === null
                    ? $ai->generateSchema($log->prompt)
                    : $ai->editSchema($stored, $log->prompt),
            );

            while (true) {
                $this->recordAttempt($log, $result);

                $errors = $this->candidateErrors($result['candidate'], $stored);

                if ($errors === []) {
                    /** @var array<string, mixed> $candidate */
                    $candidate = $result['candidate'];
                    $normalized = $schemas->normalize($candidate);

                    // Second pass against the normalizer's own output: if a
                    // retained key moved here, normalize() did it, which is
                    // exactly what this gate exists to catch.
                    $errors = $stored === null
                        ? []
                        : $this->keyPreservationErrors($stored, $normalized);

                    if ($errors === []) {
                        $errors = $schemas->validationErrors($normalized);
                    }

                    if ($errors === []) {
                        $this->complete($log, $schemas, $normalized);

                        return;
                    }
                }

                if ($repairs >= $maxRepairs) {
                    $this->markFailed($log, $this->summarize($errors));

                    return;
                }

                $repairs++;

                $result = $this->callProvider(
                    $log,
                    fn (): array => $ai->repairSchema($result['raw'], $errors, $log->prompt),
                );
            }
        } catch (Throwable $exception) {
            $this->markFailed($log, $this->safeMessage($exception));

            Log::warning('AI generation failed.', [
                'log_id' => $log->id,
                'form_id' => $form->id,
                'type' => $log->type->value,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Marks a log failed when the worker itself dies, times out, or is released.
     */
    public function failed(?Throwable $exception): void
    {
        $log = AiGenerationLog::query()->find($this->logId);

        if ($log === null || $log->status === GenerationStatus::Completed || $log->status === GenerationStatus::Failed) {
            return;
        }

        $this->markFailed($log, 'The AI request did not finish. Please try again.');
    }

    /**
     * Count the call before making it, so a worker that dies mid-call still
     * leaves an accurate tally rather than under-reporting by one.
     *
     * @param  callable(): array{candidate: array<string, mixed>|null, raw: string, model: ?string, prompt_tokens: ?int, completion_tokens: ?int, latency_ms: int}  $call
     * @return array{candidate: array<string, mixed>|null, raw: string, model: ?string, prompt_tokens: ?int, completion_tokens: ?int, latency_ms: int}
     */
    private function callProvider(AiGenerationLog $log, callable $call): array
    {
        $log->forceFill(['attempts' => $log->attempts + 1])->save();

        return $call();
    }

    /**
     * @param  array{raw: string, model: ?string, prompt_tokens: ?int, completion_tokens: ?int, latency_ms: int}  $result
     */
    private function recordAttempt(AiGenerationLog $log, array $result): void
    {
        // Tokens and latency accumulate across the initial call and every
        // repair, so the row reports what the generation actually cost.
        $log->forceFill([
            'response_raw' => $result['raw'],
            'model' => $result['model'] ?? $log->model,
            'prompt_tokens' => (int) $log->prompt_tokens + (int) $result['prompt_tokens'],
            'completion_tokens' => (int) $log->completion_tokens + (int) $result['completion_tokens'],
            'latency_ms' => (int) $log->latency_ms + $result['latency_ms'],
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function complete(AiGenerationLog $log, SchemaService $schemas, array $schema): void
    {
        $note = $log->type === GenerationType::Edit ? 'AI edit' : 'AI generation';

        try {
            $schemas->save($log->form, $schema, $log->user, $note);
        } catch (ValidationException $exception) {
            $this->markFailed($log, $this->summarize($exception->errors()));

            return;
        }

        $log->forceFill([
            'status' => GenerationStatus::Completed,
            'schema_result' => $schema,
            'error_message' => null,
        ])->save();
    }

    /**
     * Named to stay clear of InteractsWithQueue::fail(), which the queue itself
     * calls and which must keep its own meaning.
     */
    private function markFailed(AiGenerationLog $log, string $message): void
    {
        $log->forceFill([
            'status' => GenerationStatus::Failed,
            'error_message' => $message,
        ])->save();
    }

    /**
     * Every gate that must run before SchemaService::normalize() gets a chance
     * to quietly repair the problem away.
     *
     * @param  array<string, mixed>|null  $candidate
     * @param  array<string, mixed>|null  $stored
     * @return array<string, list<string>>
     */
    private function candidateErrors(?array $candidate, ?array $stored): array
    {
        if ($candidate === null) {
            return ['response' => ['The reply was not a JSON object. Return the schema as raw JSON.']];
        }

        $errors = $this->structuralErrors($candidate);

        if ($errors !== []) {
            return $errors;
        }

        $errors = array_merge(
            $this->fieldTypeErrors($candidate),
            $this->duplicateKeyErrors($candidate),
        );

        if ($stored !== null) {
            $errors = array_merge($errors, $this->keyPreservationErrors($stored, $candidate));
        }

        return $errors;
    }

    /**
     * normalize() invents a title and an empty section list, which would turn
     * "the model replied with prose" into a valid but empty form.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, list<string>>
     */
    private function structuralErrors(array $candidate): array
    {
        $errors = [];

        $title = $candidate['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            $errors['title'][] = 'The schema must include a non-empty title.';
        }

        if (! is_array($candidate['sections'] ?? null)) {
            $errors['sections'][] = 'The schema must include a sections array.';
        }

        return $errors;
    }

    /**
     * normalizeField() falls back to FieldType::Text for anything it does not
     * recognise, so without this gate an invented type is silently rewritten.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, list<string>>
     */
    private function fieldTypeErrors(array $candidate): array
    {
        $errors = [];
        $allowed = implode(', ', FieldType::values());

        foreach ($this->walk($candidate) as [$path, $field]) {
            $type = $field['type'] ?? null;

            if (! is_string($type) || FieldType::tryFrom($type) === null) {
                $shown = is_scalar($type) ? (string) $type : 'nothing';
                $errors["{$path}.type"][] = "Unsupported field type [{$shown}]. Use one of: {$allowed}.";
            }
        }

        return $errors;
    }

    /**
     * uniqueKey() would suffix a collision to key_2, detaching that field from
     * any answers already filed under the original key.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, list<string>>
     */
    private function duplicateKeyErrors(array $candidate): array
    {
        $errors = [];
        $seen = [];

        foreach ($this->walk($candidate) as [$path, $field]) {
            $key = $field['key'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                $errors["{$path}.key"][] = "Duplicate field key [{$key}]. Every field key must be unique.";

                continue;
            }

            $seen[$key] = true;
        }

        return $errors;
    }

    /**
     * The hard rule for AI edits: a field that survives the edit keeps its key.
     *
     * Identity is the field id, which the edit prompt requires the model to copy
     * verbatim. That is what separates an accidental rename, which is rejected,
     * from a deliberate removal or replacement, which is allowed. slugifyKey()
     * and uniqueKey() are both capable of moving a key without saying so, so
     * this runs against the raw candidate and again against normalize() output.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $candidate
     * @return array<string, list<string>>
     */
    private function keyPreservationErrors(array $stored, array $candidate): array
    {
        $storedKeys = [];

        foreach ($this->walk($stored) as [, $field]) {
            $id = $this->fieldId($field);

            if ($id !== null && is_string($field['key'] ?? null)) {
                $storedKeys[$id] = $field['key'];
            }
        }

        $errors = [];

        foreach ($this->walk($candidate) as [$path, $field]) {
            $id = $this->fieldId($field);

            // No id, or an id we have never seen, is a new field. Allowed.
            if ($id === null || ! isset($storedKeys[$id])) {
                continue;
            }

            $expected = $storedKeys[$id];
            $actual = $field['key'] ?? null;

            if ($actual === $expected) {
                continue;
            }

            $shown = is_scalar($actual) ? (string) $actual : 'nothing';

            $errors["{$path}.key"][] = "Field key must not change: expected [{$expected}], received [{$shown}]. "
                .'Keep the original key for every field you are not removing.';
        }

        return $errors;
    }

    private function fieldId(array $field): ?string
    {
        $id = $field['id'] ?? null;

        return is_string($id) && $id !== '' ? strtoupper($id) : null;
    }

    /**
     * Yield [dot path, field] for every field in a schema, tolerating the
     * malformed shapes an AI can produce.
     *
     * @param  array<string, mixed>  $schema
     * @return iterable<array{0: string, 1: array<string, mixed>}>
     */
    private function walk(array $schema): iterable
    {
        foreach ($schema['sections'] ?? [] as $sIndex => $section) {
            if (! is_array($section)) {
                continue;
            }

            foreach ($section['fields'] ?? [] as $fIndex => $field) {
                if (is_array($field)) {
                    yield ["sections.{$sIndex}.fields.{$fIndex}", $field];
                }
            }
        }
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function summarize(array $errors): string
    {
        $first = collect($errors)->flatten()->first();

        return 'The AI could not produce a valid form: '
            .(is_string($first) ? $first : 'the schema was rejected.');
    }

    private function safeMessage(Throwable $exception): string
    {
        // AiService already sanitizes provider failures into safe sentences.
        // Anything else is internal and must not reach the browser.
        return $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'The AI request could not be completed. Please try again.';
    }
}

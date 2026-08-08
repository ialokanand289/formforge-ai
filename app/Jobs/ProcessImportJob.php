<?php

namespace App\Jobs;

use App\Enums\FieldType;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Models\AiGenerationLog;
use App\Models\ImportJob;
use App\Services\AiService;
use App\Services\DocxImportParser;
use App\Services\ImportArchiveGuard;
use App\Services\SchemaCandidateGate;
use App\Services\SchemaService;
use App\Services\XlsxImportParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Turns one uploaded document into a schema preview, or into a recorded failure
 * that leaves the form exactly as it was.
 *
 * This job never persists a schema. It stops at ImportStatus::Preview with the
 * validated schema parked on the row, and only an explicit user acceptance in
 * the builder calls SchemaService::save(). The source file is deleted the
 * moment the preview is written, because nothing reads it again.
 */
class ProcessImportJob implements ShouldQueue
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

    public function __construct(public readonly string $importJobId)
    {
        // Budget for the initial call plus every permitted repair call, so the
        // worker always outlives the loop it is supervising.
        $this->timeout = ((int) config('formforge.ai.timeout_seconds', 60))
            * ((int) config('formforge.ai.max_repair_attempts', 3) + 1)
            + 30;
    }

    public function handle(
        AiService $ai,
        SchemaService $schemas,
        SchemaCandidateGate $gate,
        ImportArchiveGuard $guard,
    ): void {
        $job = ImportJob::query()->find($this->importJobId);

        if ($job === null || $job->status !== ImportStatus::Queued) {
            return;
        }

        $form = $job->form;

        // Defence in depth. The Livewire action already ran the policy, but a
        // job runs outside the request that authorized it.
        if ($form === null || $form->user_id !== $job->user_id) {
            $this->markFailed($job, 'This form is no longer available.');

            return;
        }

        $job->forceFill(['status' => ImportStatus::Processing])->save();

        $log = null;

        try {
            $path = $this->absolutePath($job, $guard);

            // Again, on the file as it stands on disk now. The upload-time check
            // proved a different byte sequence was safe.
            $guard->assertSafe($path, $job->source);

            $extraction = $this->boundPayload(match ($job->source) {
                ImportSource::Docx => app(DocxImportParser::class)->parse($path),
                ImportSource::Xlsx => app(XlsxImportParser::class)->parse($path),
            });

            // Re-check before paying the provider: the pruner may have reclaimed
            // this row while the parser was working.
            if (! $this->stillProcessing()) {
                $this->abandon($job);

                return;
            }

            $log = $this->openLog($job, $extraction);
            $this->infer($ai, $schemas, $gate, $job, $log, $extraction);
        } catch (Throwable $exception) {
            $message = $this->safeMessage($exception);

            $this->markFailed($job, $message);

            if ($log !== null) {
                $log->forceFill([
                    'status' => GenerationStatus::Failed,
                    'error_message' => $message,
                ])->save();
            }

            Log::warning('Document import failed.', [
                'import_job_id' => $job->id,
                'form_id' => $job->form_id,
                'source' => $job->source->value,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Marks the import failed when the worker itself dies, times out, or is
     * released.
     */
    public function failed(?Throwable $exception): void
    {
        $job = ImportJob::query()->find($this->importJobId);

        if ($job === null || $this->isTerminal($job)) {
            return;
        }

        $this->markFailed($job, 'The import did not finish. Please try again.');
    }

    /**
     * The provider call plus its bounded repair loop.
     *
     * @param  array<string, mixed>  $extraction
     */
    private function infer(
        AiService $ai,
        SchemaService $schemas,
        SchemaCandidateGate $gate,
        ImportJob $job,
        AiGenerationLog $log,
        array $extraction,
    ): void {
        // The repair budget is the number of repair calls allowed after the
        // initial one, so 3 permits at most 4 provider calls. Read from config
        // server-side; it is never a payload field.
        $maxRepairs = max(0, (int) config('formforge.ai.max_repair_attempts', 3));
        $repairs = 0;

        $result = $this->callProvider($log, fn (): array => $ai->inferSchema($extraction, $job->source));

        while (true) {
            $this->recordAttempt($log, $result);

            // Key preservation is deliberately not applied: an import replaces
            // the schema rather than editing it, so no field is retained.
            $errors = $gate->errorsFor($result['candidate']);

            if ($errors === []) {
                /** @var array<string, mixed> $candidate */
                $candidate = $result['candidate'];
                $normalized = $schemas->normalize($candidate);
                $errors = $schemas->validationErrors($normalized);

                if ($errors === []) {
                    $this->storePreview($job, $log, $normalized);

                    return;
                }
            }

            if ($repairs >= $maxRepairs) {
                $message = $this->summarize($errors);

                $this->markFailed($job, $message);
                $log->forceFill([
                    'status' => GenerationStatus::Failed,
                    'error_message' => $message,
                ])->save();

                return;
            }

            $repairs++;

            $result = $this->callProvider(
                $log,
                fn (): array => $ai->repairSchema($result['raw'], $errors, $log->prompt),
            );
        }
    }

    /**
     * Park the validated schema for review, and let the source file go.
     *
     * The log completes here because its question — did the AI produce a valid
     * schema — is answered. The import job stays at preview because its
     * question, was that schema applied, is not.
     *
     * @param  array<string, mixed>  $schema
     */
    private function storePreview(ImportJob $job, AiGenerationLog $log, array $schema): void
    {
        // Last re-check. Without it the pruner could fail this row and delete
        // its file, and we would then resurrect it into a preview that looks
        // committable but has nothing behind it.
        if (! $this->stillProcessing()) {
            $this->abandon($job);

            $log->forceFill([
                'status' => GenerationStatus::Failed,
                'error_message' => 'The import was no longer active when the schema was ready.',
            ])->save();

            return;
        }

        $job->forceFill([
            'status' => ImportStatus::Preview,
            'final_schema' => $schema,
            'preview' => $this->summaryFor($job, $schema),
            'errors' => null,
        ])->save();

        // Nothing reads the document again: acceptImport() works entirely from
        // final_schema. Deleting now bounds a temp file's life to one job run
        // rather than to a user's attention span.
        $this->deleteFile($job);

        $log->forceFill([
            'status' => GenerationStatus::Completed,
            'schema_result' => $schema,
            'error_message' => null,
        ])->save();
    }

    /**
     * A bounded, display-ready description of what the import found.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function summaryFor(ImportJob $job, array $schema): array
    {
        $sections = [];
        $fieldCount = 0;
        $importedKeys = [];

        foreach ($schema['sections'] ?? [] as $section) {
            $fields = [];

            foreach ($section['fields'] ?? [] as $field) {
                $type = FieldType::tryFrom((string) $field['type']) ?? FieldType::Text;
                $fieldCount++;
                $importedKeys[] = (string) $field['key'];

                $fields[] = [
                    'label' => (string) $field['label'],
                    'key' => (string) $field['key'],
                    'type' => $type->value,
                    'required' => (bool) ($field['required'] ?? false),
                    'options' => array_map(
                        fn (array $option): string => (string) $option['label'],
                        $field['options'] ?? []
                    ),
                    'validation' => $this->describeValidation($field['validation'] ?? []),
                ];
            }

            $sections[] = [
                'title' => (string) $section['title'],
                'fields' => $fields,
            ];
        }

        return [
            'filename' => $job->original_filename,
            'source' => $job->source->value,
            'title' => (string) ($schema['title'] ?? ''),
            'description' => (string) ($schema['description'] ?? ''),
            'field_count' => $fieldCount,
            'sections' => $sections,
            'warnings' => $this->warningsFor($job, $fieldCount, $importedKeys),
        ];
    }

    /**
     * @param  list<string>  $importedKeys
     * @return list<string>
     */
    private function warningsFor(ImportJob $job, int $fieldCount, array $importedKeys): array
    {
        $warnings = [];

        if ($fieldCount === 0) {
            $warnings[] = 'No fields could be identified in this document, so accepting it would leave an empty form.';
        }

        $existing = [];

        foreach ($job->form?->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $key = (string) ($field['key'] ?? '');

                if ($key !== '' && ! in_array($key, $importedKeys, true)) {
                    $existing[] = $key;
                }
            }
        }

        if ($existing !== []) {
            // An import replaces the schema, so answers filed under a key the
            // import does not reproduce stop appearing beside the live fields.
            $warnings[] = 'Accepting this replaces the current form. These fields are not in the import and any answers already collected under them will be left behind: '
                .implode(', ', array_slice($existing, 0, 20)).'.';
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $validation
     */
    private function describeValidation(array $validation): string
    {
        $parts = [];

        foreach ($validation as $rule => $value) {
            if ($value === null || $value === [] || $value === '') {
                continue;
            }

            $parts[] = str_replace('_', ' ', (string) $rule).' '
                .(is_array($value) ? implode('/', array_map('strval', $value)) : (string) $value);
        }

        return implode(', ', $parts);
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
        $log->forceFill([
            'response_raw' => $result['raw'],
            'model' => $result['model'] ?? $log->model,
            'prompt_tokens' => (int) $log->prompt_tokens + (int) $result['prompt_tokens'],
            'completion_tokens' => (int) $log->completion_tokens + (int) $result['completion_tokens'],
            'latency_ms' => (int) $log->latency_ms + $result['latency_ms'],
        ])->save();
    }

    /**
     * import_jobs has no columns for model, tokens, latency or attempts, so the
     * AI cost of an import is recorded where every other AI cost is recorded.
     *
     * prompt is NOT NULL and an import has no typed prompt, so it holds the
     * bounded extraction: it is the effective prompt, and it is the only thing
     * that keeps a failed inference debuggable once the file is deleted.
     *
     * @param  array<string, mixed>  $extraction
     */
    private function openLog(ImportJob $job, array $extraction): AiGenerationLog
    {
        return AiGenerationLog::query()->create([
            'user_id' => $job->user_id,
            'form_id' => $job->form_id,
            'type' => GenerationType::ImportInfer,
            'prompt' => $this->encode($extraction),
            'status' => GenerationStatus::Processing,
        ]);
    }

    /**
     * Shrink the extraction until its encoded form fits the configured cap.
     *
     * The parsers already bound rows, columns and cell length; this is the
     * backstop for a document that stays under every individual limit while
     * still adding up to more prompt than we are willing to pay for.
     *
     * @param  array<string, mixed>  $extraction
     * @return array<string, mixed>
     */
    private function boundPayload(array $extraction): array
    {
        $max = max(1000, (int) config('formforge.import.max_payload_chars', 24000));

        // Sample rows are evidence rather than content, so they are the first
        // thing to go: dropping them costs type hints, not fields.
        foreach (['sheets', 'tables'] as $group) {
            foreach (array_keys($extraction[$group] ?? []) as $index) {
                while (strlen($this->encode($extraction)) > $max
                    && ($extraction[$group][$index]['rows'] ?? []) !== []) {
                    array_pop($extraction[$group][$index]['rows']);
                }
            }
        }

        foreach (['paragraphs', 'headings', 'tables', 'sheets'] as $group) {
            while (strlen($this->encode($extraction)) > $max && ($extraction[$group] ?? []) !== []) {
                array_pop($extraction[$group]);
            }
        }

        return $extraction;
    }

    private function absolutePath(ImportJob $job, ImportArchiveGuard $guard): string
    {
        $disk = Storage::disk($guard->disk());

        if (! $disk->exists($job->disk_path)) {
            throw new RuntimeException('The uploaded file is no longer available. Please upload it again.');
        }

        return $disk->path($job->disk_path);
    }

    /**
     * Whether this row is still ours to finish.
     */
    private function stillProcessing(): bool
    {
        return ImportJob::query()->find($this->importJobId)?->status === ImportStatus::Processing;
    }

    /**
     * Stop without touching the status, because whoever changed it owns it now.
     */
    private function abandon(ImportJob $job): void
    {
        $this->deleteFile($job);
    }

    private function markFailed(ImportJob $job, string $message): void
    {
        $job->forceFill([
            'status' => ImportStatus::Failed,
            'errors' => ['message' => $message],
            'preview' => null,
            'final_schema' => null,
        ])->save();

        $this->deleteFile($job);
    }

    private function deleteFile(ImportJob $job): void
    {
        try {
            Storage::disk((string) config('formforge.uploads.disk', 'local'))->delete($job->disk_path);
        } catch (Throwable) {
            // Cleanup is best effort, and the hourly pruner sweeps what is left.
        }
    }

    private function isTerminal(ImportJob $job): bool
    {
        return in_array($job->status, [
            ImportStatus::Preview,
            ImportStatus::Committed,
            ImportStatus::Failed,
        ], true);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function summarize(array $errors): string
    {
        $first = collect($errors)->flatten()->first();

        return 'The document could not be turned into a valid form: '
            .(is_string($first) ? $first : 'the schema was rejected.');
    }

    private function safeMessage(Throwable $exception): string
    {
        // AiService, the archive guard and both parsers all raise safe
        // sentences. Anything else is internal and must not reach the browser.
        return $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'The document could not be imported. Please try again.';
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

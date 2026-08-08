<?php

namespace App\Services;

use App\Enums\FieldType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transport layer between FormForge and the AI provider.
 *
 * This class owns provider communication, request construction, response and
 * JSON extraction, and model/token/latency reporting. It owns no persistence:
 * it never touches a Form, a FormVersion, or a log row, and it never decides
 * whether a candidate schema is acceptable. That judgement belongs to
 * SchemaService and to the job that calls this.
 */
class AiService
{
    /** Cap on how much of a response is handed back for storage. */
    private const MAX_RAW_BYTES = 65536;

    public function isConfigured(): bool
    {
        return trim((string) config('formforge.ai.api_key')) !== '';
    }

    /**
     * Ask for a brand new schema from a natural language description.
     *
     * @return array{candidate: array<string, mixed>|null, raw: string, model: ?string, prompt_tokens: ?int, completion_tokens: ?int, latency_ms: int}
     */
    public function generateSchema(string $prompt): array
    {
        return $this->complete(
            $this->schemaContract()."\n\n".$this->generateRules(),
            'Build a form for this request, and reply with the JSON schema only:'."\n\n".$prompt,
        );
    }

    /**
     * Ask for an edited copy of an existing schema.
     *
     * @param  array<string, mixed>  $currentSchema
     * @return array{candidate: array<string, mixed>|null, raw: string, model: ?string, prompt_tokens: ?int, completion_tokens: ?int, latency_ms: int}
     */
    public function editSchema(array $currentSchema, string $instruction): array
    {
        return $this->complete(
            $this->schemaContract()."\n\n".$this->editRules(),
            'Current schema:'."\n\n".$this->encode($currentSchema)
                ."\n\nInstruction:\n\n".$instruction
                ."\n\nReply with the complete updated JSON schema only.",
        );
    }

    /**
     * Hand a rejected response back with the reasons it was rejected.
     *
     * @param  array<string, list<string>>  $errors
     * @return array{candidate: array<string, mixed>|null, raw: string, model: ?string, prompt_tokens: ?int, completion_tokens: ?int, latency_ms: int}
     */
    public function repairSchema(string $malformedRaw, array $errors, string $originalPrompt): array
    {
        $lines = [];

        foreach ($errors as $path => $messages) {
            foreach ((array) $messages as $message) {
                $lines[] = "- {$path}: {$message}";
            }
        }

        return $this->complete(
            $this->schemaContract()."\n\n".$this->repairRules(),
            'The original request was:'."\n\n".$originalPrompt
                ."\n\nYour previous reply was rejected:\n\n".$this->truncate($malformedRaw)
                ."\n\nIt failed these checks:\n\n".implode("\n", $lines)
                ."\n\nReply with the corrected complete JSON schema only.",
        );
    }

    /**
     * @return array{candidate: array<string, mixed>|null, raw: string, model: ?string, prompt_tokens: ?int, completion_tokens: ?int, latency_ms: int}
     */
    private function complete(string $system, string $user): array
    {
        $startedAt = microtime(true);

        try {
            $response = Http::withToken((string) config('formforge.ai.api_key'))
                ->timeout((int) config('formforge.ai.timeout_seconds', 60))
                ->acceptJson()
                ->post(rtrim((string) config('formforge.ai.base_url'), '/').'/chat/completions', [
                    'model' => config('formforge.ai.model'),
                    'temperature' => (float) config('formforge.ai.temperature', 0.2),
                    'max_tokens' => (int) config('formforge.ai.max_tokens', 4096),
                    // JSON mode guarantees the body parses. It does not
                    // guarantee the body is a FormForge schema, which is why
                    // every response still crosses the gates in the job.
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ])
                ->throw();
        } catch (ConnectionException) {
            throw new RuntimeException('The AI provider did not respond in time.');
        } catch (RequestException $exception) {
            // The provider body can echo the request, so it never reaches a
            // user-facing string. Only the status shapes the message.
            throw new RuntimeException($this->messageForStatus($exception->response->status()));
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('The AI provider returned an unreadable response.');
        }

        $raw = (string) data_get($body, 'choices.0.message.content', '');

        return [
            'candidate' => $this->extractSchema($raw),
            'raw' => $this->truncate($raw),
            'model' => is_string($model = data_get($body, 'model')) ? $model : null,
            'prompt_tokens' => $this->intOrNull(data_get($body, 'usage.prompt_tokens')),
            'completion_tokens' => $this->intOrNull(data_get($body, 'usage.completion_tokens')),
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * Pull a schema object out of whatever the model actually said.
     *
     * Only transport artifacts are undone here: code fences, prose around the
     * object, and a wrapper key. Nothing about the schema's meaning is altered,
     * because a repair that changes meaning belongs to the model, not to us.
     *
     * @return array<string, mixed>|null
     */
    public function extractSchema(string $raw): ?array
    {
        $text = trim($raw);

        if ($text === '') {
            return null;
        }

        // ```json ... ``` or ``` ... ```
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $matches) === 1) {
            $text = trim($matches[1]);
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            // Prose on either side of the object.
            $first = strpos($text, '{');
            $last = strrpos($text, '}');

            if ($first === false || $last === false || $last <= $first) {
                return null;
            }

            $decoded = json_decode(substr($text, $first, $last - $first + 1), true);
        }

        if (! is_array($decoded)) {
            return null;
        }

        return $this->unwrapEnvelope($decoded);
    }

    /**
     * Undo a {"schema": {...}} or {"form": {...}} wrapper.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function unwrapEnvelope(array $decoded): array
    {
        if (isset($decoded['sections']) || isset($decoded['title'])) {
            return $decoded;
        }

        foreach (['schema', 'form', 'data', 'result'] as $wrapper) {
            $inner = $decoded[$wrapper] ?? null;

            if (is_array($inner) && (isset($inner['sections']) || isset($inner['title']))) {
                return $inner;
            }
        }

        return $decoded;
    }

    private function messageForStatus(int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'The AI provider rejected the credentials for this server.',
            $status === 429 => 'The AI provider is rate limiting this server. Try again shortly.',
            $status >= 500 => 'The AI provider is unavailable. Try again shortly.',
            default => 'The AI provider rejected the request.',
        };
    }

    /**
     * The canonical schema contract, generated from FieldType so it can never
     * drift from the enum.
     */
    private function schemaContract(): string
    {
        $types = implode(', ', FieldType::values());
        $choiceTypes = implode(', ', array_map(
            fn (FieldType $type): string => $type->value,
            array_filter(FieldType::cases(), fn (FieldType $type): bool => $type->requiresOptions()),
        ));

        return <<<PROMPT
        You produce JSON form definitions for FormForge. Reply with a single JSON
        object and nothing else: no markdown, no code fences, no commentary.

        The object has exactly these root keys:

        - "schema_version": integer, always 1
        - "title": non-empty string
        - "description": string, may be empty
        - "settings": object with "multi_step" (boolean), "submit_button_text"
          (string) and "success_message" (string)
        - "sections": array of section objects

        A section object has "title" (non-empty string), "description" (string or
        null) and "fields" (array of field objects).

        A field object has:

        - "type": one of exactly these values and nothing else: {$types}
        - "key": lowercase snake_case matching ^[a-z0-9_]+$, unique across the
          entire form, and stable because stored answers are filed under it
        - "label": non-empty human readable string
        - "required": boolean
        - "placeholder", "help_text": optional strings
        - "options": required and non-empty for {$choiceTypes}, omitted or empty
          for every other type. Each option is an object with a non-empty
          "value" (snake_case) and a non-empty "label".
        - "validation": optional object with any of "min", "max", "min_length",
          "max_length", "regex", "file_types" (array of extensions),
          "max_file_size_kb"

        Hard rules:

        - Never invent a field type outside the list above. There is no signature,
          slider, address, time, url, currency, or section type.
        - Never emit two fields with the same "key".
        - "heading" is display only: give it a "label" and nothing else.
        - A regex must be a valid PCRE pattern.
        PROMPT;
    }

    private function generateRules(): string
    {
        return <<<'PROMPT'
        Build the form the user describes. Choose the field type that best fits
        each requested item, give every field a clear label and a snake_case key
        derived from that label, and mark a field required only when the request
        implies it. Group related fields into sections when the request suggests
        more than one grouping, otherwise use a single section.
        PROMPT;
    }

    private function editRules(): string
    {
        return <<<'PROMPT'
        You are editing an existing form. Return the complete updated schema, not
        a patch and not a description of your changes.

        Key preservation is absolute:

        - Every field you keep must carry its original "id" and its original
          "key", both copied byte for byte from the current schema.
        - Never change the "key" of a field you are keeping. Stored answers are
          filed under that key and renaming it orphans them.
        - Changing a field's label never requires changing its key.
        - To intentionally replace a field with a different key, omit the original
          field entirely and add a new field with no "id" at all. This signals
          that the old answers are being abandoned on purpose.
        - New fields you add must omit "id" so the server can assign one.

        Apply only what the instruction asks for. Leave every other field, its
        order, its options, its validation, and the form settings exactly as they
        were.
        PROMPT;
    }

    private function repairRules(): string
    {
        return <<<'PROMPT'
        Your previous reply was rejected by the validator. Fix only the listed
        problems and return the complete corrected schema.

        Do not work around a rejection by deleting the offending field, renaming
        its key, or changing its type to something easier. If a field type was
        rejected, choose the closest type from the allowed list. If a key was
        rejected for changing, restore the exact original key.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function encode(array $schema): string
    {
        return (string) json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function truncate(string $value): string
    {
        return strlen($value) > self::MAX_RAW_BYTES
            ? substr($value, 0, self::MAX_RAW_BYTES)
            : $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

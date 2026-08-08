<?php

namespace App\Rules;

use App\Services\SchemaService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;

class ValidFormSchema implements ValidationRule
{
    public function __construct(
        private readonly SchemaService $schemas = new SchemaService
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (! is_array($decoded)) {
                $fail('The :attribute must be a valid JSON schema object.');

                return;
            }
            $value = $decoded;
        }

        if (! is_array($value)) {
            $fail('The :attribute must be a schema array or JSON object.');

            return;
        }

        foreach (['schema_version', 'title', 'settings', 'sections'] as $root) {
            if (! array_key_exists($root, $value)) {
                $fail("The {$root} key is required.");
            }
        }

        if (collect(['schema_version', 'title', 'settings', 'sections'])
            ->contains(fn (string $root) => ! array_key_exists($root, $value))) {
            return;
        }

        try {
            $normalized = $this->schemas->normalize($value);
            $this->schemas->assertValid($normalized);
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->unique()->values();
            foreach ($messages as $message) {
                $fail($message);
            }
        }
    }
}

<?php

namespace App\Services;

use App\Enums\FieldType;

class ValidationService
{
    /**
     * Canonical supported field types for Builder, AI, and Import.
     *
     * @return list<string>
     */
    public function supportedTypes(): array
    {
        return FieldType::values();
    }

    /**
     * Build Laravel validation rules from schema.
     * Conditions are ignored in Phase 3 (all fields treated as visible).
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $input
     * @return array<string, list<string>>
     */
    public function rulesFromSchema(array $schema, array $input = []): array
    {
        $rules = [];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $type = FieldType::tryFrom((string) ($field['type'] ?? ''));
                if ($type === null || ! $type->isSubmittable()) {
                    continue;
                }

                $key = (string) ($field['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                foreach ($this->rulesForField($key, $field, $type) as $ruleKey => $ruleList) {
                    $rules[$ruleKey] = $ruleList;
                }
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, string>
     */
    public function messagesFromSchema(array $schema): array
    {
        $messages = [];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $key = (string) ($field['key'] ?? '');
                $label = (string) ($field['label'] ?? $key);
                if ($key === '') {
                    continue;
                }

                $messages["{$key}.required"] = "{$label} is required.";
                $messages["{$key}.email"] = "{$label} must be a valid email address.";
                $messages["{$key}.numeric"] = "{$label} must be a number.";
                $messages["{$key}.date"] = "{$label} must be a valid date.";
                $messages["{$key}.in"] = "{$label} contains an invalid selection.";
                $messages["{$key}.*.in"] = "{$label} contains an invalid selection.";
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, string>
     */
    public function attributesFromSchema(array $schema): array
    {
        $attributes = [];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $key = (string) ($field['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                $attributes[$key] = (string) ($field['label'] ?? $key);
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, list<string>>
     */
    private function rulesForField(string $key, array $field, FieldType $type): array
    {
        $required = (bool) ($field['required'] ?? false);
        $presence = $required ? 'required' : 'nullable';
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];

        if ($type === FieldType::Checkbox) {
            $out = [$key => [$presence, 'array']];
            $csv = $this->optionValuesCsv($field);
            if ($csv !== '') {
                $out[$key.'.*'] = ['string', 'in:'.$csv];
            }

            return $out;
        }

        $rules = [$presence];

        switch ($type) {
            case FieldType::Text:
            case FieldType::Textarea:
            case FieldType::Phone:
                $rules[] = 'string';
                $this->appendLengthRules($rules, $validation);
                $this->appendRegexRule($rules, $validation);
                break;

            case FieldType::Email:
                $rules[] = 'email';
                $this->appendLengthRules($rules, $validation);
                break;

            case FieldType::Number:
                $rules[] = 'numeric';
                if (($validation['min'] ?? null) !== null && $validation['min'] !== '') {
                    $rules[] = 'min:'.$validation['min'];
                }
                if (($validation['max'] ?? null) !== null && $validation['max'] !== '') {
                    $rules[] = 'max:'.$validation['max'];
                }
                break;

            case FieldType::Date:
                $rules[] = 'date';
                break;

            case FieldType::Dropdown:
            case FieldType::Radio:
                $rules[] = 'string';
                $csv = $this->optionValuesCsv($field);
                if ($csv !== '') {
                    $rules[] = 'in:'.$csv;
                }
                break;

            case FieldType::File:
                $rules[] = 'file';
                $mimes = $validation['file_types'] ?? [];
                if (! is_array($mimes) || $mimes === []) {
                    $mimes = config('formforge.uploads.allowed_mimes', []);
                }
                if ($mimes !== []) {
                    $rules[] = 'mimes:'.implode(',', $mimes);
                }
                $maxKb = $validation['max_file_size_kb'] ?? config('formforge.uploads.max_file_size_kb');
                if ($maxKb) {
                    $rules[] = 'max:'.(int) $maxKb;
                }
                break;

            case FieldType::Rating:
                $rules[] = 'integer';
                $rules[] = 'min:'.(int) ($validation['min'] ?? 1);
                $rules[] = 'max:'.(int) ($validation['max'] ?? 5);
                break;

            default:
                break;
        }

        return [$key => $rules];
    }

    /**
     * @param  list<string>  $rules
     * @param  array<string, mixed>  $validation
     */
    private function appendLengthRules(array &$rules, array $validation): void
    {
        if (($validation['min_length'] ?? null) !== null && $validation['min_length'] !== '') {
            $rules[] = 'min:'.(int) $validation['min_length'];
        }
        if (($validation['max_length'] ?? null) !== null && $validation['max_length'] !== '') {
            $rules[] = 'max:'.(int) $validation['max_length'];
        }
    }

    /**
     * @param  list<string>  $rules
     * @param  array<string, mixed>  $validation
     */
    private function appendRegexRule(array &$rules, array $validation): void
    {
        $regex = $validation['regex'] ?? null;
        if (! is_string($regex) || $regex === '') {
            return;
        }

        if (strlen($regex) >= 2 && str_starts_with($regex, '/') && str_ends_with($regex, '/')) {
            $rules[] = 'regex:'.$regex;
        } else {
            $rules[] = 'regex:/'.str_replace('/', '\/', $regex).'/';
        }
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function optionValuesCsv(array $field): string
    {
        $values = [];

        foreach ($field['options'] ?? [] as $option) {
            if (is_array($option) && isset($option['value'])) {
                $values[] = (string) $option['value'];
            }
        }

        return implode(',', $values);
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FormForge AI Configuration
    |--------------------------------------------------------------------------
    |
    | Central knobs for uploads, AI, cache, queues, exports, and public forms.
    | Field types live in App\Enums\FieldType (single source of truth) — do not
    | duplicate them here.
    |
    */

    'uploads' => [
        'disk' => env('FORMFORGE_UPLOAD_DISK', 'local'),
        'submission_dir' => 'submissions',
        'import_dir' => 'imports',
        'max_file_size_kb' => (int) env('FORMFORGE_MAX_FILE_SIZE_KB', 5120),
        'allowed_mimes' => ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg'],
    ],

    'ai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('FORMFORGE_AI_MODEL', 'gpt-4o-mini'),
        'temperature' => (float) env('FORMFORGE_AI_TEMPERATURE', 0.2),
        'max_tokens' => (int) env('FORMFORGE_AI_MAX_TOKENS', 4096),
        // The number of REPAIR calls allowed after the initial call, not the
        // total call budget: 3 here means at most 4 provider calls.
        'max_repair_attempts' => (int) env('FORMFORGE_AI_MAX_REPAIR_ATTEMPTS', 3),
        'timeout_seconds' => (int) env('FORMFORGE_AI_TIMEOUT', 60),
        'max_prompt_chars' => (int) env('FORMFORGE_AI_MAX_PROMPT_CHARS', 2000),
    ],

    'import' => [
        // The disk and directory are shared with uploads: config('formforge.uploads.disk')
        // and config('formforge.uploads.import_dir').
        'max_file_size_kb' => (int) env('FORMFORGE_IMPORT_MAX_FILE_SIZE_KB', 10240),
        'allowed_extensions' => ['docx', 'xlsx'],

        // Bounds on what leaves the parser, so no document can grow the prompt
        // without limit.
        'max_paragraphs' => 300,
        'max_headings' => 100,
        'max_tables' => 20,
        'max_table_rows' => 50,
        'max_sheets' => 5,
        'max_rows' => 50,
        'max_columns' => 60,
        'max_cell_chars' => 300,
        'max_payload_chars' => 24000,

        // How long a queued/processing row may sit before the pruner reclaims it.
        'stale_after_minutes' => (int) env('FORMFORGE_IMPORT_STALE_AFTER_MINUTES', 60),

        'archive' => [
            'max_entries' => (int) env('FORMFORGE_IMPORT_MAX_ARCHIVE_ENTRIES', 512),
            'max_uncompressed_bytes' => (int) env('FORMFORGE_IMPORT_MAX_UNCOMPRESSED_BYTES', 67108864),
        ],
    ],

    'cache' => [
        'schema_ttl_seconds' => (int) env('FORMFORGE_SCHEMA_CACHE_TTL', 3600),
        'schema_key' => 'form:schema:%s:v%s',
    ],

    'queue' => [
        'ai' => env('FORMFORGE_QUEUE_AI', 'default'),
        'import' => env('FORMFORGE_QUEUE_IMPORT', 'default'),
        'export' => env('FORMFORGE_QUEUE_EXPORT', 'default'),
        'default' => env('FORMFORGE_QUEUE_DEFAULT', 'default'),
    ],

    'export' => [
        'sync_max_rows' => (int) env('FORMFORGE_EXPORT_SYNC_MAX_ROWS', 500),
        'csv_chunk_size' => (int) env('FORMFORGE_EXPORT_CSV_CHUNK', 200),
    ],

    'public' => [
        'submit_rate_limit_per_minute' => (int) env('FORMFORGE_PUBLIC_RATE_LIMIT', 10),
        'log_submissions_in_activity' => (bool) env('FORMFORGE_LOG_SUBMISSIONS', false),
    ],

    'activity' => [
        'enabled' => (bool) env('FORMFORGE_ACTIVITY_ENABLED', true),
    ],

];

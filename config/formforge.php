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
        'model' => env('FORMFORGE_AI_MODEL', 'gpt-4o-mini'),
        'temperature' => (float) env('FORMFORGE_AI_TEMPERATURE', 0.2),
        'max_tokens' => (int) env('FORMFORGE_AI_MAX_TOKENS', 4096),
        'max_repair_attempts' => (int) env('FORMFORGE_AI_MAX_REPAIR_ATTEMPTS', 3),
        'timeout_seconds' => (int) env('FORMFORGE_AI_TIMEOUT', 60),
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

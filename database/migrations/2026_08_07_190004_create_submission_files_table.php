<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('form_submission_id')->constrained('form_submissions')->cascadeOnDelete();
            $table->string('field_key');
            $table->string('original_name');
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->timestamp('created_at')->useCurrent();

            // Eager-load files per submission
            $table->index(['form_submission_id']);
            // Resolve file(s) for a field
            $table->index(['form_submission_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_files');
    }
};

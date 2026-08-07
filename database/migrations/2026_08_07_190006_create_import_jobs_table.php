<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('form_id')->nullable()->constrained('forms')->nullOnDelete();
            $table->string('source');
            $table->string('original_filename');
            $table->string('disk_path');
            $table->string('status');
            $table->json('preview')->nullable();
            $table->json('errors')->nullable();
            $table->json('final_schema')->nullable();
            $table->timestamps();

            // User import list / poll
            $table->index(['user_id', 'status']);
            // Queue monitoring
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};

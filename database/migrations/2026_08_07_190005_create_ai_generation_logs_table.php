<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generation_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('form_id')->nullable()->constrained('forms')->nullOnDelete();
            $table->string('type');
            $table->text('prompt');
            $table->string('status');
            $table->string('model')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->longText('response_raw')->nullable();
            $table->json('schema_result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            // User AI history
            $table->index(['user_id', 'created_at']);
            // Poll by form + status
            $table->index(['form_id', 'status']);
            // Stuck-job / worker monitoring
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generation_logs');
    }
};

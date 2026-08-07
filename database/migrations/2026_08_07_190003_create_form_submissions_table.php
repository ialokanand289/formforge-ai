<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('form_id')->constrained('forms')->cascadeOnDelete();
            // Historical schema version at submit time — not an FK
            $table->unsignedInteger('form_version');
            $table->json('payload');
            $table->text('search_text')->nullable();
            $table->string('status');
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            // Paginated submission list (newest first)
            $table->index(['form_id', 'created_at']);
            // Filter spam / completed
            $table->index(['form_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};

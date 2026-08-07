<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('slug');
            $table->uuid('public_token')->unique();
            $table->string('status');
            $table->json('schema');
            $table->unsignedInteger('schema_version')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
            // Dashboard filtered lists by owner + status
            $table->index(['user_id', 'status']);
            // Dashboard sorted lists by owner + recency
            $table->index(['user_id', 'updated_at']);
            // Published inventory / ops queries
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};

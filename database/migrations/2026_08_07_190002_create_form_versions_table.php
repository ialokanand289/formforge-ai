<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('form_id')->constrained('forms')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('schema');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // Version identity per form
            $table->unique(['form_id', 'version']);
            // History timeline
            $table->index(['form_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_versions');
    }
};

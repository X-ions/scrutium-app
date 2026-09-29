<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publishing_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_post_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->string('status', 20)->default('pending');
            // Payloads are sanitized upstream; no credentials are stored here.
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('rate_limit_reset_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['post_variant_id', 'attempt_number'], 'publishing_attempts_variant_attempt_index');
            $table->index(['status', 'started_at'], 'publishing_attempts_status_started_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publishing_attempts');
    }
};

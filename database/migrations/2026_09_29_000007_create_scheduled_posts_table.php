<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_variant_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('timezone', 50)->default('UTC');
            $table->string('status', 20)->default('pending');
            $table->string('job_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('post_variant_id', 'scheduled_posts_variant_schedule_unique');
            $table->index(['scheduled_at', 'status'], 'scheduled_posts_scheduled_at_status_index');
            $table->index('job_id', 'scheduled_posts_job_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_posts');
    }
};

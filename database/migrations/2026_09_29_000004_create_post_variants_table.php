<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->text('caption')->nullable();
            $table->json('media_ids')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('mentions')->nullable();
            $table->string('location_id')->nullable();
            $table->string('location_name')->nullable();
            $table->json('platform_specific')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('provider_post_id')->nullable();
            $table->string('provider_post_url', 500)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['post_id', 'social_account_id'], 'post_variants_post_account_unique');
            $table->index(['post_id', 'status'], 'post_variants_post_status_index');
            $table->index(['social_account_id', 'scheduled_at'], 'post_variants_account_scheduled_index');
            $table->index('provider_post_id', 'post_variants_provider_post_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_variants');
    }
};

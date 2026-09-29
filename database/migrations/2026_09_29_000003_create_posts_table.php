<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status'], 'posts_tenant_status_index');
            $table->index(['tenant_id', 'user_id'], 'posts_tenant_user_index');
            $table->index('campaign_id', 'posts_campaign_index');
            $table->index('published_at', 'posts_published_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_comment_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->string('provider', 50);
            $table->string('provider_comment_id');
            $table->string('author_provider_id');
            $table->string('author_username')->nullable();
            $table->string('author_display_name')->nullable();
            $table->string('author_avatar_url', 500)->nullable();
            $table->text('content');
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('reply_count')->default(0);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('provider_created_at');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider', 'provider_comment_id'], 'comments_unique_provider_comment');
            $table->index(['post_variant_id', 'provider_created_at'], 'comments_variant_created_index');
            $table->index(['social_account_id', 'provider_created_at'], 'comments_account_created_index');
            $table->index('parent_comment_id', 'comments_parent_index');
            $table->index('is_hidden', 'comments_hidden_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};

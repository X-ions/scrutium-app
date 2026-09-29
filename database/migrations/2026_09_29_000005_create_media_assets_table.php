<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('filename');
            $table->string('stored_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('duration', 10, 3)->nullable();
            $table->string('storage_disk', 50)->default('local');
            $table->string('storage_path', 500);
            $table->string('thumbnail_path', 500)->nullable();
            $table->string('alt_text')->nullable();
            $table->json('tags')->nullable();
            $table->string('folder')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'mime_type'], 'media_assets_tenant_type_index');
            $table->index(['tenant_id', 'folder'], 'media_assets_tenant_folder_index');
            $table->index('created_at', 'media_assets_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};

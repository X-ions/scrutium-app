<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named socialhub_notifications rather than "notifications" so the table does not
        // collide with Laravel's built-in database notification driver table of the same name.
        Schema::create('socialhub_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();
            $table->string('action_url', 500)->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'is_read'], 'socialhub_notifications_tenant_user_read_index');
            $table->index(['user_id', 'created_at'], 'socialhub_notifications_user_created_index');
            $table->index('type', 'socialhub_notifications_type_index');
            $table->index('expires_at', 'socialhub_notifications_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('socialhub_notifications');
    }
};

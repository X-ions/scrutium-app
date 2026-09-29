<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->text('content');
            $table->string('provider_reply_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['comment_id', 'status'], 'comment_replies_comment_status_index');
            $table->index(['provider', 'provider_reply_id'], 'comment_replies_provider_reply_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_replies');
    }
};

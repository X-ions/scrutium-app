<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_account_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            // Ciphertext only: the model casts these columns with `encrypted`.
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->text('id_token')->nullable();
            $table->string('token_type', 50)->default('Bearer');
            $table->timestamp('expires_at')->nullable();
            $table->text('scope')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['social_account_id', 'expires_at'], 'social_account_tokens_account_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_account_tokens');
    }
};

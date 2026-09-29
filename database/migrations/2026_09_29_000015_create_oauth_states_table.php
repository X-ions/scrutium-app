<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('state');
            $table->string('code_verifier')->nullable();
            $table->string('redirect_url', 500)->nullable();
            $table->text('scopes')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();

            $table->unique('state', 'oauth_states_state_unique');
            $table->index('expires_at', 'oauth_states_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_states');
    }
};

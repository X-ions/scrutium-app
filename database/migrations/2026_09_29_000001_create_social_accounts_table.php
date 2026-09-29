<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('provider_account_id');
            $table->string('provider_username')->nullable();
            $table->string('provider_display_name')->nullable();
            $table->string('provider_avatar_url', 500)->nullable();
            $table->string('account_type', 20)->default('personal');
            $table->string('status', 20)->default('disconnected');
            $table->json('permissions')->nullable();
            $table->json('capabilities')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'provider', 'provider_account_id'], 'social_accounts_tenant_provider_account_unique');
            $table->index(['tenant_id', 'provider'], 'social_accounts_tenant_provider_index');
            $table->index('status', 'social_accounts_status_index');
            $table->index(['tenant_id', 'is_default'], 'social_accounts_tenant_default_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};

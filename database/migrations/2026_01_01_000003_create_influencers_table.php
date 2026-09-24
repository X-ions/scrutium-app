<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influencers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('handle');
            $table->string('platform');
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('avatar_url')->nullable();
            $table->unsignedBigInteger('followers')->default(0);
            $table->decimal('engagement_rate', 5, 2)->default(0);
            $table->decimal('pulse_score', 5, 2)->nullable();
            $table->string('tier')->default('nano');
            $table->string('vetting_status')->default('sourced');
            $table->text('notes')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform', 'handle']);
            $table->index(['tenant_id', 'vetting_status']);
            $table->index(['tenant_id', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('influencers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influencer_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('influencer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('score_config_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('score', 6, 2);
            $table->json('components')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index(['influencer_id', 'computed_at']);
            $table->index(['tenant_id', 'computed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('influencer_scores');
    }
};

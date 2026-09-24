<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('influencer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deliverable_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform');
            $table->string('external_id')->nullable();
            $table->string('url');
            $table->text('caption')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('saves')->default(0);
            $table->decimal('engagement_rate', 6, 2)->default(0);
            $table->decimal('provenance_score', 5, 2)->nullable();
            $table->boolean('has_disclosure')->default(false);
            $table->boolean('has_captions')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform', 'external_id']);
            $table->index(['tenant_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_posts');
    }
};

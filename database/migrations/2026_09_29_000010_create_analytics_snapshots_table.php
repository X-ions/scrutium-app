<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('post_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 50)->nullable();
            $table->date('date');
            $table->string('period', 10)->default('daily');
            $table->json('metrics');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'social_account_id', 'post_variant_id', 'provider', 'date', 'period'],
                'analytics_snapshots_unique_snapshot'
            );
            $table->index(['tenant_id', 'date', 'period'], 'analytics_snapshots_tenant_date_index');
            $table->index(['social_account_id', 'date', 'period'], 'analytics_snapshots_account_date_index');
            $table->index(['post_variant_id', 'date', 'period'], 'analytics_snapshots_variant_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_snapshots');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DATABASE.md declares this table MySQL-partitioned by RANGE (TO_DAYS(period_start)).
        // SQLite and PostgreSQL have no such partitioning, so no raw PARTITION BY DDL is
        // emitted here; partition maintenance is delegated to a MySQL-only maintenance job.
        Schema::create('analytics_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('metric_type', 100);
            $table->string('metric_subtype', 100)->nullable();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->bigInteger('value')->default(0);
            $table->json('raw_response')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(
                ['tenant_id', 'social_account_id', 'post_variant_id', 'provider', 'metric_type', 'metric_subtype', 'period_start'],
                'analytics_metrics_unique_metric'
            );
            $table->index(['tenant_id', 'social_account_id', 'period_start'], 'analytics_metrics_tenant_account_period_index');
            $table->index(['post_variant_id', 'period_start'], 'analytics_metrics_variant_period_index');
            $table->index(['provider', 'metric_type'], 'analytics_metrics_provider_metric_index');
            $table->index('recorded_at', 'analytics_metrics_recorded_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_metrics');
    }
};

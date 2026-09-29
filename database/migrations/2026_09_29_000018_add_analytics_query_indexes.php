<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The analytics read model aggregates `analytics_metrics` three ways, and the
 * original indexes only serve one of them:
 *
 * - headline totals group by `provider`/`metric_type` across a whole tenant and
 *   a date range, which no existing index covers in that column order;
 * - per-post performance joins on `post_variant_id` and filters by
 *   `metric_type` within a period, where the existing index leads with
 *   `period_start` instead.
 *
 * Both are non-unique secondary indexes, so they remain valid on the MySQL
 * partitioned table DATABASE.md describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_metrics', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'period_start', 'metric_type', 'provider'],
                'analytics_metrics_tenant_period_metric_index',
            );

            $table->index(
                ['post_variant_id', 'metric_type', 'period_start'],
                'analytics_metrics_variant_metric_period_index',
            );
        });

        Schema::table('analytics_snapshots', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'period', 'date'],
                'analytics_snapshots_tenant_period_date_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('analytics_snapshots', function (Blueprint $table) {
            $table->dropIndex('analytics_snapshots_tenant_period_date_index');
        });

        Schema::table('analytics_metrics', function (Blueprint $table) {
            $table->dropIndex('analytics_metrics_tenant_period_metric_index');
            $table->dropIndex('analytics_metrics_variant_metric_period_index');
        });
    }
};

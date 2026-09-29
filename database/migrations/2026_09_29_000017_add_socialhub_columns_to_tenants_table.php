<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // tenants.plan already exists from the base Scrutium migration; it is only widened here.
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan', 50)->nullable()->default('free')->change();
            $table->unsignedInteger('social_accounts_limit')->nullable()->default(3);
            $table->unsignedInteger('scheduled_posts_limit')->nullable()->default(10);
            $table->unsignedInteger('team_members_limit')->nullable()->default(5);
            $table->unsignedInteger('analytics_retention_days')->nullable()->default(30);
            $table->unsignedInteger('storage_limit_mb')->nullable()->default(1024);
            $table->unsignedInteger('ai_credits_monthly')->nullable()->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'plan',
                'social_accounts_limit',
                'scheduled_posts_limit',
                'team_members_limit',
                'analytics_retention_days',
                'storage_limit_mb',
                'ai_credits_monthly',
            ]);
        });
    }
};

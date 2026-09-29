<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the comments inbox read model.
 *
 * The inbox query is always "tenant's comments, optionally narrowed by platform
 * and account, ordered newest first, excluding hidden and deleted". The existing
 * indexes cover the variant and account timelines separately, but neither leads
 * with the visibility pair the inbox filters on, so this adds:
 *
 * - `(social_account_id, is_hidden, is_deleted, provider_created_at)` for the
 *   per-platform breakdown and the "unreplied" badge, which is the hottest read
 *   in the engagement UI.
 * - `(tenant_id, provider, provider_created_at)` for the cross-platform grouping,
 *   which aggregates by provider across every account in a workspace.
 *
 * Both are additive and drop cleanly; nothing depends on them for correctness.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->index(
                ['social_account_id', 'is_hidden', 'is_deleted', 'provider_created_at'],
                'comments_inbox_account_visibility_index',
            );

            $table->index(
                ['tenant_id', 'provider', 'provider_created_at'],
                'comments_inbox_tenant_provider_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('comments_inbox_account_visibility_index');
            $table->dropIndex('comments_inbox_tenant_provider_index');
        });
    }
};

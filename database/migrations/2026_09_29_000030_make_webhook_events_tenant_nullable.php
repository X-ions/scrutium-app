<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A delivery that names a social account this installation does not have
 * belongs to no workspace. The event is still recorded so the redelivery can
 * be recognised as a duplicate and so an operator can see it, which means the
 * tenant reference has to be optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('event_id');
            $table->string('event_type', 100);
            $table->json('payload');
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['provider', 'event_id'], 'webhook_events_provider_event_unique');
            $table->index(['tenant_id', 'processed'], 'webhook_events_tenant_processed_index');
            $table->index('created_at', 'webhook_events_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};

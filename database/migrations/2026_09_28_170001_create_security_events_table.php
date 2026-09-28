<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('event_category');
            $table->string('severity')->default('info');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('device_fingerprint', 64)->nullable();
            // Plain column, not a foreign key: the devices table is created by a
            // later migration, and an audit row must survive device deletion.
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('session_id')->nullable();
            $table->string('location_country')->nullable();
            $table->string('location_city')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lon', 10, 7)->nullable();
            $table->string('risk_score')->default('low');
            $table->boolean('is_suspicious')->default(false);
            $table->boolean('notification_sent')->default(false);
            $table->string('notification_id')->nullable();
            $table->json('detection_details')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acknowledgment_action')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index(['device_fingerprint', 'user_id']);
            $table->index(['ip_address', 'occurred_at']);
            $table->index(['is_suspicious', 'occurred_at']);
            $table->index(['notification_sent', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
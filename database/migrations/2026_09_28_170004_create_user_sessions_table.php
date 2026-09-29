<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id', 64)->unique();
            $table->string('ip_address', 45);
            $table->string('user_agent')->nullable();
            $table->string('browser')->nullable();
            $table->string('os')->nullable();
            $table->string('device_type')->nullable();
            $table->string('location_country')->nullable();
            $table->string('location_city')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lon', 10, 7)->nullable();
            $table->boolean('is_current')->default(false);
            $table->boolean('is_revoked')->default(false);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('last_activity_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_current']);
            $table->index(['user_id', 'last_activity_at']);
            $table->index(['session_id']);
            $table->index(['device_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64)->unique();
            $table->string('browser')->nullable();
            $table->string('browser_version')->nullable();
            $table->string('os')->nullable();
            $table->string('os_version')->nullable();
            $table->string('device_type')->nullable();
            $table->string('device_brand')->nullable();
            $table->string('device_model')->nullable();
            $table->json('fingerprint_components')->nullable();
            $table->string('first_ip', 45)->nullable();
            $table->string('first_location_country')->nullable();
            $table->string('first_location_city')->nullable();
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->integer('login_count')->default(0);
            $table->boolean('is_trusted')->default(false);
            $table->timestamp('trusted_at')->nullable();
            $table->foreignId('trusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trust_method')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->timestamp('blocked_at')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('block_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_trusted']);
            $table->index(['user_id', 'last_seen_at']);
            $table->index(['fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
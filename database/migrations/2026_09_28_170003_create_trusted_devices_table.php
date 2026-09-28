<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('alias')->nullable();
            $table->string('browser')->nullable();
            $table->string('os')->nullable();
            $table->string('device_type')->nullable();
            $table->string('trusted_ip', 45)->nullable();
            $table->string('trusted_location_country')->nullable();
            $table->string('trusted_location_city')->nullable();
            $table->string('trust_method');
            $table->string('confirmation_token', 64)->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint']);
            $table->index(['user_id', 'confirmed_at']);
            $table->index(['confirmation_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
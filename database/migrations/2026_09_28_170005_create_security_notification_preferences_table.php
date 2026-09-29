<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Login notifications
            $table->boolean('notify_new_device')->default(true);
            $table->boolean('notify_new_browser')->default(true);
            $table->boolean('notify_new_os')->default(true);
            $table->boolean('notify_new_location')->default(true);
            $table->boolean('notify_impossible_travel')->default(true);
            $table->boolean('notify_suspicious_activity')->default(true);

            // Account change notifications
            $table->boolean('notify_password_change')->default(true);
            $table->boolean('notify_email_change')->default(true);
            $table->boolean('notify_mfa_change')->default(true);
            $table->boolean('notify_recovery_change')->default(true);
            $table->boolean('notify_api_key_change')->default(true);
            $table->boolean('notify_ownership_transfer')->default(true);

            // Security events
            $table->boolean('notify_account_locked')->default(true);
            $table->boolean('notify_failed_attempts')->default(true);
            $table->boolean('notify_high_risk_location')->default(true);

            // Delivery preferences
            $table->boolean('email_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);

            // Sensitivity level: low, medium, high
            $table->string('sensitivity')->default('medium');

            // Rate limiting
            $table->integer('max_emails_per_hour')->default(3);
            $table->integer('max_emails_per_day')->default(10);

            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_notification_preferences');
    }
};

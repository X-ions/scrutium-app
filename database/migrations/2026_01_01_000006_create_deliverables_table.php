<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('influencer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('type')->default('post');
            $table->string('platform');
            $table->unsignedInteger('contracted_units')->default(1);
            $table->unsignedInteger('delivered_units')->default(0);
            $table->decimal('fee', 14, 2)->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('evidence_path')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['campaign_id', 'status']);
            $table->index(['influencer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverables');
    }
};

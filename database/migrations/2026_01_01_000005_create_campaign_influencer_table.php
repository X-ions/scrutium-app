<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_influencer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('influencer_id')->constrained()->cascadeOnDelete();
            $table->string('role')->nullable();
            $table->string('status')->default('invited');
            $table->decimal('agreed_fee', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'influencer_id']);
            $table->index(['influencer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_influencer');
    }
};

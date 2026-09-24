<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('type')->default('performance');
            $table->string('status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->json('payload')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'title', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};

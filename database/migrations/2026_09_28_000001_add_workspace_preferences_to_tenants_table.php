<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamp('name_updated_at')->nullable();
            $table->string('language', 5)->default('en');
            $table->boolean('compact_layout')->default(false);
            $table->boolean('auto_save')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'logo_path',
                'name_updated_at',
                'language',
                'compact_layout',
                'auto_save',
            ]);
        });
    }
};

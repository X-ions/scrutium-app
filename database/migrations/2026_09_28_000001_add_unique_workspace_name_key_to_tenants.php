<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $seenNames = [];

        foreach (DB::table('tenants')->select(['id', 'name'])->orderBy('id')->cursor() as $tenant) {
            $nameKey = mb_strtolower(trim($tenant->name));

            if (isset($seenNames[$nameKey])) {
                throw new RuntimeException(sprintf(
                    'Cannot enforce unique workspace names: "%s" and "%s" match when case is ignored. Rename one before migrating.',
                    $seenNames[$nameKey],
                    $tenant->name,
                ));
            }

            $seenNames[$nameKey] = $tenant->name;
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('name_key', 255)->nullable();
        });

        foreach (DB::table('tenants')->select(['id', 'name'])->orderBy('id')->cursor() as $tenant) {
            DB::table('tenants')->where('id', $tenant->id)->update([
                'name_key' => mb_strtolower(trim($tenant->name)),
            ]);
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('name_key', 255)->nullable(false)->change();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->unique('name_key', 'tenants_name_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique('tenants_name_key_unique');
            $table->dropColumn('name_key');
        });
    }
};

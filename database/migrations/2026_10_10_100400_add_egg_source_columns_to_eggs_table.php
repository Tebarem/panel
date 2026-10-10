<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddEggSourceColumnsToEggsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('eggs', function (Blueprint $table) {
            $table->foreignId('egg_source_id')
                ->nullable()
                ->after('docker_registry_id')
                ->constrained('egg_sources')
                ->nullOnDelete();
            $table->string('egg_source_path', 500)->nullable()->after('egg_source_id');
            $table->index(['egg_source_id', 'egg_source_path'], 'eggs_egg_source_id_egg_source_path_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('eggs', function (Blueprint $table) {
            $table->dropForeign(['egg_source_id']);
            $table->dropIndex('eggs_egg_source_id_egg_source_path_index');
            $table->dropColumn(['egg_source_id', 'egg_source_path']);
        });
    }
}

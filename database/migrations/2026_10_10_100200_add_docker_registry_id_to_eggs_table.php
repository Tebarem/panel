<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddDockerRegistryIdToEggsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('eggs', function (Blueprint $table) {
            $table->foreignId('docker_registry_id')
                ->nullable()
                ->after('file_hidden')
                ->constrained('docker_registries')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('eggs', function (Blueprint $table) {
            $table->dropForeign(['docker_registry_id']);
            $table->dropColumn('docker_registry_id');
        });
    }
}

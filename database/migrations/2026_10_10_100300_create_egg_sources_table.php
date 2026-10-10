<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateEggSourcesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('egg_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // One of "github", "gitlab" or "url".
            $table->string('type', 32);
            $table->string('base_url')->nullable();
            $table->string('repository')->nullable();
            $table->string('branch')->nullable();
            $table->string('path', 500)->nullable();
            // Encrypted using the "encrypted" model cast.
            $table->text('token')->nullable();
            $table->unsignedInteger('nest_id');
            $table->foreign('nest_id')->references('id')->on('nests')->cascadeOnDelete();
            $table->foreignId('docker_registry_id')
                ->nullable()
                ->constrained('docker_registries')
                ->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('egg_sources');
    }
}

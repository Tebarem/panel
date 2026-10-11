<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ramjet_egg_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            // One of "github", "gitlab" or "url".
            $table->string('type', 16);
            $table->string('base_url', 191)->nullable();
            $table->string('repository', 191)->nullable();
            $table->string('branch', 191)->nullable();
            $table->string('path', 500)->nullable();
            // Encrypted with the "encrypted" model cast. Write-only: never returned by the API.
            $table->text('token')->nullable();
            // Slug of the tag attached to every egg imported from this source.
            $table->string('tag', 191)->nullable();
            $table->foreignId('docker_registry_id')
                ->nullable()
                ->constrained('ramjet_docker_registries')
                ->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            // Per-file results of the last completed sync: [{path, status, egg, message}].
            $table->json('last_sync_results')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ramjet_egg_sources');
    }
};

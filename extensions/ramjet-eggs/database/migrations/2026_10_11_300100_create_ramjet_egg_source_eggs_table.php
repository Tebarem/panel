<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Links an egg to the source file it was imported from. An egg belongs to at most one source file, and a
        // file is imported at most once per source. Deleting a source removes these links only; the eggs are kept.
        Schema::create('ramjet_egg_source_eggs', function (Blueprint $table): void {
            $table->unsignedInteger('egg_id')->primary();
            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('ramjet_egg_sources')->cascadeOnDelete();
            $table->string('path', 500);
            // Blob id reported by the provider when the file was last imported (GitHub sha, GitLab blob id).
            // Null for URL sources, which have no listing. A file whose revision is unchanged is not downloaded.
            $table->string('revision', 191)->nullable();
            $table->unique(['source_id', 'path'], 'ramjet_egg_source_eggs_source_path_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ramjet_egg_source_eggs');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ramjet_egg_registries', function (Blueprint $table): void {
            $table->unsignedInteger('egg_id')->primary();
            $table->foreignId('registry_id')
                ->nullable()
                ->constrained('ramjet_docker_registries')
                ->nullOnDelete();

            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ramjet_egg_registries');
    }
};

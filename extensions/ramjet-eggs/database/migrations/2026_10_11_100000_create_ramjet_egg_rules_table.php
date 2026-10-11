<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Hidden file patterns per egg. The protected patterns stay in core eggs.file_denylist. */
    public function up(): void
    {
        Schema::create('ramjet_egg_rules', function (Blueprint $table): void {
            $table->unsignedInteger('egg_id')->primary();
            $table->json('hidden');

            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ramjet_egg_rules');
    }
};

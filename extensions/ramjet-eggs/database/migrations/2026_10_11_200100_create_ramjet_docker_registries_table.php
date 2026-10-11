<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ramjet_docker_registries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('registry');
            $table->string('username');
            // Encrypted by the model's "encrypted" cast, so it must hold the ciphertext.
            $table->text('password');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ramjet_docker_registries');
    }
};

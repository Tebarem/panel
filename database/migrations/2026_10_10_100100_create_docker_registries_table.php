<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateDockerRegistriesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('docker_registries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('registry');
            $table->string('username');
            // Encrypted using the "encrypted" model cast, so this must be large enough for the ciphertext.
            $table->text('password');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('docker_registries');
    }
}

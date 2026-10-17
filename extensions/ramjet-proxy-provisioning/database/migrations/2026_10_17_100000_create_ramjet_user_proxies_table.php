<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per customer: the user id is the primary key, so a second row for the same user cannot exist
        // even if two requests slip past the application-level check. Deleting the server (or the user) removes
        // the row, which frees the customer to create a new proxy.
        Schema::create('ramjet_user_proxies', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->unsignedInteger('server_id')->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ramjet_user_proxies');
    }
};

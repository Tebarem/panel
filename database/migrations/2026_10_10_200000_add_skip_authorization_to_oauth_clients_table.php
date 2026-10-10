<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddSkipAuthorizationToOauthClientsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * The oauth_clients table itself is created by Laravel Passport's own migrations.
     */
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->boolean('skip_authorization')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn('skip_authorization');
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Reading services live at the site root, so the sync token cannot travel in the URL.
            // The device's own Authorization header is pinned on first use and required after
            // that, which is stronger than trusting an unguessable book id alone.
            $table->text('reading_services_auth')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('reading_services_auth');
        });
    }
};

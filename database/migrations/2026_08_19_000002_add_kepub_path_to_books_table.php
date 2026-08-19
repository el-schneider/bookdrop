<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // Path to the converted Kobo EPUB, when one exists. Deliberately a separate column
            // rather than a new value in the `format` enum: on SQLite that enum is a CHECK
            // constraint, and altering it forces a table rebuild during an unattended boot.
            $table->string('kepub_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('kepub_path');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotations', function (Blueprint $table) {
            // The device assigns annotation ids; they are the identity across syncs.
            $table->string('id')->primary();
            $table->foreignUuid('book_id')->constrained()->cascadeOnDelete();

            // highlight | note | dogear, as reported by the device.
            $table->string('type')->index();

            // Stored verbatim so nothing the device sends is lost to a schema we guessed at.
            $table->json('payload');

            // Extracted for display and ordering only; payload remains authoritative.
            $table->text('highlighted_text')->nullable();
            $table->text('note_text')->nullable();
            $table->string('chapter_filename')->nullable();
            $table->float('chapter_progress')->nullable();
            $table->timestamp('client_last_modified')->nullable();

            $table->timestamps();

            $table->index(['book_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};

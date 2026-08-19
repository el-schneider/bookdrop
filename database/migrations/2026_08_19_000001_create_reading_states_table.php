<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('book_id')->unique()->constrained()->cascadeOnDelete();

            // ReadyToRead | Reading | Finished, as sent by the device.
            $table->string('status')->default('ReadyToRead');
            $table->unsignedInteger('times_started_reading')->default(0);
            $table->timestamp('last_time_started_reading')->nullable();

            // The device sends both percentages independently; 0 is a meaningful value, so these
            // stay nullable to distinguish "at the start" from "never reported".
            $table->float('progress_percent')->nullable();
            $table->float('content_source_progress_percent')->nullable();

            // Location is what actually restores the reading position; the percentage alone
            // cannot. Type is KoboSpan for Kobo's own addressing.
            $table->string('location_source')->nullable();
            $table->string('location_type')->nullable();
            $table->string('location_value')->nullable();

            $table->unsignedInteger('spent_reading_minutes')->nullable();
            $table->unsignedInteger('remaining_time_minutes')->nullable();

            // Own monotonic counter, mirroring books.revision, so a progress update can be paged
            // to the device without resending the book's metadata.
            $table->unsignedBigInteger('revision')->nullable();

            $table->timestamp('last_modified')->nullable();
            $table->timestamp('priority_timestamp')->nullable();
            $table->timestamps();

            $table->unique('revision', 'reading_states_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_states');
    }
};

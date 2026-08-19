<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // Kobo sync needs to tell a new book from a changed one, which uploaded_at alone
            // cannot express.
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // Monotonic change counter. Sync pages over this instead of a timestamp: second-
            // resolution timestamps cannot distinguish a change made in the same second as the
            // sync that delivered it, which would drop that change permanently.
            $table->unsignedBigInteger('revision')->nullable();

            $table->string('series')->nullable();
            $table->string('series_index')->nullable();
            $table->text('description')->nullable();
            $table->string('language')->nullable();
            $table->string('publisher')->nullable();
            $table->timestamp('published_at')->nullable();
        });

        // Existing rows predate these columns. A null LastModified in an entitlement confuses the
        // device, so seed both from the only timestamp that existed. Deleted rows carry their
        // deletion time so the removal can still propagate.
        DB::table('books')->update([
            'created_at' => DB::raw('uploaded_at'),
            'updated_at' => DB::raw('COALESCE(deleted_at, uploaded_at)'),
        ]);

        // Seed revisions in the order the books were last touched, so an existing device resumes
        // in a sensible order. Done in PHP rather than a window function to stay portable.
        $revision = 0;
        $existing = DB::table('books')->orderBy('updated_at')->orderBy('id')->pluck('id');

        foreach ($existing as $id) {
            DB::table('books')->where('id', $id)->update(['revision' => ++$revision]);
        }

        Schema::table('books', function (Blueprint $table) {
            $table->unique('revision', 'books_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropUnique('books_revision_unique');
            $table->dropColumn([
                'created_at',
                'updated_at',
                'revision',
                'series',
                'series_index',
                'description',
                'language',
                'publisher',
                'published_at',
            ]);
        });
    }
};

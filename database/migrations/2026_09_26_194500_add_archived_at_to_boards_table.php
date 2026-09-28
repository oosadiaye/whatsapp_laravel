<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board archiving: an archived_at that is not a delete.
 *
 * Boards already soft-delete, and deleting one cascades a soft delete to every
 * card on it — so the rows survive, but nothing in the UI can bring them back.
 * `withTrashed()` is a developer's escape hatch, not a recovery plan, and "the
 * board with 400 cards is gone" is not a state an app should be able to reach
 * in one click.
 *
 * A timestamp rather than a boolean so the archive answer "when did this stop
 * being live work?" without a second table, and so an expiry policy is a query
 * rather than a schema change.
 *
 * Deliberately NOT `deleted_at`: archiving keeps the board, its cards, its
 * history and its slug, and the row stays out of the ordinary listings through
 * a plain `whereNull`. Nothing is hidden from a query that asks for it, so a
 * report run over an archived board still works and a restore is total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('description');
        });

        // The index serves the "where is it not archived, newest first" query
        // that every board listing runs. Partial would be ideal and is not
        // portable, so this is the ordinary index and the query is written to
        // use it.
        Schema::table('boards', function (Blueprint $table): void {
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};

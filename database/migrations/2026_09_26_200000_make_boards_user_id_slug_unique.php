<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dedupe before constraining (mirrors the sibling position-unique migration).
        // BoardController notes validation can race on concurrent requests, so two
        // boards may already share (user_id, slug); without this the unique() below
        // aborts the deploy with MySQL error 1062 and DDL isn't transactional.
        // NULL user_id (a board whose creator was deleted) is excluded: NULLs are
        // distinct in a unique index and so never collide.
        $dupes = DB::table('boards')
            ->select('user_id', 'slug')
            ->whereNotNull('user_id')
            ->groupBy('user_id', 'slug')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $dupe) {
            $ids = DB::table('boards')
                ->where('user_id', $dupe->user_id)
                ->where('slug', $dupe->slug)
                ->orderBy('id')
                ->pluck('id');

            // Keep the earliest board's slug; uniquify the rest so the index applies.
            foreach ($ids->slice(1) as $id) {
                DB::table('boards')->where('id', $id)->update(['slug' => $dupe->slug.'-dup-'.$id]);
            }
        }

        // Add the unique index FIRST, then drop the old plain one. On MySQL the
        // user_id foreign key is backed by the leftmost prefix of the plain
        // (user_id, slug) index, so dropping that index before a replacement exists
        // fails with error 1553 ("needed in a foreign key constraint"). Creating the
        // unique (also user_id-leftmost) first gives the FK a backing index, so the
        // plain one then becomes droppable. Separate statements guarantee the order;
        // SQLite has no such FK-index rule, so this ordering is harmless there.
        Schema::table('boards', function (Blueprint $table) {
            $table->unique(['user_id', 'slug']);
        });

        Schema::table('boards', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'slug']);
            $table->index(['user_id', 'slug']);
        });
    }
};

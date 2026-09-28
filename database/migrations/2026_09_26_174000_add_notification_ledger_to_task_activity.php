<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correlation key for notification idempotency.
     *
     * Two parts, and the second is the reason this exists:
     *
     * 1. `task_activity.event_key` - a UUID minted once, when the event is
     *    constructed. Both the recorder (which writes the log row) and the
     *    notification listeners (which run later, on the queue) need to agree on
     *    *which row* belongs to *this* event. The obvious alternatives do not
     *    work: mutating the event from one listener and reading it in another
     *    depends on listener registration order, and "the most recent matching
     *    row" is racy the moment two people touch one card at once. A value
     *    carried on the event from birth is the only correlation that is exact.
     *
     *    Nullable so rows written outside an event (console commands, tests) do
     *    not have to invent one. MySQL and SQLite both allow many NULLs under a
     *    unique index.
     *
     * 2. `task_activity_notifications` - a per-recipient ledger. It has to be
     *    per recipient, not a single `notified_at` on the activity row: one
     *    activity fans out to N people, so one timestamp cannot represent N
     *    sends. The single timestamp idea would mark the row sent when the first
     *    recipient went out and silently starve the rest.
     */
    public function up(): void
    {
        Schema::table('task_activity', function (Blueprint $table) {
            $table->uuid('event_key')->nullable()->unique()->after('type');
        });

        Schema::create('task_activity_notifications', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('activity_id');
            $table->unsignedBigInteger('user_id');

            // NOT NULL, and deliberately so. The invariant this table documents
            // is "a row means this person has been handed this notification", and
            // a nullable timestamp would license a third state - claimed but not
            // yet sent - which is exactly the state the write-ordering decision
            // in TaskNotificationLedger refuses to create. Making the column
            // refuse it too means the schema and the comment cannot drift apart.
            $table->timestamp('sent_at');

            // No updated_at, for the same reason task_activity has none: nothing
            // in the app updates a ledger row, and an always-null column that
            // implies an update path is worse than no column.
            $table->timestamp('created_at')->nullable();

            // This index *is* the idempotency guarantee. Without it two
            // deliveries are merely unlikely; with it a second attempt collides
            // instead of duplicating.
            $table->unique(['activity_id', 'user_id'], 'task_activity_notifications_once');

            // Lets a "have I already been mailed about this?" check be
            // answerable per user, which is the other direction anyone will ask
            // for (e.g. an unsubscribe audit).
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activity_notifications');

        Schema::table('task_activity', function (Blueprint $table) {
            $table->dropUnique(['event_key']);
            $table->dropColumn('event_key');
        });
    }
};

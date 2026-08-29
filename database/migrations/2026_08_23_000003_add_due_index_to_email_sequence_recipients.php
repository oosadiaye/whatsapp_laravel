<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ProcessEmailSequences (email-sequences:process, scheduled everyMinute) selects
 * due recipients per active sequence filtering on
 * (email_sequence_id, status, next_send_at). Only the FK index on
 * email_sequence_id exists, so within a sequence the status/next_send_at filter
 * scans every enrolled row every minute just to surface the ≤50 due ones. This
 * composite covers the whole predicate.
 */
return new class extends Migration
{
    // MySQL caps identifier names at 64 chars; the auto-generated name
    // (email_sequence_recipients_email_sequence_id_status_next_send_at_index, 69)
    // overflows it, so name the index explicitly. SQLite has no such cap, which is
    // why the default name passed the test DB but failed on prod MySQL.
    private const INDEX = 'esr_seq_status_send_idx';

    public function up(): void
    {
        Schema::table('email_sequence_recipients', function (Blueprint $table) {
            $table->index(['email_sequence_id', 'status', 'next_send_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('email_sequence_recipients', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};

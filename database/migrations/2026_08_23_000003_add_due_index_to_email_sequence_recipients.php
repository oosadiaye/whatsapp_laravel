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
    public function up(): void
    {
        Schema::table('email_sequence_recipients', function (Blueprint $table) {
            $table->index(['email_sequence_id', 'status', 'next_send_at']);
        });
    }

    public function down(): void
    {
        Schema::table('email_sequence_recipients', function (Blueprint $table) {
            $table->dropIndex(['email_sequence_id', 'status', 'next_send_at']);
        });
    }
};

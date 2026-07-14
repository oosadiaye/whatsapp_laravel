<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give contacts an email address so the same audience powers both WhatsApp and
 * email campaigns. Phone becomes nullable so an email-only prospect can exist as
 * a contact (the WhatsApp flows already guard on a present phone).
 *
 * The existing unique(user_id, phone) still holds (multiple NULL phones are
 * allowed); we add an index on email for recipient lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('phone');
            $table->string('phone', 20)->nullable()->change();
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex(['email']);
            $table->dropColumn('email');
            // Note: phone is left nullable on rollback — reverting to NOT NULL
            // could fail if email-only rows exist. Backfill before tightening.
        });
    }
};

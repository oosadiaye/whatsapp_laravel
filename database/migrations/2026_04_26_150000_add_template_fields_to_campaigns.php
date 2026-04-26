<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allows a campaign to send via a Meta-approved template instead of freeform
 * text. When `message_template_id` is set AND the campaign's instance uses the
 * Cloud API driver, the send job will dispatch via /messages with type=template.
 *
 * Otherwise the existing `message` column continues to be used (legacy/Evolution
 * path or local-only templates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('message_template_id')
                ->nullable()
                ->after('message')
                ->constrained('message_templates')
                ->nullOnDelete();

            // Template language code at the time of send. Falls back to the
            // template's own `language` value but is overridable per-campaign.
            $table->string('template_language', 16)->nullable()->after('message_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('message_template_id');
            $table->dropColumn('template_language');
        });
    }
};

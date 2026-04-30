<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds header_media_url to campaigns so Meta-approved templates with
 * IMAGE / VIDEO / DOCUMENT headers can be filled at send time.
 *
 * Why this column exists:
 *   Meta requires templates with media headers to receive the actual media
 *   URL in every send request. Without it, Meta returns error 132012
 *   ("Parameter format does not match format in the created template").
 *
 *   The header IS approved at template-creation time, but the URL provided
 *   then is just an example. Each send must supply a fresh URL (or media
 *   handle uploaded via /{phone_number_id}/media). We chose URL because
 *   it's the simplest path — user pastes any publicly-reachable HTTPS URL
 *   when creating the campaign.
 *
 *   Nullable because most templates have TEXT headers (or no header at all)
 *   and don't need this field. Validation in StoreCampaignRequest enforces
 *   it conditionally based on the selected template's header format.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('header_media_url', 2048)->nullable()->after('template_language');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('header_media_url');
        });
    }
};

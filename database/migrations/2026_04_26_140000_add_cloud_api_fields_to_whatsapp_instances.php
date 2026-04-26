<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds WhatsApp Cloud API credential fields to whatsapp_instances.
 *
 * Each instance now represents one Meta Cloud API phone number with its own
 * access token, WABA ID, phone-number ID, and webhook verify token. The legacy
 * Evolution API fields (api_token, instance_name) are kept so the old code path
 * keeps working until Phase 2 swaps it out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            // 'cloud' = Meta Cloud API (graph.facebook.com); 'evolution' = legacy Baileys path.
            $table->string('driver', 32)->default('evolution')->after('user_id');

            // Meta WhatsApp Business Account ID — required to manage templates.
            $table->string('waba_id')->nullable()->after('driver');

            // Meta Phone Number ID — required to send messages from this number.
            $table->string('phone_number_id')->nullable()->after('waba_id');

            // Long-lived system-user access token. Encrypted via $casts in the model.
            $table->text('access_token')->nullable()->after('phone_number_id');

            // App secret used to verify the X-Hub-Signature-256 header on incoming webhooks.
            $table->text('app_secret')->nullable()->after('access_token');

            // Random token chosen by us; Meta echoes it back during webhook verification.
            $table->string('webhook_verify_token')->nullable()->after('app_secret');

            // The actual phone number string (E.164 format) — purely for display.
            $table->string('business_phone_number')->nullable()->after('webhook_verify_token');

            // Cloud API quality rating returned by GET /{phone_number_id} (GREEN/YELLOW/RED).
            $table->string('quality_rating', 16)->nullable()->after('business_phone_number');

            // Cloud API messaging tier (TIER_50/TIER_250/TIER_1K/TIER_10K/TIER_100K/TIER_UNLIMITED).
            $table->string('messaging_limit_tier', 32)->nullable()->after('quality_rating');

            $table->index('driver');
            $table->unique('phone_number_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            $table->dropUnique(['phone_number_id']);
            $table->dropIndex(['driver']);
            $table->dropColumn([
                'driver',
                'waba_id',
                'phone_number_id',
                'access_token',
                'app_secret',
                'webhook_verify_token',
                'business_phone_number',
                'quality_rating',
                'messaging_limit_tier',
            ]);
        });
    }
};

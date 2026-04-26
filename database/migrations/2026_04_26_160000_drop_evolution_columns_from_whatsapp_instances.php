<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops Evolution/Baileys-specific columns from whatsapp_instances.
 *
 * After committing to direct Meta Cloud API only, these columns no longer
 * carry meaning:
 *
 *   - driver              — only one value possible now
 *   - api_token           — Evolution-specific bearer for its own /instance API
 *
 * `instance_name` stays — repurposed as the user-visible internal handle
 * (still shown on the create form and dashboard list). It just no longer
 * doubles as the Evolution provider's lookup key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            // SQLite (used in tests) can't drop multiple columns in one statement
            // when an index references them; drop the index first.
            $table->dropIndex(['driver']);
        });

        Schema::table('whatsapp_instances', function (Blueprint $table) {
            $table->dropColumn(['driver', 'api_token']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            $table->string('driver', 32)->default('cloud')->after('user_id');
            $table->text('api_token')->nullable()->after('access_token');
            $table->index('driver');
        });
    }
};

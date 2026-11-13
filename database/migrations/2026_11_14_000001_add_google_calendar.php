<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('google_email', 190);
            // Encrypted at rest (the model's `encrypted` cast). Never serialised, never logged.
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('scopes', 500)->nullable();
            $table->string('status', 16)->default('active'); // active | needs_reauth
            $table->dateTime('last_synced_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->unsignedInteger('last_import_count')->default(0);
            $table->timestamps();
        });

        Schema::table('meetings', function (Blueprint $table): void {
            // Which outside calendar this meeting lives in, and its event id there.
            $table->string('external_provider', 12)->nullable()->after('sequence');
            $table->string('external_id', 255)->nullable()->after('external_provider');
            $table->string('external_url', 500)->nullable()->after('external_id');
            $table->string('external_organizer', 190)->nullable()->after('external_url');
            // True when the meeting was brought in from the outside calendar (read-only here), false when it was
            // created in BlastIQ and pushed out.
            $table->boolean('imported')->default(false)->after('external_organizer');

            $table->unique(['external_provider', 'external_id', 'organizer_id'], 'meetings_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropUnique('meetings_external_unique');
            $table->dropColumn(['external_provider', 'external_id', 'external_url', 'external_organizer', 'imported']);
        });
        Schema::dropIfExists('google_connections');
    }
};

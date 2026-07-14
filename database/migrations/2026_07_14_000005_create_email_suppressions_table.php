<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global email suppression list — the do-not-email set. An address lands here
 * on unsubscribe, hard bounce, or spam complaint, and the send pipeline skips
 * any recipient whose (lowercased) email is present. Single-tenant: unique on
 * the email alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('reason')->default('unsubscribe'); // unsubscribe|bounce|complaint|manual
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};

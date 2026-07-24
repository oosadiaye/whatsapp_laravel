<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-authored email templates — reusable HTML bodies the team BUILDS itself
 * (distinct from Meta-synced WhatsApp message_templates, which are read-only).
 * Single-tenant: shared across everyone with email permission; user_id only
 * records who created it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('subject')->nullable();
            $table->longText('body_html');
            $table->softDeletes();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};

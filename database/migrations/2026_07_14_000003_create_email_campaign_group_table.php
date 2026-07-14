<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which contact groups an email campaign targets. Reuses the same ContactGroup
 * audience as WhatsApp campaigns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_group', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('contact_groups')->cascadeOnDelete();
            $table->unique(['email_campaign_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_group');
    }
};

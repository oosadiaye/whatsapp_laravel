<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One conversation = one (contact, instance) pair within a user's account.
 *
 * Grouping rule: per-contact + per-instance (decision D from the planning).
 * If you have multiple WhatsApp numbers and one customer messages two of
 * them, you get two threads — natural for separate sales/support lines.
 *
 * `assigned_to_user_id` is set when a manager assigns the chat to a specific
 * agent (Phase 14). Null = unassigned, visible in the "all unassigned" pool.
 *
 * `last_message_at` denormalized so the inbox list can ORDER BY it without
 * joining message tables on every page load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('whatsapp_instance_id')->constrained('whatsapp_instances')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();

            $table->unique(['contact_id', 'whatsapp_instance_id'], 'conversations_contact_instance_unique');
            $table->index(['user_id', 'last_message_at']);
            $table->index('assigned_to_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};

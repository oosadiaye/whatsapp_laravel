<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual messages within a conversation — both inbound (from contact)
 * and outbound (replies sent by staff).
 *
 * Why a separate table from message_logs:
 * - message_logs is campaign-scoped (FK is required) and tracks delivery
 *   analytics. Conversations are NOT tied to a specific campaign — most
 *   inbound replies aren't responses to any campaign at all.
 * - Different read/write patterns: message_logs is bulk-written by the send
 *   job and bulk-read for analytics; conversation_messages is single-row
 *   write per webhook event and indexed-read for chat thread display.
 *
 * `direction='outbound'` rows have `sent_by_user_id` set to the staff member
 * who composed the reply, used in audit ("who sent that?") and the chat UI
 * avatar display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);

            // Meta's wamid — unique across all your messages with Meta.
            // Inbound messages always have one; outbound only after Meta accepts.
            $table->string('whatsapp_message_id')->nullable()->unique();

            // text / image / video / audio / document / sticker / location / contacts / interactive / template / unknown
            $table->string('type', 24);

            // For text → the text. For media → the caption (may be empty).
            $table->text('body')->nullable();

            // Local storage path for downloaded inbound media.
            // Outbound media is referenced by URL in the send call, not stored locally.
            $table->string('media_path')->nullable();
            $table->string('media_mime')->nullable();
            $table->unsignedInteger('media_size_bytes')->nullable();

            // Outbound only — who composed the reply.
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // For outbound messages, mirrors the message_log flow (sent / delivered / read / failed).
            $table->string('status', 16)->nullable();

            // Provider-reported timestamp (preferred over our created_at for inbound ordering).
            $table->timestamp('received_at')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['direction', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};

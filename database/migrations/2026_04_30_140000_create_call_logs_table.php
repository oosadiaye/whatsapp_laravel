<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * call_logs — one row per call event series, updated as Meta's webhook
 * delivers state changes (connect → accept → disconnect).
 *
 * Always linked to a Conversation (NOT NULL FK by design — see spec Q4).
 * Uses meta_call_id for idempotency: webhook retries find the same row
 * and update it instead of creating duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();
            $table->foreignId('contact_id')
                ->constrained('contacts')
                ->cascadeOnDelete();
            $table->foreignId('whatsapp_instance_id')
                ->constrained('whatsapp_instances')
                ->cascadeOnDelete();

            $table->enum('direction', ['inbound', 'outbound']);

            // Meta's call ID. Unique. Nullable for outbound calls between
            // API request and Meta's response coming back.
            $table->string('meta_call_id')->nullable()->unique();

            $table->enum('status', [
                'initiated',  // outbound: API fired, awaiting Meta confirmation
                'ringing',    // ring received on customer side
                'connected',  // accepted by either party
                'ended',      // normal hang-up after connection
                'missed',     // ringing timeout, no answer
                'declined',   // explicit reject
                'failed',     // API/network error before ringing
            ])->default('initiated');

            $table->string('from_phone', 20);
            $table->string('to_phone', 20);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->text('failure_reason')->nullable();

            // Outbound only: who clicked the call button. NULL for inbound.
            $table->foreignId('placed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Append-only event log: every webhook payload Meta sent for this
            // call, in chronological order. Used for debugging the timeline view.
            $table->json('raw_event_log')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['whatsapp_instance_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};

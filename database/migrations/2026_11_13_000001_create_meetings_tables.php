<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table): void {
            $table->id();
            // Null once the organiser's account is deleted: the meeting (and the invitations people
            // already hold) stay valid, so deleting a person never silently cancels other people's plans.
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('video_url', 500)->nullable();
            // Stored in UTC; entered and shown in app.business_timezone.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 12)->default('scheduled');
            // Stable calendar identity: the same UID on every update/cancel lets calendar apps replace the event.
            $table->string('uid', 64)->unique();
            $table->unsignedInteger('sequence')->default(0);
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('reminder_24h_sent_at')->nullable();
            $table->dateTime('reminder_10m_sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        Schema::create('meeting_attendees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 190);
            $table->string('name', 120)->nullable();
            $table->string('response', 10)->default('pending');
            // The recipient's private answer link. Random, unique, never shown to anyone else.
            $table->string('response_token', 40)->unique();
            $table->dateTime('responded_at')->nullable();
            $table->dateTime('invited_at')->nullable();
            $table->string('invite_error', 300)->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendees');
        Schema::dropIfExists('meetings');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_profile_id')->nullable()->constrained('lead_profiles')->nullOnDelete();
            $table->string('source', 32);
            $table->string('source_id', 128);
            // Null until a platform reveals it; MySQL and SQLite both allow many NULLs in a unique index.
            $table->string('email')->nullable()->unique();
            $table->string('email_status', 32)->default('unrevealed');
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('company')->nullable();
            $table->string('domain')->nullable();
            $table->string('country')->nullable();
            $table->string('phone')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->json('raw')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('tier', 16)->nullable();
            $table->text('qualification_reason')->nullable();
            $table->string('status', 24)->default('new');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_id']);
            $table->index(['lead_profile_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};

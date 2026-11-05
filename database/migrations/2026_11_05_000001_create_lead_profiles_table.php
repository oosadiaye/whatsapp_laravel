<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->text('brief');
            $table->json('hard_filters')->nullable();
            $table->json('sources');
            $table->json('lead_query')->nullable();
            $table->unsignedInteger('daily_pull_cap')->default(50);
            // Per source: ['apollo' => ['next_page' => 1, 'exhausted' => false]]
            $table->json('pull_state')->nullable();
            $table->timestamp('last_pulled_at')->nullable();
            $table->text('last_pull_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_profiles');
    }
};

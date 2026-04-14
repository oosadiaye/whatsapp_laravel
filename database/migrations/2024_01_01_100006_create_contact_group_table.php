<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_group', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('contact_groups')->cascadeOnDelete();

            $table->primary(['contact_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_group');
    }
};

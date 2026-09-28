<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            // Creator audit metadata only (see boards migration). Null the creator
            // on user deletion rather than hard-deleting the card off a shared board.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('todo');
            $table->foreignId('board_id')->nullable()->constrained('boards')->onDelete('cascade');
            $table->integer('position')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['board_id', 'position']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};

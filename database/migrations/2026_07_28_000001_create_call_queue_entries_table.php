<?php

declare(strict_types=1);

use App\Models\CallQueueEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->index();
            $table->string('caller_number')->nullable();
            $table->string('queue_name')->default('support');
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('status', 20)->default(CallQueueEntry::STATUS_WAITING);
            $table->timestamp('entered_at')->useCurrent();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_queue_entries');
    }
};

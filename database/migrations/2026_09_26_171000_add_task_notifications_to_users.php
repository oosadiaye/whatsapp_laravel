<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 'transitions' is the default: column moves notify, but comments
            // and assignment changes do not. That keeps today's behaviour for
            // existing users while making the value explicit and editable.
            $table->string('task_notifications')
                ->default('transitions')
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('task_notifications');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table): void {
            $table->text('signature')->nullable()->after('display_name');
            // On by default: a signature that is set is used without the person having to opt in again.
            $table->boolean('signature_auto')->default(true)->after('signature');
        });
    }

    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table): void {
            $table->dropColumn(['signature', 'signature_auto']);
        });
    }
};

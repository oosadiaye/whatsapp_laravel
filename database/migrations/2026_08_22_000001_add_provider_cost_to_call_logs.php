<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist Africa's Talking's actual billed cost (when the Completed callback
 * carries amount/currency) alongside the flat estimate. The estimate remains
 * the working figure; the real figures are kept for reconciliation/accuracy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->decimal('provider_amount', 12, 4)->nullable()->after('cost_estimate_kobo');
            $table->string('provider_currency', 8)->nullable()->after('provider_amount');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn(['provider_amount', 'provider_currency']);
        });
    }
};

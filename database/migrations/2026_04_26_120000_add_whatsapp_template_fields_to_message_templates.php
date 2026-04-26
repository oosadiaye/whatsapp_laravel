<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->foreignId('whatsapp_instance_id')
                ->nullable()
                ->after('user_id')
                ->constrained('whatsapp_instances')
                ->nullOnDelete();

            $table->string('whatsapp_template_id')->nullable()->after('name');
            $table->string('language', 16)->default('en_US')->after('whatsapp_template_id');
            $table->string('status')->default('LOCAL')->after('language');
            $table->json('components')->nullable()->after('content');
            $table->timestamp('synced_at')->nullable()->after('components');

            $table->unique(['whatsapp_instance_id', 'whatsapp_template_id', 'language'], 'mt_instance_tpl_lang_unique');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropUnique('mt_instance_tpl_lang_unique');
            $table->dropConstrainedForeignId('whatsapp_instance_id');
            $table->dropColumn([
                'whatsapp_template_id',
                'language',
                'status',
                'components',
                'synced_at',
            ]);
        });
    }
};

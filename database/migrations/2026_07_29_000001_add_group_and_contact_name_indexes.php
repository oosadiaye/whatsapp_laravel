<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // group_id is only the SECOND column of contact_group's composite PK
        // (contact_id, group_id), so `WHERE group_id IN (...)` — the campaign
        // audience subquery in CampaignBatchDispatch / EmailCampaignService — and
        // the new sequence-enrolment `whereHas('groups')` can't use it. Add a
        // standalone index (H8).
        Schema::table('contact_group', function (Blueprint $table) {
            $table->index('group_id', 'contact_group_group_id_index');
        });

        // The Call Workspace dial picker and the contacts list both ORDER BY
        // name; without an index that is a filesort on every load (H8).
        Schema::table('contacts', function (Blueprint $table) {
            $table->index('name', 'contacts_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('contact_group', function (Blueprint $table) {
            $table->dropIndex('contact_group_group_id_index');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_name_index');
        });
    }
};

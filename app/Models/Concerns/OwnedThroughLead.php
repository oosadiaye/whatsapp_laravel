<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\OwnRecordsScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lead CRM rows are owned through the lead profile that pulled them (created_by) or the lead's
 * assigned owner. Lead, LeadProfile and their messages/replies/events all narrow to the same set.
 */
trait OwnedThroughLead
{
    public static function bootOwnedThroughLead(): void
    {
        static::addGlobalScope(new OwnRecordsScope);
    }

    /** @param  Builder<static>  $builder */
    public function restrictQueryToOwner(Builder $builder, User $user): void
    {
        $builder->whereIn($this->qualifyColumn($this->leadKey()), self::ownedLeadIds($user));
    }

    /** Column holding the lead's id on this model (`id` on Lead itself). */
    public function leadKey(): string
    {
        return 'lead_id';
    }

    /** @return \Illuminate\Database\Query\Builder */
    public static function ownedLeadIds(User $user)
    {
        return DB::table('leads')->select('id')->where(function ($q) use ($user): void {
            $q->where('owner_user_id', $user->id)
                ->orWhereIn('lead_profile_id', DB::table('lead_profiles')->select('id')->where('created_by', $user->id));
        });
    }
}

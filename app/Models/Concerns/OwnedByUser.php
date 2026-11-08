<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\OwnRecordsScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Marks a model whose rows belong to the user who created them. Models keyed by a column other
 * than `user_id` set {@see self::ownerColumn()}; models owned through a parent override
 * {@see self::restrictQueryToOwner()}.
 */
trait OwnedByUser
{
    public static function bootOwnedByUser(): void
    {
        static::addGlobalScope(new OwnRecordsScope);
    }

    public function ownerColumn(): string
    {
        return 'user_id';
    }

    /** @param  Builder<static>  $builder */
    public function restrictQueryToOwner(Builder $builder, User $user): void
    {
        $builder->where($this->qualifyColumn($this->ownerColumn()), $user->id);
    }
}

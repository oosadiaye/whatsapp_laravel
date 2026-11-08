<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps a restricted user inside their own workspace: while the signed-in user is flagged
 * "restrict to own records", every query on a model using {@see \App\Models\Concerns\OwnedByUser}
 * only returns rows they created. Queue workers, webhooks and the scheduler have no signed-in
 * user, so they are never narrowed.
 */
final class OwnRecordsScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::hasUser() ? Auth::user() : null;
        if (! $user instanceof User || ! $user->restrictsToOwnRecords()) {
            return;
        }

        /** @var \App\Models\Concerns\OwnedByUser $model */
        $model->restrictQueryToOwner($builder, $user);
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $user->id === $campaign->user_id || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $user->id === $campaign->user_id || $user->isAdmin();
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->id === $campaign->user_id || $user->isAdmin();
    }
}

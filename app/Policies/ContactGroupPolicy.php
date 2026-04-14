<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ContactGroup;
use App\Models\User;

class ContactGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ContactGroup $contactGroup): bool
    {
        return $user->id === $contactGroup->user_id || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ContactGroup $contactGroup): bool
    {
        return $user->id === $contactGroup->user_id || $user->isAdmin();
    }

    public function delete(User $user, ContactGroup $contactGroup): bool
    {
        return $user->id === $contactGroup->user_id || $user->isAdmin();
    }
}

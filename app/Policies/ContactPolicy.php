<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->id === $contact->user_id || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->id === $contact->user_id || $user->isAdmin();
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->id === $contact->user_id || $user->isAdmin();
    }
}

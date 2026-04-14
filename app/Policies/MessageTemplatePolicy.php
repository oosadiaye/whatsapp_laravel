<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MessageTemplate;
use App\Models\User;

class MessageTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MessageTemplate $messageTemplate): bool
    {
        return $user->id === $messageTemplate->user_id || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MessageTemplate $messageTemplate): bool
    {
        return $user->id === $messageTemplate->user_id || $user->isAdmin();
    }

    public function delete(User $user, MessageTemplate $messageTemplate): bool
    {
        return $user->id === $messageTemplate->user_id || $user->isAdmin();
    }
}

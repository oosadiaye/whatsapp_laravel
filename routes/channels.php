<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('user.{id}', function ($user, $id) {
    // Each user authorizes ONLY for their own user-scoped channel.
    // Echo passes the authenticated session cookie; this closure runs
    // inside the standard Laravel session guard.
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| Task board channel
|--------------------------------------------------------------------------
|
| Every board is company-wide (single tenant), so viewing a board's live
| stream is the same test as viewing the board itself: tasks.view. Mirrors the
| board's route middleware so a private channel can never widen access.
|
| Note this does NOT grant mutation rights — moving a card is still gated by
| tasks.edit inside the Livewire component, which channel auth cannot bypass.
|
*/
Broadcast::channel('boards.{boardId}', function ($user, int $boardId): bool {
    // Explicit null check: a guest reaching /broadcasting/auth must be denied
    // rather than relying on a property call on null.
    return $user !== null && $user->can('tasks.view');
});

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

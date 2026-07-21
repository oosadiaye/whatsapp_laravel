<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * The per-employee mailbox inbox page (plan B4). Hosts the Livewire
 * {@see \App\Livewire\Mailbox\Inbox} component; account connection lives in
 * {@see EmailAccountController}. Whole feature gated by the mailbox.enabled route
 * middleware + mailbox.view permission.
 */
class MailboxController extends Controller
{
    public function inbox(): View
    {
        return view('mailbox.inbox');
    }
}

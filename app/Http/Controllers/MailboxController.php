<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

/**
 * The per-employee mailbox inbox page (plan B4) + attachment downloads (B5b).
 * Hosts the Livewire {@see \App\Livewire\Mailbox\Inbox} component; account
 * connection lives in {@see EmailAccountController}. Whole feature gated by the
 * mailbox.enabled route middleware + mailbox.view permission.
 */
class MailboxController extends Controller
{
    public function inbox(): View
    {
        return view('mailbox.inbox');
    }

    /**
     * Stream an inbound attachment off the private disk. The binary is never
     * web-served directly (it lives outside the public root), so access is
     * re-authorized here: the requester must be able to see the owning account's
     * mail — their OWN account, or any account when they hold mailbox.view_all
     * (the same visibility the reader enforces).
     */
    public function downloadAttachment(Request $request, EmailAttachment $attachment): StreamedResponse
    {
        abort_unless((bool) config('mail_client.enabled'), 404);

        $user = $request->user();
        abort_unless($user?->can('mailbox.view'), 403);

        $account = $attachment->message?->account;
        abort_if($account === null, 404);

        $owns = $account->user_id === $user->id;
        abort_unless($owns || $user->can('mailbox.view_all'), 403);

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->filename);
    }
}

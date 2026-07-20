<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use App\Services\MailClient\MailAccountProviderFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Per-employee mailbox account connection (plan B2). A user manages THEIR OWN
 * accounts; mailbox.view_all can see the team's, mailbox.admin can disconnect
 * others'. Whole feature is behind the `mailbox.enabled` route middleware.
 */
class EmailAccountController extends Controller
{
    public function index(): View
    {
        $accounts = auth()->user()->can('mailbox.view_all')
            ? EmailAccount::with('user')->orderBy('email')->get()
            : EmailAccount::where('user_id', auth()->id())->orderBy('email')->get();

        return view('mailbox.accounts.index', ['accounts' => $accounts]);
    }

    public function create(): View
    {
        return view('mailbox.accounts.create');
    }

    public function store(Request $request, MailAccountProviderFactory $factory): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'imap_host' => ['required', 'string', 'max:255'],
            'imap_port' => ['required', 'integer', 'between:1,65535'],
            'imap_encryption' => ['required', 'in:ssl,tls,starttls,none'],
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', 'in:ssl,tls,starttls,none'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        // A user only ever connects an account under their own id. IncludingTrashed
        // so reconnecting a previously-disconnected mailbox revives its row.
        $account = EmailAccount::firstOrNewIncludingTrashed([
            'user_id' => auth()->id(),
            'email' => $data['email'],
        ]);

        $account->fill([
            'provider' => EmailAccount::PROVIDER_IMAP,
            'display_name' => $data['display_name'] ?? null,
            'credentials' => [
                'imap_host' => $data['imap_host'],
                'imap_port' => $data['imap_port'],
                'imap_encryption' => $data['imap_encryption'] === 'none' ? '' : $data['imap_encryption'],
                'smtp_host' => $data['smtp_host'],
                'smtp_port' => $data['smtp_port'],
                'smtp_encryption' => $data['smtp_encryption'] === 'none' ? '' : $data['smtp_encryption'],
                'username' => $data['username'],
                'password' => $data['password'],
            ],
        ]);
        $account->save();

        // Only mark the account usable if the credentials actually sign in.
        $result = $factory->for($account)?->connectionTest($account);

        if ($result === null || ! $result->ok) {
            $account->update(['is_active' => false, 'needs_reauth' => true]);

            return redirect()->route('mailbox.accounts.index')->with(
                'error',
                'Saved, but could not sign in: '.($result->error ?? 'unsupported provider').'. Check the settings and reconnect.',
            );
        }

        $account->update(['is_active' => true, 'needs_reauth' => false]);

        return redirect()->route('mailbox.accounts.index')
            ->with('success', "Mailbox {$account->email} connected.");
    }

    public function destroy(EmailAccount $account): RedirectResponse
    {
        // Own account, or any if you can administer mailboxes.
        abort_unless(
            $account->user_id === auth()->id() || auth()->user()->can('mailbox.admin'),
            403,
        );

        $email = $account->email;
        $account->delete(); // soft delete = disconnect (credentials retained, revivable)

        return redirect()->route('mailbox.accounts.index')
            ->with('success', "Mailbox {$email} disconnected.");
    }
}

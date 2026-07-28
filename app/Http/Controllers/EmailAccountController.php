<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use App\Rules\SafeMailHost;
use App\Services\AuditService;
use App\Services\MailClient\MailAccountProviderFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

    public function store(Request $request, MailAccountProviderFactory $factory, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'imap_host' => ['required', 'string', 'max:255', new SafeMailHost],
            'imap_port' => ['required', 'integer', 'between:1,65535'],
            'imap_encryption' => ['required', 'in:ssl,tls,starttls,none'],
            'smtp_host' => ['required', 'string', 'max:255', new SafeMailHost],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', 'in:ssl,tls,starttls,none'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $account = EmailAccount::firstOrNewIncludingTrashed([
            'user_id' => auth()->id(),
            'email' => $data['email'],
        ]);

        // outlook.office365.com is intentionally NOT mapped to PROVIDER_GRAPH:
        // there is no OAuth/Graph flow yet, and Graph accounts route through plain
        // ImapFetcher/SmtpSender anyway — so a "Graph" label implied a capability
        // we don't have and produced opaque sign-in failures. Connect Outlook as
        // IMAP so behaviour matches the label (M365 needs IMAP/SMTP AUTH enabled).
        $providerMap = [
            'imap.gmail.com' => EmailAccount::PROVIDER_GMAIL,
            'imap.mail.yahoo.com' => EmailAccount::PROVIDER_IMAP,
        ];
        $provider = $providerMap[$data['imap_host']] ?? EmailAccount::PROVIDER_IMAP;

        $account->fill([
            'provider' => $provider,
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

            // Log the real reason server-side; show a generic message so the
            // response can't be used as an SSRF/connection oracle (H1).
            Log::warning('Mailbox connect failed', [
                'account_id' => $account->id,
                'user_id' => auth()->id(),
                'reason' => $result->error ?? 'unsupported provider',
            ]);

            return redirect()->route('mailbox.accounts.index')->with(
                'error',
                'Saved, but we could not sign in with those settings. Double-check the host, port, username and password, then reconnect.',
            );
        }

        $account->update(['is_active' => true, 'needs_reauth' => false]);

        $audit->log('mailbox.connected', 'email_account', $account->id, ['email' => $account->email]);

        return redirect()->route('mailbox.accounts.index')
            ->with('success', "Mailbox {$account->email} connected.");
    }

    public function edit(EmailAccount $account): View
    {
        abort_unless($account->user_id === auth()->id(), 403);

        return view('mailbox.accounts.edit', ['account' => $account]);
    }

    public function update(Request $request, EmailAccount $account, MailAccountProviderFactory $factory, AuditService $audit): RedirectResponse
    {
        abort_unless($account->user_id === auth()->id(), 403);

        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:255'],
            'imap_host' => ['required', 'string', 'max:255', new SafeMailHost],
            'imap_port' => ['required', 'integer', 'between:1,65535'],
            'imap_encryption' => ['required', 'in:ssl,tls,starttls,none'],
            'smtp_host' => ['required', 'string', 'max:255', new SafeMailHost],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', 'in:ssl,tls,starttls,none'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
        ]);

        $creds = $account->credentials ?? [];
        $creds = array_merge($creds, [
            'imap_host' => $data['imap_host'],
            'imap_port' => $data['imap_port'],
            'imap_encryption' => $data['imap_encryption'] === 'none' ? '' : $data['imap_encryption'],
            'smtp_host' => $data['smtp_host'],
            'smtp_port' => $data['smtp_port'],
            'smtp_encryption' => $data['smtp_encryption'] === 'none' ? '' : $data['smtp_encryption'],
            'username' => $data['username'],
        ]);

        if (filled($data['password'] ?? null)) {
            $creds['password'] = $data['password'];
        }

        $account->fill([
            'display_name' => $data['display_name'] ?? null,
            'credentials' => $creds,
            'needs_reauth' => false,
        ]);

        $result = $factory->for($account)?->connectionTest($account);

        if ($result === null || ! $result->ok) {
            $account->is_active = false;
            $account->needs_reauth = true;
            $account->save();

            Log::warning('Mailbox update sign-in failed', [
                'account_id' => $account->id,
                'user_id' => auth()->id(),
                'reason' => $result->error ?? 'unsupported provider',
            ]);

            return redirect()->route('mailbox.accounts.index')->with(
                'error',
                'Saved, but we could not sign in with those settings. Double-check the host, port, username and password, then reconnect.',
            );
        }

        $account->is_active = true;
        $account->save();

        $audit->log('mailbox.updated', 'email_account', $account->id, ['email' => $account->email]);

        return redirect()->route('mailbox.accounts.index')
            ->with('success', "Mailbox {$account->email} updated.");
    }

    public function destroy(EmailAccount $account, AuditService $audit): RedirectResponse
    {
        abort_unless(
            $account->user_id === auth()->id() || auth()->user()->can('mailbox.admin'),
            403,
        );

        $email = $account->email;
        $account->delete();

        $audit->log('mailbox.disconnected', 'email_account', $account->id, ['email' => $email]);

        return redirect()->route('mailbox.accounts.index')
            ->with('success', "Mailbox {$email} disconnected.");
    }
}

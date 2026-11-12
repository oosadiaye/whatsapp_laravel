<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncEmailAccount;
use App\Models\EmailAccount;
use App\Rules\SafeMailHost;
use App\Services\AuditService;
use App\Services\MailClient\MailAccountProviderFactory;
use App\Services\MailClient\SmtpSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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

        // Pull the mailbox's existing mail now, not at the next scheduler tick.
        SyncEmailAccount::dispatch($account->id);

        $audit->log('mailbox.connected', 'email_account', $account->id, ['email' => $account->email]);

        $redirect = redirect()->route('mailbox.accounts.index')
            ->with('success', "Mailbox {$account->email} connected.");

        return $this->withSendWarning($redirect, $account);
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

        SyncEmailAccount::dispatch($account->id);

        $audit->log('mailbox.updated', 'email_account', $account->id, ['email' => $account->email]);

        $redirect = redirect()->route('mailbox.accounts.index')
            ->with('success', "Mailbox {$account->email} updated.");

        return $this->withSendWarning($redirect, $account);
    }

    /**
     * Receiving works if we got this far; also try the outgoing server. If that fails the mailbox
     * stays connected, but the person is told right now that sending will not work yet.
     */
    private function withSendWarning(RedirectResponse $redirect, EmailAccount $account): RedirectResponse
    {
        if (! config('mail_client.verify_smtp_on_connect')) {
            return $redirect;
        }

        $result = app(SmtpSender::class)->check($account);

        if ($result->ok) {
            return $redirect;
        }

        Log::warning('Mailbox outgoing check failed', ['account_id' => $account->id, 'reason' => $result->error]);

        return $redirect->with('warning', 'Incoming mail works, but sending will fail until this is fixed. '.$result->error);
    }

    /** The owner's own signature for this mailbox. Plain text; added to new mail when `signature_auto` is on. */
    public function updateSignature(Request $request, EmailAccount $account): RedirectResponse
    {
        abort_unless($account->user_id === auth()->id(), 403);

        $data = $request->validate([
            'signature' => ['nullable', 'string', 'max:1000'],
        ]);

        $signature = trim(str_replace("\r\n", "\n", (string) ($data['signature'] ?? '')));

        $account->update([
            'signature' => $signature === '' ? null : $signature,
            'signature_auto' => $request->boolean('signature_auto'),
        ]);

        return redirect()->route('mailbox.accounts.edit', $account)->with('success', 'Signature saved.');
    }

    /** How often this mailbox is checked for new mail. */
    public function updateSyncInterval(Request $request, EmailAccount $account): RedirectResponse
    {
        abort_unless($account->user_id === auth()->id(), 403);

        $data = $request->validate([
            'sync_interval_minutes' => ['required', 'integer', Rule::in(EmailAccount::SYNC_INTERVALS)],
        ]);

        $account->update(['sync_interval_minutes' => $data['sync_interval_minutes']]);

        return redirect()->route('mailbox.accounts.edit', $account)->with('success', 'Check interval saved.');
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\EmailAccount;
use App\Models\EmailSequence;
use App\Models\EmailSequenceRecipient;
use App\Models\EmailSequenceStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmailSequenceController extends Controller
{
    public function index(): View
    {
        $sequences = EmailSequence::query()
            ->with('steps')
            ->withCount('recipients')
            ->where('user_id', auth()->id())
            ->orderByDesc('id')
            ->get();

        return view('email-sequences.index', ['sequences' => $sequences]);
    }

    public function create(): View
    {
        $accounts = EmailAccount::query()
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->orderBy('email')
            ->get();

        return view('email-sequences.create', ['accounts' => $accounts]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Scope to the caller's OWN accounts — a bare exists: rule would let a
            // user point a sequence at a colleague's mailbox and send through their
            // SMTP identity (H2 cross-account send-as impersonation).
            'email_account_id' => ['nullable', 'integer', Rule::exists('email_accounts', 'id')->where('user_id', auth()->id())],
        ]);

        $sequence = EmailSequence::create([
            'user_id' => auth()->id(),
            'email_account_id' => $data['email_account_id'] ?? null,
            'name' => $data['name'],
            'status' => EmailSequence::STATUS_DRAFT,
        ]);

        return redirect()->route('email-sequences.edit', $sequence)
            ->with('success', 'Sequence created. Add your steps below.');
    }

    public function edit(EmailSequence $emailSequence): View
    {
        abort_unless($emailSequence->user_id === auth()->id(), 403);

        $emailSequence->loadCount('recipients')->load('steps');

        $accounts = EmailAccount::query()
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->orderBy('email')
            ->get();

        // Single-tenant: contact groups are shared, like contacts.
        $groups = ContactGroup::query()->orderBy('name')->get();

        return view('email-sequences.edit', [
            'sequence' => $emailSequence,
            'accounts' => $accounts,
            'groups' => $groups,
        ]);
    }

    public function update(Request $request, EmailSequence $emailSequence): RedirectResponse
    {
        abort_unless($emailSequence->user_id === auth()->id(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Scope to the caller's OWN accounts — a bare exists: rule would let a
            // user point a sequence at a colleague's mailbox and send through their
            // SMTP identity (H2 cross-account send-as impersonation).
            'email_account_id' => ['nullable', 'integer', Rule::exists('email_accounts', 'id')->where('user_id', auth()->id())],
        ]);

        $emailSequence->update($data);

        return redirect()->route('email-sequences.edit', $emailSequence)
            ->with('success', 'Sequence updated.');
    }

    public function storeStep(Request $request, EmailSequence $emailSequence): RedirectResponse
    {
        abort_unless($emailSequence->user_id === auth()->id(), 403);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body_text' => ['nullable', 'string'],
            'body_html' => ['nullable', 'string'],
            'delay_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'delay_hours' => ['nullable', 'integer', 'min:0', 'max:72'],
        ]);

        $maxOrder = $emailSequence->steps()->max('order') ?? -1;

        $emailSequence->steps()->create([
            'order' => $maxOrder + 1,
            'subject' => $data['subject'],
            'body_text' => $data['body_text'] ?? '',
            'body_html' => $data['body_html'] ?? '',
            'delay_days' => (int) ($data['delay_days'] ?? 0),
            'delay_hours' => (int) ($data['delay_hours'] ?? 0),
        ]);

        return redirect()->route('email-sequences.edit', $emailSequence)
            ->with('success', 'Step added.');
    }

    public function updateStep(Request $request, EmailSequence $emailSequence, EmailSequenceStep $step): RedirectResponse
    {
        abort_unless($emailSequence->user_id === auth()->id(), 403);
        abort_unless($step->email_sequence_id === $emailSequence->id, 404);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body_text' => ['nullable', 'string'],
            'body_html' => ['nullable', 'string'],
            'delay_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'delay_hours' => ['nullable', 'integer', 'min:0', 'max:72'],
            'order' => ['nullable', 'integer', 'min:0'],
        ]);

        $step->update($data);

        return redirect()->route('email-sequences.edit', $emailSequence)
            ->with('success', 'Step updated.');
    }

    public function deleteStep(EmailSequence $emailSequence, EmailSequenceStep $step): RedirectResponse
    {
        abort_unless($emailSequence->user_id === auth()->id(), 403);
        abort_unless($step->email_sequence_id === $emailSequence->id, 404);

        $step->delete();

        $emailSequence->steps()->where('order', '>', $step->order)->decrement('order');

        return redirect()->route('email-sequences.edit', $emailSequence)
            ->with('success', 'Step removed.');
    }

    /**
     * Enrol contacts into this sequence — the step that was previously missing,
     * which left the whole feature non-functional (nothing ever created recipient
     * rows, so ProcessEmailSequences always found zero due recipients). Audience
     * is either a chosen contact group or every contact that has an email address.
     * Idempotent: re-enrolling never duplicates a recipient row.
     */
    public function enroll(Request $request, EmailSequence $emailSequence): RedirectResponse
    {
        abort_unless($emailSequence->user_id === auth()->id(), 403);

        $data = $request->validate([
            'group_id' => ['nullable', 'integer', Rule::exists('contact_groups', 'id')],
        ]);

        $firstStepOrder = $emailSequence->steps()->min('order');
        if ($firstStepOrder === null) {
            return back()->with('error', 'Add at least one step before enrolling contacts.');
        }

        $query = Contact::query()
            ->whereNotNull('email')
            ->where('email', '!=', '');

        if (! empty($data['group_id'])) {
            $groupId = (int) $data['group_id'];
            $query->whereHas('groups', fn ($q) => $q->where('contact_groups.id', $groupId));
        }

        $enrolled = 0;
        $query->chunkById(500, function ($contacts) use ($emailSequence, $firstStepOrder, &$enrolled): void {
            foreach ($contacts as $contact) {
                $recipient = EmailSequenceRecipient::firstOrCreate(
                    ['email_sequence_id' => $emailSequence->id, 'contact_id' => $contact->id],
                    [
                        'email' => $contact->email,
                        'current_step' => $firstStepOrder,
                        'status' => EmailSequence::RECIPIENT_PENDING,
                        'next_send_at' => now(),
                    ],
                );
                if ($recipient->wasRecentlyCreated) {
                    $enrolled++;
                }
            }
        });

        return back()->with('success', "Enrolled {$enrolled} new contact(s) into this sequence.");
    }

    public function launch(EmailSequence $emailSequence): RedirectResponse
    {
        abort_unless($emailSequence->user_id === auth()->id(), 403);
        abort_unless($emailSequence->steps()->exists(), 422);

        // A sequence with no sending account would silently no-op on every run
        // (sendStep just logs a warning). If none was chosen, fall back to the
        // owner's single active account; refuse to launch when there's nothing
        // to send from, instead of appearing "Active" while doing nothing.
        if ($emailSequence->email_account_id === null) {
            $account = EmailAccount::query()
                ->where('user_id', auth()->id())
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            if ($account === null) {
                return back()->with('error', 'Connect an email account before launching this sequence.');
            }
            $emailSequence->email_account_id = $account->id;
        }

        $emailSequence->update([
            'email_account_id' => $emailSequence->email_account_id,
            'status' => EmailSequence::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        return redirect()->route('email-sequences.index')
            ->with('success', 'Sequence launched.');
    }

    public function pause(EmailSequence $emailSequence): RedirectResponse
    {
        abort_unless($emailSequence->user_id === auth()->id(), 403);

        $emailSequence->update(['status' => EmailSequence::STATUS_PAUSED]);

        return redirect()->route('email-sequences.index')
            ->with('success', 'Sequence paused.');
    }

    public function cancel(EmailSequence $emailSequence): RedirectResponse
    {
        abort_unless($emailSequence->user_id === auth()->id(), 403);

        $emailSequence->update(['status' => EmailSequence::STATUS_CANCELLED]);

        return redirect()->route('email-sequences.index')
            ->with('success', 'Sequence cancelled.');
    }

    public function destroy(EmailSequence $emailSequence): RedirectResponse
    {
        abort_unless($emailSequence->user_id === auth()->id(), 403);

        $emailSequence->delete();

        return redirect()->route('email-sequences.index')
            ->with('success', 'Sequence deleted.');
    }
}

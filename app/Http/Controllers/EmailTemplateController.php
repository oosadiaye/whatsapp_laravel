<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Support\EmailTemplateLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The team's own email-template library — reusable HTML the user BUILDS and saves
 * (contrast MessageTemplateController, which SYNCS read-only WhatsApp templates
 * from Meta). Single-tenant: every user with email permission sees every
 * template; user_id records the author.
 */
class EmailTemplateController extends Controller
{
    public function index(): View
    {
        return view('email-templates.index', [
            'templates' => EmailTemplate::with('creator')->latest()->get(),
            'starters' => EmailTemplateLibrary::catalogue(),
        ]);
    }

    public function create(): View
    {
        return view('email-templates.create', [
            'template' => null,
            'starters' => EmailTemplateLibrary::all(),
            // Seed the body when arriving from a starter design (?starter=key).
            'preselectHtml' => EmailTemplateLibrary::html((string) request('starter')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['user_id'] = auth()->id();

        EmailTemplate::create($data);

        return redirect()
            ->route('email-templates.index')
            ->with('success', __('Template ":name" saved.', ['name' => $data['name']]));
    }

    public function edit(EmailTemplate $emailTemplate): View
    {
        return view('email-templates.edit', [
            'template' => $emailTemplate,
            'starters' => EmailTemplateLibrary::all(),
            'preselectHtml' => '',
        ]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $emailTemplate->update($this->validated($request));

        return redirect()
            ->route('email-templates.index')
            ->with('success', __('Template ":name" updated.', ['name' => $emailTemplate->name]));
    }

    public function destroy(EmailTemplate $emailTemplate): RedirectResponse
    {
        $emailTemplate->delete();

        return redirect()
            ->route('email-templates.index')
            ->with('success', __('Template deleted.'));
    }

    /**
     * @return array{name: string, subject: ?string, body_html: string}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:100000'],
        ]);
    }
}

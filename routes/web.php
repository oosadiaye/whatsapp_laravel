<?php

use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContactGroupController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CloudWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Provider webhooks — unauthenticated by nature, so hardened with an abuse
// rate-limit + an optional source-IP allowlist (config/voice.php). CSRF is
// excluded in bootstrap/app.php; SubstituteBindings still resolves {instance}.
Route::middleware([
    'webhook.allowed-ips',
    'throttle:'.((int) config('voice.webhook_rate_limit', 600)).',1',
])->group(function () {
    // Meta Cloud API per-instance webhook (verify GET + events POST).
    Route::get('/webhooks/whatsapp/{instance}', [CloudWebhookController::class, 'verify'])
        ->name('webhook.cloud.verify');
    Route::post('/webhooks/whatsapp/{instance}', [CloudWebhookController::class, 'handle'])
        ->name('webhook.cloud.handle');

    // Africa's Talking voice webhook (Phase 18). The optional {secret} path
    // segment authenticates the callback (AT voice callbacks are unsigned) —
    // see config voice.at_webhook_secret.
    Route::post('/webhooks/africastalking/voice/{secret?}', [\App\Http\Controllers\AfricasTalkingWebhookController::class, 'handle'])
        ->name('webhook.africastalking.voice');
});

// Public one-click email unsubscribe. Signed URL (tamper-proof); every campaign
// email footer links here + a List-Unsubscribe header. GET for the footer link,
// POST for RFC 8058 one-click (CSRF-exempt in bootstrap/app.php). No auth —
// recipients aren't users.
Route::match(['get', 'post'], '/email/unsubscribe', [\App\Http\Controllers\UnsubscribeController::class, 'show'])
    ->middleware('signed')
    ->name('email.unsubscribe');

// Open-tracking pixel (signed per-recipient). Returns a 1x1 GIF and records the
// open. No auth — recipients aren't users.
Route::get('/email/open/{log}', [\App\Http\Controllers\EmailTrackingController::class, 'open'])
    ->middleware('signed')
    ->name('email.open');

// Authenticated routes — role/permission gates per resource group
Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard + profile — anyone logged in
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Single-instance app: the one WhatsApp number is configured on the
    // Settings page (SettingsController::upsertWhatsAppInstance). The old
    // multi-instance CRUD routes (/instances*) were removed in the unify.

    // ─── Contact Groups ────────────────────────────────────────────────────
    Route::middleware('permission:groups.view')->group(function () {
        Route::get('/groups', [ContactGroupController::class, 'index'])->name('groups.index');
        Route::get('/groups/{group}', [ContactGroupController::class, 'show'])->name('groups.show');
    });
    Route::middleware('permission:groups.create')->group(function () {
        Route::post('/groups', [ContactGroupController::class, 'store'])->name('groups.store');
    });
    Route::middleware('permission:groups.edit')->group(function () {
        Route::put('/groups/{group}', [ContactGroupController::class, 'update'])->name('groups.update');
    });
    Route::middleware('permission:groups.delete')->group(function () {
        Route::delete('/groups/{group}', [ContactGroupController::class, 'destroy'])->name('groups.destroy');
    });

    // ─── Contacts ──────────────────────────────────────────────────────────
    // import comes before any /contacts/{contact} parameter routes.
    Route::middleware('permission:contacts.import')->group(function () {
        Route::get('/contacts/import', [ContactController::class, 'importForm'])->name('contacts.import');
        Route::post('/contacts/import', [ContactController::class, 'importProcess'])->name('contacts.importProcess');
        // Sample CSV with the four columns the import job consumes. Kept
        // under the same contacts.import permission gate — anyone who can
        // import can see the template; users without import permission
        // shouldn't be probing the column spec.
        Route::get('/contacts/import/template', [ContactController::class, 'downloadTemplate'])->name('contacts.importTemplate');
    });
    Route::middleware('permission:contacts.view')->group(function () {
        Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::get('/groups/{group}/export', [ContactController::class, 'exportGroup'])->name('contacts.exportGroup');
    });
    Route::middleware('permission:contacts.edit')->group(function () {
        Route::get('/contacts/{contact}/edit', [ContactController::class, 'edit'])->name('contacts.edit');
        Route::put('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    });
    Route::middleware('permission:contacts.delete')->group(function () {
        Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    });

    // ─── Message Templates ─────────────────────────────────────────────────
    // Static segments (sync, create) come before {template} wildcards so
    // /templates/create doesn't get matched as /templates/{template=create}.
    Route::middleware('permission:templates.sync')->group(function () {
        Route::post('/templates/sync', [MessageTemplateController::class, 'sync'])->name('templates.sync');
    });
    Route::middleware('permission:templates.create')->group(function () {
        Route::get('/templates/create', [MessageTemplateController::class, 'create'])->name('templates.create');
        Route::post('/templates', [MessageTemplateController::class, 'store'])->name('templates.store');
    });
    Route::middleware('permission:templates.view')->group(function () {
        Route::get('/templates', [MessageTemplateController::class, 'index'])->name('templates.index');
    });
    Route::middleware('permission:templates.submit')->group(function () {
        Route::post('/templates/{template}/submit', [MessageTemplateController::class, 'submitToMeta'])->name('templates.submit');
    });
    Route::middleware('permission:templates.edit')->group(function () {
        Route::get('/templates/{template}/edit', [MessageTemplateController::class, 'edit'])->name('templates.edit');
        Route::put('/templates/{template}', [MessageTemplateController::class, 'update'])->name('templates.update');
        Route::patch('/templates/{template}', [MessageTemplateController::class, 'update']);
    });
    Route::middleware('permission:templates.view')->group(function () {
        Route::get('/templates/{template}', [MessageTemplateController::class, 'show'])->name('templates.show');
    });
    Route::middleware('permission:templates.delete')->group(function () {
        Route::delete('/templates/{template}', [MessageTemplateController::class, 'destroy'])->name('templates.destroy');
    });

    // ─── Campaigns ─────────────────────────────────────────────────────────
    // /campaigns/create before /campaigns/{campaign} (same routing rule).
    Route::middleware('permission:campaigns.create')->group(function () {
        Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
        Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::post('/campaigns/{campaign}/clone', [CampaignController::class, 'clone'])->name('campaigns.clone');
    });
    Route::middleware('permission:campaigns.view')->group(function () {
        Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
        Route::get('/campaigns/{campaign}/export', [CampaignController::class, 'exportLogs'])->name('campaigns.exportLogs');
    });
    Route::middleware('permission:campaigns.edit')->group(function () {
        Route::get('/campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('campaigns.edit');
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
    });
    Route::middleware('permission:campaigns.launch')->group(function () {
        Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->name('campaigns.launch');
        Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
        Route::post('/campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->name('campaigns.resume');
    });
    Route::middleware('permission:campaigns.cancel')->group(function () {
        Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('campaigns.cancel');
        // Bulk-cancel all QUEUED + RUNNING campaigns owned by the current user.
        // Useful when the queue worker has been down and a backlog of campaigns
        // is stuck — one click clears the lot.
        Route::post('/campaigns/clear-queue', [CampaignController::class, 'clearQueue'])->name('campaigns.clearQueue');
    });
    Route::middleware('permission:campaigns.delete')->group(function () {
        // Bulk-destroy MUST be declared BEFORE the wildcard {campaign} route,
        // otherwise Laravel matches /campaigns/bulk-delete to {campaign}=bulk-delete
        // and 404s. Same lesson as the /contacts/import vs /contacts/{contact}
        // ordering on line 74-78.
        Route::post('/campaigns/bulk-delete', [CampaignController::class, 'bulkDestroy'])->name('campaigns.bulkDestroy');
        Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
    });

    // ─── Email Campaigns (bulk email to prospects) ─────────────────────────
    // Static routes before {emailCampaign} wildcards.
    Route::middleware('permission:email.create')->group(function () {
        Route::get('/email-campaigns/create', [\App\Http\Controllers\EmailCampaignController::class, 'create'])->name('email-campaigns.create');
        Route::post('/email-campaigns', [\App\Http\Controllers\EmailCampaignController::class, 'store'])->name('email-campaigns.store');
    });
    Route::middleware('permission:email.view')->group(function () {
        Route::get('/email-campaigns', [\App\Http\Controllers\EmailCampaignController::class, 'index'])->name('email-campaigns.index');
        Route::get('/email-campaigns/{emailCampaign}', [\App\Http\Controllers\EmailCampaignController::class, 'show'])->name('email-campaigns.show');
    });
    Route::middleware('permission:email.edit')->group(function () {
        Route::get('/email-campaigns/{emailCampaign}/edit', [\App\Http\Controllers\EmailCampaignController::class, 'edit'])->name('email-campaigns.edit');
        Route::put('/email-campaigns/{emailCampaign}', [\App\Http\Controllers\EmailCampaignController::class, 'update'])->name('email-campaigns.update');
    });
    Route::middleware('permission:email.send')->group(function () {
        Route::post('/email-campaigns/{emailCampaign}/launch', [\App\Http\Controllers\EmailCampaignController::class, 'launch'])->name('email-campaigns.launch');
        Route::post('/email-campaigns/{emailCampaign}/cancel', [\App\Http\Controllers\EmailCampaignController::class, 'cancel'])->name('email-campaigns.cancel');
    });
    Route::middleware('permission:email.delete')->group(function () {
        Route::delete('/email-campaigns/{emailCampaign}', [\App\Http\Controllers\EmailCampaignController::class, 'destroy'])->name('email-campaigns.destroy');
    });

    // ─── Conversations / Chat (Phase 13/14) ────────────────────────────────
    Route::middleware('role_or_permission:conversations.view_all|conversations.view_assigned')->group(function () {
        Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::get('/conversations/messages/{message}/media', [ConversationController::class, 'downloadMedia'])->name('conversations.media');
    });

    // ─── Calls feed + Workspace (Phase 15 / 20) ────────────────────────────
    Route::middleware('role_or_permission:conversations.view_all|conversations.view_assigned')->group(function () {
        Route::get('/calls', [\App\Http\Controllers\CallController::class, 'index'])
            ->name('calls.index');
        // Unified agent Call Workspace: live call + queue/history + AI/notes panel.
        Route::get('/workspace', [\App\Http\Controllers\CallController::class, 'workspace'])
            ->name('calls.workspace');
        // Stream a call recording (private disk, per-call access checked in the action).
        Route::get('/calls/{call}/recording', [\App\Http\Controllers\CallController::class, 'downloadRecording'])
            ->name('calls.recording.download');
        // Voicemail inbox (inbound callers who left a message).
        Route::get('/voicemails', [\App\Http\Controllers\VoicemailController::class, 'index'])
            ->name('voicemails.index');
        Route::post('/voicemails/{voicemail}/heard', [\App\Http\Controllers\VoicemailController::class, 'markHeard'])
            ->name('voicemails.markHeard');
    });
    Route::middleware('permission:conversations.reply')->group(function () {
        Route::post('/conversations/{conversation}/reply', [ConversationController::class, 'reply'])->name('conversations.reply');

        // Phase 17 — inbound call browser answer
        Route::post('/calls/{call}/claim', [\App\Http\Controllers\CallController::class, 'claim'])->name('calls.claim');
        Route::post('/calls/{call}/answer', [\App\Http\Controllers\CallController::class, 'answer'])->name('calls.answer');
        Route::post('/calls/{call}/decline', [\App\Http\Controllers\CallController::class, 'decline'])->name('calls.decline');
        Route::post('/calls/{call}/hangup', [\App\Http\Controllers\CallController::class, 'hangup'])->name('calls.hangup');
        Route::post('/calls/{call}/quality', [\App\Http\Controllers\CallController::class, 'quality'])->name('calls.quality');

        // Phase 20 — Call Workspace: upload recording for AI analysis + log notes.
        Route::post('/calls/{call}/recording', [\App\Http\Controllers\CallController::class, 'storeRecording'])->name('calls.recording.store');
        Route::post('/calls/{call}/notes', [\App\Http\Controllers\CallController::class, 'storeNote'])->name('calls.notes.store');
        // Blind transfer to another agent or a PSTN number.
        Route::post('/calls/{call}/transfer', [\App\Http\Controllers\CallController::class, 'transfer'])->name('calls.transfer');
    });
    Route::middleware('permission:conversations.reply')->group(function () {
        Route::post('/contacts/{contact}/chat', [ContactController::class, 'startChat'])
            ->name('contacts.startChat');
    });
    Route::middleware('permission:conversations.assign')->group(function () {
        Route::post('/conversations/{conversation}/assign', [ConversationController::class, 'assign'])->name('conversations.assign');
    });
    Route::middleware('permission:conversations.call')->group(function () {
        Route::post('/conversations/{conversation}/call', [ConversationController::class, 'initiateCall'])->name('conversations.initiateCall');
        Route::post('/conversations/{conversation}/calls/{call}/end', [ConversationController::class, 'endCall'])->name('conversations.endCall');

        // Phase 18 — outbound PSTN dial via Africa's Talking
        Route::post('/calls/outbound', [\App\Http\Controllers\CallController::class, 'placeOutbound'])
            ->name('calls.outbound');
    });
    Route::middleware('permission:conversations.call')->group(function () {
        Route::post('/contacts/{contact}/call', [ContactController::class, 'startCall'])
            ->name('contacts.startCall');
    });

    // ─── Settings ──────────────────────────────────────────────────────────
    Route::middleware('permission:settings.view')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    });
    Route::middleware('permission:settings.edit')->group(function () {
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    // ─── User Management ───────────────────────────────────────────────────
    Route::middleware('permission:users.create')->group(function () {
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
    });
    Route::middleware('permission:users.view')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
    });

    // Team-load dashboard (Phase 15) — separate permission from users.*
    // so managers get team visibility without user-CRUD rights.
    // See RolesAndPermissionsSeeder for the team.view → role grants.
    Route::middleware('permission:team.view')->group(function () {
        Route::get('/team', [\App\Http\Controllers\TeamLoadController::class, 'index'])
            ->name('team.index');
        // Live operations wallboard — realtime call board (Livewire Wallboard).
        Route::view('/wallboard', 'wallboard.index')->name('wallboard');
    });
    Route::middleware('permission:users.edit')->group(function () {
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggleActive');
    });
    Route::middleware('permission:users.delete')->group(function () {
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});

require __DIR__.'/auth.php';

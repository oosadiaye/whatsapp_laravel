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
use App\Http\Controllers\WhatsAppInstanceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Meta Cloud API per-instance webhook (verify GET + events POST)
// CSRF excluded in bootstrap/app.php; SubstituteBindings still resolves {instance}.
Route::get('/webhooks/whatsapp/{instance}', [CloudWebhookController::class, 'verify'])
    ->name('webhook.cloud.verify');
Route::post('/webhooks/whatsapp/{instance}', [CloudWebhookController::class, 'handle'])
    ->name('webhook.cloud.handle');

// Authenticated routes — role/permission gates per resource group
Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard + profile — anyone logged in
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ─── WhatsApp Instances ────────────────────────────────────────────────
    // Static routes first (instances/create), then parameter routes (instances/{instance}),
    // otherwise Laravel matches /instances/create as /instances/{instance=create} and 404s.
    Route::middleware('permission:instances.create')->group(function () {
        Route::get('/instances/create', [WhatsAppInstanceController::class, 'create'])->name('instances.create');
        Route::post('/instances', [WhatsAppInstanceController::class, 'store'])->name('instances.store');
    });
    Route::middleware('permission:instances.view')->group(function () {
        Route::get('/instances', [WhatsAppInstanceController::class, 'index'])->name('instances.index');
        Route::get('/instances/{instance}', [WhatsAppInstanceController::class, 'show'])->name('instances.show');
    });
    Route::middleware('permission:instances.edit')->group(function () {
        Route::post('/instances/{instance}/default', [WhatsAppInstanceController::class, 'setDefault'])->name('instances.setDefault');
    });
    Route::middleware('permission:instances.delete')->group(function () {
        Route::delete('/instances/{instance}', [WhatsAppInstanceController::class, 'destroy'])->name('instances.destroy');
    });

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
        Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
    });

    // ─── Conversations / Chat (Phase 13/14) ────────────────────────────────
    Route::middleware('role_or_permission:conversations.view_all|conversations.view_assigned')->group(function () {
        Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::get('/conversations/messages/{message}/media', [ConversationController::class, 'downloadMedia'])->name('conversations.media');
    });

    // ─── Calls feed (Phase 15) ─────────────────────────────────────────────
    Route::middleware('role_or_permission:conversations.view_all|conversations.view_assigned')->group(function () {
        Route::get('/calls', [\App\Http\Controllers\CallController::class, 'index'])
            ->name('calls.index');
    });
    Route::middleware('permission:conversations.reply')->group(function () {
        Route::post('/conversations/{conversation}/reply', [ConversationController::class, 'reply'])->name('conversations.reply');
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

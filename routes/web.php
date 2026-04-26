<?php

use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContactGroupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\CloudWebhookController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WhatsAppInstanceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Webhooks (no CSRF, no auth — external services post here)
Route::post('/webhook/evolution', [WebhookController::class, 'handle'])
    ->name('webhook.evolution')
    ->withoutMiddleware(['web']);

// Meta Cloud API per-instance webhook (verify GET + events POST)
Route::get('/webhooks/whatsapp/{instance}', [CloudWebhookController::class, 'verify'])
    ->name('webhook.cloud.verify')
    ->withoutMiddleware(['web']);
Route::post('/webhooks/whatsapp/{instance}', [CloudWebhookController::class, 'handle'])
    ->name('webhook.cloud.handle')
    ->withoutMiddleware(['web']);

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Profile (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // WhatsApp Instances
    Route::get('/instances', [WhatsAppInstanceController::class, 'index'])->name('instances.index');
    Route::get('/instances/create', [WhatsAppInstanceController::class, 'create'])->name('instances.create');
    Route::post('/instances', [WhatsAppInstanceController::class, 'store'])->name('instances.store');
    Route::get('/instances/{instance}', [WhatsAppInstanceController::class, 'show'])->name('instances.show');
    Route::delete('/instances/{instance}', [WhatsAppInstanceController::class, 'destroy'])->name('instances.destroy');
    Route::post('/instances/{instance}/default', [WhatsAppInstanceController::class, 'setDefault'])->name('instances.setDefault');
    Route::get('/instances/{instance}/qr-status', [WhatsAppInstanceController::class, 'qrStatus'])->name('instances.qrStatus');

    // Contact Groups
    Route::get('/groups', [ContactGroupController::class, 'index'])->name('groups.index');
    Route::post('/groups', [ContactGroupController::class, 'store'])->name('groups.store');
    Route::get('/groups/{group}', [ContactGroupController::class, 'show'])->name('groups.show');
    Route::put('/groups/{group}', [ContactGroupController::class, 'update'])->name('groups.update');
    Route::delete('/groups/{group}', [ContactGroupController::class, 'destroy'])->name('groups.destroy');

    // Contacts
    Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::get('/contacts/import', [ContactController::class, 'importForm'])->name('contacts.import');
    Route::post('/contacts/import', [ContactController::class, 'importProcess'])->name('contacts.importProcess');
    Route::get('/contacts/{contact}/edit', [ContactController::class, 'edit'])->name('contacts.edit');
    Route::put('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::get('/groups/{group}/export', [ContactController::class, 'exportGroup'])->name('contacts.exportGroup');

    // Message Templates
    Route::post('/templates/sync', [MessageTemplateController::class, 'sync'])->name('templates.sync');
    Route::resource('templates', MessageTemplateController::class);

    // Campaigns
    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
    Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::get('/campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('campaigns.edit');
    Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
    Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
    Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->name('campaigns.launch');
    Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
    Route::post('/campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->name('campaigns.resume');
    Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('campaigns.cancel');
    Route::post('/campaigns/{campaign}/clone', [CampaignController::class, 'clone'])->name('campaigns.clone');
    Route::get('/campaigns/{campaign}/export', [CampaignController::class, 'exportLogs'])->name('campaigns.exportLogs');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});

require __DIR__.'/auth.php';

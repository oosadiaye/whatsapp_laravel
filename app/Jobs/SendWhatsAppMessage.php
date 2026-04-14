<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Services\CampaignService;
use App\Services\ContactImportService;
use App\Services\EvolutionApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public MessageLog $log,
        public Campaign $campaign,
        public Contact $contact,
    ) {
        $this->onQueue('messages');
    }

    public function handle(EvolutionApiService $api): void
    {
        $this->campaign->refresh();

        if ($this->campaign->status === 'PAUSED') {
            self::dispatch($this->log, $this->campaign, $this->contact)
                ->delay(now()->addMinutes(2))
                ->onQueue('messages');

            return;
        }

        if ($this->campaign->status === 'CANCELLED') {
            $this->log->update([
                'status' => 'FAILED',
                'error_message' => 'Campaign cancelled',
            ]);

            return;
        }

        $personalizer = new ContactImportService();
        $message = $personalizer->personalizeMessage(
            $this->campaign->message,
            $this->contact,
            $this->campaign->name,
        );

        $instanceName = $this->campaign->whatsAppInstance->instance_name;

        if ($this->campaign->media_path) {
            $response = $api->sendMedia(
                $instanceName,
                $this->contact->phone,
                $message,
                asset($this->campaign->media_path),
                $this->campaign->media_type,
            );
        } else {
            $response = $api->sendText(
                $instanceName,
                $this->contact->phone,
                $message,
            );
        }

        $this->log->update([
            'status' => 'SENT',
            'whatsapp_message_id' => $response['key']['id'] ?? null,
            'sent_at' => Carbon::now(),
        ]);

        $this->campaign->increment('sent_count');

        $campaignService = new CampaignService();
        $campaignService->checkCompletion($this->campaign);
    }

    public function failed(Throwable $exception): void
    {
        $this->log->update([
            'status' => 'FAILED',
            'error_message' => $exception->getMessage(),
        ]);

        $this->campaign->increment('failed_count');

        $campaignService = new CampaignService();
        $campaignService->checkCompletion($this->campaign);

        Log::error('SendWhatsAppMessage failed', [
            'campaign_id' => $this->campaign->id,
            'contact_id' => $this->contact->id,
            'error' => $exception->getMessage(),
        ]);
    }
}

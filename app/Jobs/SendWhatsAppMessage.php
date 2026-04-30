<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Services\CampaignService;
use App\Services\ContactImportService;
use App\Services\WhatsAppMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches one WhatsApp message for a single contact in a campaign.
 *
 * Uses {@see WhatsAppMessenger} so the underlying driver (Cloud API vs
 * Evolution) is picked based on the campaign's instance — this job has no
 * direct dependency on either provider.
 *
 * Decision tree on every send:
 *   - Campaign cancelled?            → mark FAILED, exit.
 *   - Campaign paused?               → re-queue with delay.
 *   - Campaign has template + cloud? → sendTemplate (with personalized params).
 *   - Campaign has media?            → sendMedia.
 *   - Otherwise                      → sendText.
 */
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

    public function handle(WhatsAppMessenger $messenger): void
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

        $instance = $this->campaign->whatsAppInstance;

        if ($instance === null) {
            $this->log->update([
                'status' => 'FAILED',
                'error_message' => 'Campaign has no WhatsApp instance attached',
            ]);

            return;
        }

        $personalizer = new ContactImportService();

        // Branch 1: template-driven send (production path for cold outreach).
        if ($this->campaign->shouldSendAsTemplate()) {
            $template = $this->campaign->messageTemplate;
            $language = $this->campaign->template_language
                ?? $template?->language
                ?? 'en_US';

            $components = $this->buildTemplateComponents($template?->components ?? [], $personalizer);

            $result = $messenger->sendTemplate(
                $instance,
                $this->contact->phone,
                $template?->name ?? '',
                $language,
                $components,
            );
        } elseif ($this->campaign->media_path) {
            // Branch 2: media + caption (only legal inside the 24h window).
            $caption = $personalizer->personalizeMessage(
                $this->campaign->message,
                $this->contact,
                $this->campaign->name,
            );

            $result = $messenger->sendMedia(
                $instance,
                $this->contact->phone,
                $caption,
                asset($this->campaign->media_path),
                (string) $this->campaign->media_type,
            );
        } else {
            // Branch 3: plain text (only legal inside the 24h conversation window).
            $message = $personalizer->personalizeMessage(
                $this->campaign->message,
                $this->contact,
                $this->campaign->name,
            );

            $result = $messenger->sendText(
                $instance,
                $this->contact->phone,
                $message,
            );
        }

        $this->log->update([
            'status' => 'SENT',
            'whatsapp_message_id' => $result->messageId,
            'sent_at' => Carbon::now(),
        ]);

        $this->campaign->increment('sent_count');

        (new CampaignService())->checkCompletion($this->campaign);
    }

    /**
     * Build per-contact `components` payload for a template send.
     *
     * Handles two component types Meta requires at send time:
     *
     *   1. HEADER with format=IMAGE/VIDEO/DOCUMENT
     *      Meta requires the media URL provided at send time, even though the
     *      template was approved with an example. Without this, Meta returns
     *      error 132012 "Format mismatch, expected IMAGE, received UNKNOWN".
     *      The URL comes from $campaign->header_media_url, which is required
     *      by StoreCampaignRequest when a media-header template is selected.
     *
     *   2. BODY with {{1}}, {{2}} variable placeholders
     *      Personalized per-contact via resolveTemplateVariable().
     *
     * Skipped intentionally:
     *   - HEADER format=TEXT without variables (Meta doesn't require parameters)
     *   - FOOTER (always static text — no parameters)
     *   - BUTTONS (URL/quick-reply parameters not yet supported; defer until
     *     a customer reports needing them)
     *
     * @param  array<int, array<string, mixed>>  $templateComponents
     * @return array<int, array<string, mixed>>
     */
    private function buildTemplateComponents(array $templateComponents, ContactImportService $personalizer): array
    {
        $output = [];

        foreach ($templateComponents as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));

            if ($type === 'HEADER') {
                $headerComponent = $this->buildHeaderComponent($component);
                if ($headerComponent !== null) {
                    $output[] = $headerComponent;
                }
                continue;
            }

            if ($type !== 'BODY') {
                continue;  // FOOTER / BUTTONS not yet supported
            }

            $bodyText = (string) ($component['text'] ?? '');
            $variableCount = preg_match_all('/{{\s*(\d+)\s*}}/', $bodyText, $matches);

            if ($variableCount === 0 || $variableCount === false) {
                continue;
            }

            $parameters = [];
            foreach ($matches[1] as $position) {
                $parameters[] = [
                    'type' => 'text',
                    'text' => $this->resolveTemplateVariable((int) $position),
                ];
            }

            $output[] = [
                'type' => 'body',
                'parameters' => $parameters,
            ];
        }

        return $output;
    }

    /**
     * Build the header component for a template send.
     *
     * @param  array<string, mixed>  $component  the HEADER component definition from the template
     * @return array<string, mixed>|null  shaped for Meta's components[] array, or null if no params required
     */
    private function buildHeaderComponent(array $component): ?array
    {
        $format = strtoupper((string) ($component['format'] ?? 'TEXT'));

        // Media headers — IMAGE/VIDEO/DOCUMENT all need the URL at send time
        if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $url = (string) ($this->campaign->header_media_url ?? '');
            if ($url === '') {
                // Should never happen — StoreCampaignRequest blocks this — but be defensive.
                return null;
            }

            $mediaKey = strtolower($format);

            return [
                'type' => 'header',
                'parameters' => [[
                    'type' => $mediaKey,
                    $mediaKey => ['link' => $url],
                ]],
            ];
        }

        // TEXT header — only needs parameters if it has {{1}} variables
        if ($format === 'TEXT') {
            $headerText = (string) ($component['text'] ?? '');
            if (preg_match_all('/{{\s*(\d+)\s*}}/', $headerText, $matches) > 0) {
                $parameters = [];
                foreach ($matches[1] as $position) {
                    $parameters[] = [
                        'type' => 'text',
                        'text' => $this->resolveTemplateVariable((int) $position),
                    ];
                }

                return [
                    'type' => 'header',
                    'parameters' => $parameters,
                ];
            }
        }

        return null;  // static text header / unknown format — no params needed
    }

    /**
     * Map a positional template variable ({{1}}, {{2}}, ...) to a contact field.
     * Mirrors the existing personalization order — name, phone, first_name,
     * custom_field_1, then a sane default.
     */
    private function resolveTemplateVariable(int $position): string
    {
        return match ($position) {
            1 => $this->contact->name ?? $this->contact->phone,
            2 => $this->contact->phone,
            3 => $this->firstName($this->contact->name ?? ''),
            4 => (string) ($this->contact->custom_fields['custom_field_1'] ?? ''),
            default => '',
        };
    }

    private function firstName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName));

        return $parts[0] ?? '';
    }

    public function failed(Throwable $exception): void
    {
        $this->log->update([
            'status' => 'FAILED',
            'error_message' => $exception->getMessage(),
        ]);

        $this->campaign->increment('failed_count');

        (new CampaignService())->checkCompletion($this->campaign);

        Log::error('SendWhatsAppMessage failed', [
            'campaign_id' => $this->campaign->id,
            'contact_id' => $this->contact->id,
            'error' => $exception->getMessage(),
        ]);
    }
}

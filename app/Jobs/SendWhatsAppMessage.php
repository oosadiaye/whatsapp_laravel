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

    // Exactly one attempt. Meta's send API has no idempotency key, so a
    // job-level retry after a send that already reached Meta (a 5xx *response*
    // after processing, or a post-send DB write that threw) would deliver a
    // DUPLICATE message to the customer. Transient CONNECTION errors are
    // retried safely inside WhatsAppCloudApiService::client() (which retries
    // only ConnectionException, never a response) — so we don't lose
    // connection resilience by not retrying the whole job. See failed().
    public int $tries = 1;

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
        // Idempotency guard: once this log leaves PENDING it has already been
        // sent (or failed) by a prior run of this job. Meta's send API has no
        // idempotency key, so if the worker retries after a successful send
        // (e.g. the status write below, the increment, or checkCompletion threw,
        // or the worker restarted) re-entering a send branch would deliver a
        // DUPLICATE message to the customer. Bail instead.
        $this->log->refresh();
        if ($this->log->status !== 'PENDING') {
            return;
        }

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
        } else {
            // Plain text (only legal inside the 24h conversation window).
            // NB: there is no campaigns.media_path send branch — campaign media
            // rides on the template header (header_media_url), not media_path.
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
     * Map a positional template variable ({{1}}, {{2}}, ...) to a contact field
     * via the shared, config-driven {@see \App\Support\Personalizer} — the same
     * resolver the freeform path uses, so the two can't drift. Order lives in
     * config/personalization.php.
     */
    private function resolveTemplateVariable(int $position): string
    {
        return (new \App\Support\Personalizer())->positional($this->contact, $position);
    }

    /**
     * Append a remediation hint when the message contains a known Meta
     * Cloud API error code. Returns the original message unchanged when
     * no known code matches. Hints are deliberately short and actionable.
     */
    private function annotateMetaError(string $message): string
    {
        $hints = [
            // 131053 — the one this app hits most often. Meta couldn't fetch
            // the URL we passed in the template's header parameter. Cause is
            // almost always 'public/storage' symlink missing on the server,
            // so /storage/campaign-headers/*.jpg returns 404 to Meta's fetcher.
            '131053' => "\n\nHINT: Meta couldn't fetch the header-media URL. "
                ."Most common cause: 'public/storage' symlink missing on the server. "
                ."Fix: run 'php artisan storage:link', then re-launch the campaign.",

            // 132012 — template format mismatch (header expected IMAGE, got UNKNOWN, etc).
            '132012' => "\n\nHINT: Template-format mismatch. The template expects a media "
                ."header but no media file was supplied (or wrong type). Edit the campaign "
                ."and upload a header media file matching the template's expected format.",

            // 131056 — per-pair rate limit. Same business+customer pair too frequently.
            '131056' => "\n\nHINT: Meta per-pair rate limit hit. Same business+customer pair "
                ."contacted too often. Lower campaign rate_per_minute or wait before retrying.",
        ];

        foreach ($hints as $code => $hint) {
            // PHP casts numeric-string array keys to int, so $code is an int
            // here — str_contains() needs a string needle or it TypeErrors,
            // which previously crashed failed() on every real send failure.
            if (str_contains($message, (string) $code)) {
                return $message.$hint;
            }
        }

        return $message;
    }

    public function failed(Throwable $exception): void
    {
        // Never clobber a log that already resolved. With $tries=1 a thrown send
        // lands here, but if the send actually reached Meta (log is already
        // SENT) and only post-send bookkeeping threw, flipping it to FAILED
        // would corrupt state and double-count failed_count. Act only while the
        // log is still PENDING.
        if (($this->log->fresh()?->status) !== 'PENDING') {
            return;
        }

        // Annotate well-known Meta Cloud API error codes with a remediation
        // hint so operators can act without trawling Meta's docs. The full
        // original message is preserved; the hint is appended.
        $message = $this->annotateMetaError($exception->getMessage());

        $this->log->update([
            'status' => 'FAILED',
            'error_message' => $message,
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

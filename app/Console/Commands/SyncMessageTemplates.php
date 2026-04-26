<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MessageTemplate;
use App\Models\WhatsAppInstance;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Periodic refresh of message-template state from Meta.
 *
 * Why this exists:
 *   - When a user submits a template via {@see MessageTemplateController::submitToMeta},
 *     it lands in PENDING. Meta typically transitions to APPROVED or REJECTED
 *     within a few minutes to a few hours, but the user has no way to know
 *     unless they manually re-sync.
 *   - Template categories or quality ratings can change server-side
 *     (e.g. Meta downgrading a low-engagement marketing template).
 *
 * What it does:
 *   - For every Cloud API instance that has at least one template (i.e.
 *     someone has used templates with it), call fetchTemplates() and upsert
 *     fresh state. Effectively the same as the user clicking "Sync from
 *     WhatsApp" but for every instance, on a schedule.
 *
 * Schedule (registered in routes/console.php):
 *   - Every 15 minutes — frequent enough that a freshly-submitted template
 *     reaches APPROVED visibility in ≤15min, infrequent enough not to spam
 *     Graph API. Meta's rate limit on /message_templates is generous
 *     (200 req/hour per WABA) so this is well below threshold.
 */
class SyncMessageTemplates extends Command
{
    protected $signature = 'templates:sync-status
                            {--instance= : Sync only this instance ID (default: all)}';

    protected $description = 'Refresh message-template status from Meta for all Cloud API instances.';

    public function handle(WhatsAppCloudApiService $cloudApi): int
    {
        $query = WhatsAppInstance::query()
            ->whereNotNull('access_token')
            ->whereNotNull('phone_number_id')
            ->whereNotNull('waba_id');

        if ($instanceId = $this->option('instance')) {
            $query->where('id', $instanceId);
        } else {
            // Skip instances that have never had a template — no point hitting Meta for them.
            $query->whereHas('campaigns'); // proxy: instances actually used in campaigns
        }

        $instances = $query->get();

        if ($instances->isEmpty()) {
            $this->info('No Cloud API instances eligible for sync.');

            return self::SUCCESS;
        }

        $totalCreated = 0;
        $totalUpdated = 0;
        $errors = 0;

        foreach ($instances as $instance) {
            $this->line("→ Instance #{$instance->id} ({$instance->instance_name})");

            try {
                $remote = $cloudApi->fetchTemplates($instance);
            } catch (Throwable $e) {
                $this->error("  failed: {$e->getMessage()}");
                $errors++;

                continue;
            }

            [$created, $updated] = $this->upsertAll($instance, $remote);
            $totalCreated += $created;
            $totalUpdated += $updated;

            $this->info("  +{$created} created, ~{$updated} updated");
        }

        $this->newLine();
        $this->info("Done. {$totalCreated} created, {$totalUpdated} updated, {$errors} error(s).");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, array<string, mixed>>  $remoteTemplates
     * @return array{0: int, 1: int}  [created, updated]
     */
    private function upsertAll(WhatsAppInstance $instance, array $remoteTemplates): array
    {
        $created = 0;
        $updated = 0;

        foreach ($remoteTemplates as $remote) {
            $name = (string) ($remote['name'] ?? 'unnamed');
            $language = (string) ($remote['language'] ?? 'en_US');
            $remoteId = (string) ($remote['id'] ?? $name);
            $components = is_array($remote['components'] ?? null) ? $remote['components'] : [];

            $payload = [
                'user_id' => $instance->user_id,
                'whatsapp_instance_id' => $instance->id,
                'whatsapp_template_id' => $remoteId,
                'name' => $name,
                'language' => $language,
                'status' => strtoupper((string) ($remote['status'] ?? MessageTemplate::STATUS_APPROVED)),
                'content' => $this->extractBody($components),
                'components' => $components,
                'category' => $this->mapCategory((string) ($remote['category'] ?? '')),
                'synced_at' => Carbon::now(),
            ];

            $existing = MessageTemplate::where('whatsapp_instance_id', $instance->id)
                ->where('whatsapp_template_id', $remoteId)
                ->where('language', $language)
                ->first();

            if ($existing) {
                $existing->update($payload);
                $updated++;
            } else {
                MessageTemplate::create($payload);
                $created++;
            }
        }

        return [$created, $updated];
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     */
    private function extractBody(array $components): string
    {
        foreach ($components as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                return (string) ($component['text'] ?? '');
            }
        }

        return '';
    }

    private function mapCategory(string $metaCategory): string
    {
        return match (strtoupper($metaCategory)) {
            'MARKETING' => 'promotional',
            'UTILITY', 'AUTHENTICATION' => 'transactional',
            default => 'reminder',
        };
    }
}

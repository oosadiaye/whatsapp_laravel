<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\WhatsAppInstance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Processes inbound message payloads from Meta webhooks into local DB rows.
 *
 * Lives in its own service so the {@see CloudWebhookController} stays focused
 * on routing + signature validation. The webhook handler hands the parsed
 * `messages[]` array straight to {@see processMessages()}.
 *
 * Responsibilities:
 *   - Find or create the Contact (by phone, scoped to instance owner)
 *   - Find or create the Conversation (per-contact + per-instance grouping)
 *   - Download media synchronously (Meta's signed URLs expire in ~5 min)
 *   - Insert one ConversationMessage row per inbound message
 *   - Update conversation timestamps + unread_count
 *
 * Idempotent: dedupes on whatsapp_message_id so a webhook retry doesn't
 * create duplicate rows.
 */
class InboundMessageProcessor
{
    public function __construct(
        private readonly WhatsAppCloudApiService $cloudApi,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages   from value.messages[]
     * @param  array<string, mixed>  $contactsBlock  from value.contacts[] (gives us the name)
     */
    public function processMessages(
        WhatsAppInstance $instance,
        array $messages,
        array $contactsBlock = [],
    ): void {
        // Build a phone → name map from the contacts block so we can populate
        // contact names on first sight (Meta sends the WhatsApp profile name).
        $nameByPhone = [];
        foreach ($contactsBlock as $c) {
            $waId = (string) ($c['wa_id'] ?? '');
            $name = (string) ($c['profile']['name'] ?? '');
            if ($waId !== '' && $name !== '') {
                $nameByPhone[$waId] = $name;
            }
        }

        foreach ($messages as $message) {
            try {
                $this->processOne($instance, $message, $nameByPhone);
            } catch (Throwable $e) {
                // Catch per-message so one bad payload doesn't drop the rest.
                Log::error('Inbound message processing failed', [
                    'instance_id' => $instance->id,
                    'wamid' => $message['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, string>  $nameByPhone
     */
    private function processOne(WhatsAppInstance $instance, array $message, array $nameByPhone): void
    {
        $wamid = (string) ($message['id'] ?? '');
        if ($wamid === '') {
            return;
        }

        // Idempotency: skip if we've already stored this exact message.
        if (ConversationMessage::where('whatsapp_message_id', $wamid)->exists()) {
            return;
        }

        $fromPhone = (string) ($message['from'] ?? '');
        if ($fromPhone === '') {
            return;
        }

        $contact = $this->findOrCreateContact($instance, $fromPhone, $nameByPhone[$fromPhone] ?? null);
        $conversation = $this->findOrCreateConversation($instance, $contact);
        $type = (string) ($message['type'] ?? 'unknown');
        $receivedAt = isset($message['timestamp'])
            ? Carbon::createFromTimestamp((int) $message['timestamp'])
            : Carbon::now();

        // Extract body text for whichever message type came in.
        // Each Cloud API message type puts the text in a different sub-key.
        $body = $this->extractBody($message, $type);

        // Download media if present, store locally, capture path/mime/size.
        $media = $this->downloadMediaForMessage($instance, $message, $type);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => ConversationMessage::DIRECTION_INBOUND,
            'whatsapp_message_id' => $wamid,
            'type' => $type,
            'body' => $body,
            'media_path' => $media['path'] ?? null,
            'media_mime' => $media['mime'] ?? null,
            'media_size_bytes' => $media['size'] ?? null,
            'received_at' => $receivedAt,
        ]);

        // Update denormalized inbox indicators.
        $conversation->update([
            'last_message_at' => $receivedAt,
            'last_inbound_at' => $receivedAt,
            'unread_count' => $conversation->unread_count + 1,
        ]);
    }

    private function findOrCreateContact(
        WhatsAppInstance $instance,
        string $phone,
        ?string $whatsappProfileName,
    ): Contact {
        // user_id of the contact = owner of the instance. Each customer's
        // contacts are siloed by user_id throughout the rest of the app.
        return Contact::firstOrCreate(
            ['user_id' => $instance->user_id, 'phone' => $phone],
            ['name' => $whatsappProfileName ?? $phone, 'is_active' => true],
        );
    }

    private function findOrCreateConversation(WhatsAppInstance $instance, Contact $contact): Conversation
    {
        return Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'whatsapp_instance_id' => $instance->id],
            ['user_id' => $instance->user_id, 'unread_count' => 0],
        );
    }

    /**
     * Extract the displayable body for any message type Meta might send.
     *
     * @param  array<string, mixed>  $message
     */
    private function extractBody(array $message, string $type): ?string
    {
        return match ($type) {
            'text' => (string) ($message['text']['body'] ?? ''),
            'image', 'video', 'document', 'audio' => (string) ($message[$type]['caption'] ?? ''),
            'location' => trim(sprintf(
                '%s, %s — %s',
                $message['location']['latitude'] ?? '',
                $message['location']['longitude'] ?? '',
                $message['location']['name'] ?? '',
            ), ', — '),
            'contacts' => 'Shared contact: '.($message['contacts'][0]['name']['formatted_name'] ?? 'unknown'),
            'sticker' => '[sticker]',
            'interactive' => (string) (
                $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? ''
            ),
            default => null,
        };
    }

    /**
     * If the message has media, fetch its URL, download bytes, save to storage.
     *
     * @param  array<string, mixed>  $message
     * @return array{path: string, mime: string, size: int}|array{}
     */
    private function downloadMediaForMessage(
        WhatsAppInstance $instance,
        array $message,
        string $type,
    ): array {
        if (! in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true)) {
            return [];
        }

        $mediaId = (string) ($message[$type]['id'] ?? '');
        if ($mediaId === '') {
            return [];
        }

        try {
            $info = $this->cloudApi->getMediaUrl($instance, $mediaId);
            $bytes = $this->cloudApi->downloadMediaContent($instance, (string) $info['url']);
        } catch (Throwable $e) {
            Log::warning('Inbound media download failed', [
                'instance_id' => $instance->id,
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        // Store under storage/app/conversations/<instance_id>/ so it's outside
        // public/ (private; not directly fetchable). The controller serves it
        // via a permission-checked download route in Phase 13.
        $extension = $this->extensionForMime((string) ($info['mime_type'] ?? ''));
        $filename = sprintf('%s/%s.%s', $instance->id, Str::uuid(), $extension);
        Storage::put('conversations/'.$filename, $bytes);

        return [
            'path' => 'conversations/'.$filename,
            'mime' => (string) ($info['mime_type'] ?? 'application/octet-stream'),
            'size' => strlen($bytes),
        ];
    }

    private function extensionForMime(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/jpeg') => 'jpg',
            str_starts_with($mime, 'image/png') => 'png',
            str_starts_with($mime, 'image/webp') => 'webp',
            str_starts_with($mime, 'image/gif') => 'gif',
            str_starts_with($mime, 'video/mp4') => 'mp4',
            str_starts_with($mime, 'video/3gpp') => '3gp',
            str_starts_with($mime, 'audio/ogg') => 'ogg',
            str_starts_with($mime, 'audio/mpeg') => 'mp3',
            str_starts_with($mime, 'audio/amr') => 'amr',
            str_starts_with($mime, 'audio/aac') => 'aac',
            str_starts_with($mime, 'application/pdf') => 'pdf',
            default => 'bin',
        };
    }
}

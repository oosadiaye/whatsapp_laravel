<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MessageLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class WebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $secret = Setting::get('webhook_secret');

        if ($secret && $request->header('X-Evolution-Secret') !== $secret) {
            return response('Unauthorized', 401);
        }

        $payload = $request->all();
        $event = $payload['event'] ?? '';

        $isStatusUpdate = in_array($event, ['messages.update', 'MESSAGES_UPDATE'], true);

        if (! $isStatusUpdate) {
            return response('OK', 200);
        }

        $whatsappMessageId = $payload['data']['key']['id']
            ?? $payload['key']['id']
            ?? null;

        $rawStatus = $payload['data']['status']
            ?? $payload['status']
            ?? null;

        if (! $whatsappMessageId || ! $rawStatus) {
            return response('OK', 200);
        }

        $mappedStatus = $this->mapStatus($rawStatus);

        $messageLog = MessageLog::where('whatsapp_message_id', $whatsappMessageId)->first();

        if (! $messageLog) {
            return response('OK', 200);
        }

        $updates = ['status' => $mappedStatus];

        match ($mappedStatus) {
            'DELIVERED' => $updates['delivered_at'] = Carbon::now(),
            'READ' => $updates['read_at'] = Carbon::now(),
            'SENT' => $updates['sent_at'] = $updates['sent_at'] ?? Carbon::now(),
            default => null,
        };

        $messageLog->update($updates);

        $campaign = $messageLog->campaign;

        if ($campaign) {
            match ($mappedStatus) {
                'DELIVERED' => $campaign->increment('delivered_count'),
                'READ' => $campaign->increment('read_count'),
                'FAILED' => $campaign->increment('failed_count'),
                default => null,
            };
        }

        return response('OK', 200);
    }

    private function mapStatus(string $rawStatus): string
    {
        return match (strtoupper($rawStatus)) {
            'DELIVERY_ACK' => 'DELIVERED',
            'READ' => 'READ',
            'SERVER_ACK' => 'SENT',
            'FAILED', 'ERROR' => 'FAILED',
            default => strtoupper($rawStatus),
        };
    }
}

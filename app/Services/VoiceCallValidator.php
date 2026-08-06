<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CallLog;

/**
 * Renders a structured PASS / WARN / FAIL report for a single call, used by the
 * `voice:watch` command (and available for tests) to validate a live
 * Africa's Talking call against the Phase 0 definition of done.
 *
 * Every check inspects only data the app already persists — call_logs columns,
 * the raw_event_log callback timeline, and the linked conversation — so the
 * report works without any live-account access: craft a CallLog and assert.
 */
class VoiceCallValidator
{
    /**
     * @return array<int, array{label: string, status: 'pass'|'warn'|'fail'|'info', detail: string}>
     */
    public function report(CallLog $call): array
    {
        $results = [];

        $results[] = $this->item(
            'Webhook callbacks received',
            filled($call->raw_event_log),
            filled($call->raw_event_log)
                ? count($call->raw_event_log).' callback(s): '.implode(', ', array_column($call->raw_event_log, 'event'))
                : 'No callbacks recorded — check the webhook URL, AT_VOICE_WEBHOOK_SECRET, and AT reachability.',
        );

        $results[] = $this->item(
            'Call reached a terminal state',
            in_array($call->status, CallLog::STATUSES_TERMINAL, true),
            'status = '.$call->status,
        );

        $results[] = $this->item(
            'Contact + conversation linked',
            $call->contact_id !== null && $call->conversation_id !== null,
            'contact #'.($call->contact_id ?? 'null').' / conversation #'.($call->conversation_id ?? 'null'),
        );

        if ($call->isInbound()) {
            $assignee = $call->conversation?->assigned_to_user_id;
            $results[] = $this->item(
                'Inbound auto-assigned to an agent',
                $assignee !== null,
                $assignee !== null
                    ? 'agent #'.$assignee
                    : 'No assignee — round-robin found no available (present, under-capacity) agent.',
            );
        } else {
            $results[] = $this->item(
                'Outbound placed by an agent',
                $call->placed_by_user_id !== null,
                $call->placed_by_user_id !== null
                    ? 'agent #'.$call->placed_by_user_id
                    : 'No placing agent on the call.',
            );
        }

        $results[] = $this->item(
            'Provider session id captured',
            filled($call->provider_session_id) || filled($call->meta_call_id),
            $call->provider_session_id ?? $call->meta_call_id ?? 'none',
        );

        $connected = $call->connected_at !== null;
        $results[] = $this->info(
            'Call connected',
            $connected
                ? 'connected at '.$call->connected_at?->toIso8601String()
                : 'never connected ('.$call->status.')',
        );

        if ($connected) {
            $results[] = $this->item(
                'Duration computed',
                $call->duration_seconds !== null,
                $call->duration_seconds !== null ? $call->duration_seconds.'s' : 'no duration recorded',
            );

            if ($call->provider === CallLog::PROVIDER_AFRICAS_TALKING) {
                $results[] = $this->item(
                    'Cost estimate recorded',
                    $call->cost_estimate_kobo !== null,
                    $call->cost_estimate_kobo !== null ? $call->cost_estimate_kobo.' kobo' : 'no cost estimate — confirm AT\'s Completed callback includes amount/currency',
                );
            }
        }

        if ($call->isInbound() && $connected) {
            $results[] = $this->item(
                'Call answered in-browser',
                filled($call->answered_by_session_id),
                filled($call->answered_by_session_id)
                    ? 'session '.$call->answered_by_session_id
                    : 'not claimed/answered by an agent in the browser',
            );
        }

        if ($call->status === CallLog::STATUS_FAILED) {
            $results[] = $this->item(
                'Failure reason captured',
                filled($call->failure_reason),
                $call->failure_reason ?? 'none',
            );
        }

        return $results;
    }

    /**
     * A PASS/FAIL check. PASS when $ok, FAIL otherwise (the detail explains why).
     *
     * @return array{label: string, status: 'pass'|'fail', detail: string}
     */
    private function item(string $label, bool $ok, string $detail): array
    {
        return [
            'label' => $label,
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $detail,
        ];
    }

    /**
     * @return array{label: string, status: 'info', detail: string}
     */
    private function info(string $label, string $detail): array
    {
        return [
            'label' => $label,
            'status' => 'info',
            'detail' => $detail,
        ];
    }
}

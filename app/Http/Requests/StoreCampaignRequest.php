<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\MessageTemplate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'instance_id' => ['nullable', 'exists:whatsapp_instances,id'],
            'message_template_id' => ['nullable', 'exists:message_templates,id'],
            'template_language' => ['nullable', 'string', 'max:16'],
            // Single file uploader (replaces the old `header_media_url` URL field).
            // Stored on the public disk by the controller; the resulting URL is
            // written to campaigns.header_media_url so SendWhatsAppMessage can
            // pass it to Meta as the template-header parameter.
            'header_media' => ['nullable', 'file', 'max:16384', 'mimes:jpg,jpeg,png,gif,mp4,mov,pdf'],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['exists:contact_groups,id'],
            'rate_per_minute' => ['integer', 'min:1', 'max:60'],
            'delay_min' => ['integer', 'min:1', 'max:30'],
            'delay_max' => ['integer', 'min:1', 'max:60'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'status' => ['nullable', 'in:DRAFT,QUEUED'],
        ];
    }

    /**
     * Cross-field validation that can't live in the rules array:
     *
     *   - If a template was picked, it must be APPROVED (PENDING/REJECTED would
     *     fail at Meta's side anyway, so block at the form).
     *   - If no template is picked, warn loudly that the send will only work
     *     for contacts already inside a 24-hour conversation window.
     *     We don't BLOCK no-template campaigns because freeform sends are
     *     legitimate for service-conversation replies — but the user should
     *     know what they're signing up for.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $templateId = $this->input('message_template_id');

            if ($templateId === null || $templateId === '') {
                return;  // No template — handled by view warning, not error.
            }

            $template = MessageTemplate::where('user_id', auth()->id())
                ->where('id', $templateId)
                ->first();

            if ($template === null) {
                return;  // exists:message_templates rule will handle it
            }

            if ($template->isRemote() && $template->status !== MessageTemplate::STATUS_APPROVED) {
                $v->errors()->add(
                    'message_template_id',
                    "Template \"{$template->name}\" has status {$template->status} — only APPROVED templates can be sent. Sync the template list to refresh status, or pick a different template."
                );
                return;
            }

            // Pre-flight: if the template has a media-format header (IMAGE / VIDEO / DOCUMENT),
            // a header_media file is REQUIRED — otherwise Meta returns error 132012
            // ("Format mismatch, expected IMAGE, received UNKNOWN") and the entire
            // campaign fails silently in the queue.
            //
            // On UPDATE we also accept a previously-stored header URL on the campaign
            // being edited (route-bound), so users editing a campaign don't have to
            // re-upload the same file just to change unrelated fields.
            $headerFormat = $this->extractHeaderMediaFormat($template);
            $hasFile = $this->hasFile('header_media');
            $existingUrl = $this->route('campaign')?->header_media_url;

            if ($headerFormat !== null && ! $hasFile && empty($existingUrl)) {
                $v->errors()->add(
                    'header_media',
                    "Template \"{$template->name}\" has a {$headerFormat} header — upload a {$headerFormat} file under the message box."
                );
            }
        });
    }

    /**
     * Inspect a template's components to find a media-format header.
     *
     * Returns 'IMAGE', 'VIDEO', or 'DOCUMENT' if the template requires media,
     * or null for text headers / no header at all.
     */
    private function extractHeaderMediaFormat(MessageTemplate $template): ?string
    {
        $components = $template->components ?? [];
        if (! is_array($components)) {
            return null;
        }

        foreach ($components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));
            if ($type !== 'HEADER') {
                continue;
            }
            $format = strtoupper((string) ($component['format'] ?? 'TEXT'));
            if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                return $format;
            }
        }

        return null;
    }
}

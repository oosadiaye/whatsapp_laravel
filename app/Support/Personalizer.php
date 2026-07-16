<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Contact;

/**
 * The one place message personalization resolves a contact field to a value —
 * used by both the WhatsApp *template* path (positional {{1}}, {{2}} …) and the
 * *freeform* path (named {{name}}, {{phone}} …). The positional order comes from
 * config/personalization.php, so the two paths can never drift again.
 */
class Personalizer
{
    /**
     * Resolve a field key to a value for a contact. Single source of truth.
     */
    public function field(Contact $contact, string $field): string
    {
        $custom = $contact->custom_fields ?? [];

        return match ($field) {
            'name' => (string) ($contact->name ?? ''),
            'display_name' => (string) $contact->display_name, // name, else phone
            'phone' => (string) ($contact->phone ?? ''),
            'email' => (string) ($contact->email ?? ''),
            'first_name' => $this->firstName((string) ($contact->name ?? '')),
            'custom_field_1' => (string) ($custom['custom_field_1'] ?? ''),
            'custom_field_2' => (string) ($custom['custom_field_2'] ?? ''),
            default => '',
        };
    }

    /**
     * A positional WhatsApp template variable ({{1}}, {{2}} …) → value, using the
     * config-defined order. Unknown positions resolve to an empty string.
     */
    public function positional(Contact $contact, int $position): string
    {
        $field = config("personalization.template_variables.{$position}");

        return is_string($field) ? $this->field($contact, $field) : '';
    }

    /**
     * Replace named {{tokens}} in a freeform message body. {{name}} falls back to
     * "Friend" when the contact has no name (preserves the long-standing
     * behaviour); every other token resolves via {@see field()}.
     */
    public function named(Contact $contact, string $template, string $campaignName = ''): string
    {
        $name = $this->field($contact, 'name');

        $replacements = [
            '{{name}}' => $name !== '' ? $name : 'Friend',
            '{{phone}}' => $this->field($contact, 'phone'),
            '{{email}}' => $this->field($contact, 'email'),
            '{{first_name}}' => $this->field($contact, 'first_name'),
            '{{custom_field_1}}' => $this->field($contact, 'custom_field_1'),
            '{{custom_field_2}}' => $this->field($contact, 'custom_field_2'),
            '{{date}}' => now()->format('d F Y'),
            '{{campaign_name}}' => $campaignName,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    private function firstName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName));

        return $parts[0] ?? '';
    }
}

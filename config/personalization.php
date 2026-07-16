<?php

declare(strict_types=1);

/**
 * Single source of truth for message personalization, shared by the WhatsApp
 * template path (positional {{1}}, {{2}} … params) and the freeform-message
 * path (named {{name}}, {{phone}} … tokens) via App\Support\Personalizer.
 *
 * Previously the positional order was hardcoded in SendWhatsAppMessage and the
 * named tokens in ContactImportService, with no shared field resolver — so the
 * two could (and did) drift. This maps each numbered template variable to a
 * contact field key; the resolver turns a key into a value in one place.
 *
 * Field keys: name | display_name (name, else phone) | phone | email |
 *             first_name | custom_field_1 | custom_field_2
 */
return [
    'template_variables' => [
        1 => 'display_name',   // preserves the original {{1}} = name-or-phone
        2 => 'phone',
        3 => 'first_name',
        4 => 'custom_field_1',
    ],
];

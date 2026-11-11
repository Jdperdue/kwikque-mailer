<?php

declare(strict_types=1);

namespace App\Support\Audience;

final class EmailAddress
{
    /** Trim, lowercase, validate. Returns null when the address is not usable. */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Strip BOM / zero-width / non-breaking spaces that spreadsheets leave behind.
        $email = preg_replace('/^[\s\x{FEFF}\x{200B}\x{00A0}]+|[\s\x{FEFF}\x{200B}\x{00A0}]+$/u', '', $raw) ?? '';
        $email = mb_strtolower($email);

        if ($email === '' || strlen($email) > 254) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}

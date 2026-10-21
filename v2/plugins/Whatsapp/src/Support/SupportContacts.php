<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\Support;

/**
 * The team contacts from the secret config's "support" block, as the lines
 * shoppers get whenever something goes wrong or they ask for help:
 *
 *   "support": { "ruvenss": "https://wa.me/ruvenss", "email": "info@orderlemon.com" }
 *
 *   Ruvenss: https://wa.me/ruvenss
 *   Email: info@orderlemon.com
 *
 * The key is the name shown, with a capital letter; a plain list (no names)
 * gives the values alone.
 */
final class SupportContacts
{
    /** @return list<string> one "Name: contact" line per entry */
    public static function lines(mixed $support): array
    {
        $lines = [];
        foreach ((array) $support as $name => $contact) {
            $contact = trim((string) (is_scalar($contact) ? $contact : ''));
            if ($contact === '') {
                continue;
            }
            $lines[] = is_string($name) && trim($name) !== '' ? ucfirst(trim($name)) . ': ' . $contact : $contact;
        }

        return $lines;
    }

    public static function text(mixed $support): string
    {
        return implode("\n", self::lines($support));
    }
}

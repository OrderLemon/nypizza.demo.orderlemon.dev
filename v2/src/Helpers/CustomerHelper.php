<?php

namespace Pmsrapi\V2\Helpers;

class CustomerHelper
{

    /**
     * The gateway sends bare digits; mockups may carry +, spaces or dashes, and
     * a Dutch number may or may not have a trunk zero. Compare on digits only.
     */
    public static function samePhone(string $a, string $b): bool
    {
        $norm = static function (string $p): string {
            $digits = preg_replace('/\D+/', '', $p) ?? '';

            return ltrim($digits, '0');
        };

        $left = $norm($a);

        return $left !== '' && $left === $norm($b);
    }

    /**
     * Last-resort phone lookup from the conversation envelope. Prefer passing it
     * into reply() from the gateway payload — that is the value the tools trust,
     * and a file name is easier to get wrong than a webhook field.
     */
    public static function phoneOf(array $conversation): string
    {
        foreach ([
            $conversation['phone'] ?? null,
            $conversation['data']['phone'] ?? null,
            $conversation['data']['client_phone'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }
}

?>
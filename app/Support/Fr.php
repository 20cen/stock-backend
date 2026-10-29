<?php

namespace App\Support;

/**
 * Mise en forme française des nombres pour les documents imprimés (sans devise, à valider avec le client).
 */
final class Fr
{
    /** « 125000.00 » → « 125 000 » ; « 1250.5 » → « 1 250,50 ». */
    public static function money(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $v = (float) $value;

        return number_format($v, floor($v) == $v ? 0 : 2, ',', "\u{202F}");
    }

    /** « 3.500 » → « 3,5 » ; « 20.000 » → « 20 ». */
    public static function quantity(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $value, 3, ',', "\u{202F}"), '0'), ',');
    }
}

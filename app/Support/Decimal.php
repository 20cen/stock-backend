<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Calcul décimal exact (bcmath) pour les quantités et les coûts : jamais de flottant.
 */
final class Decimal
{
    public const QUANTITY_SCALE = 3;

    public const COST_SCALE = 4;

    /**
     * Valide et normalise une valeur (« 2 », « 2.5 », 2) en chaîne à `$scale` décimales.
     *
     * @throws InvalidArgumentException si la valeur n'est pas un nombre ou a trop de décimales
     */
    public static function normalize(int|string $value, int $scale): string
    {
        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(\.(\d+))?$/', $value, $m) || strlen($m[2] ?? '') > $scale) {
            throw new InvalidArgumentException("Nombre invalide ou avec plus de {$scale} décimales : {$value}");
        }

        return bcadd($value, '0', $scale);
    }

    /**
     * Arrondi au plus proche (moitié vers l'extérieur de zéro).
     */
    public static function round(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', $scale + 10) >= 0
            ? bcadd($value, $half, $scale)
            : bcsub($value, $half, $scale);
    }

    public static function isZero(string $value): bool
    {
        return bccomp($value, '0', 10) === 0;
    }

    public static function isInteger(string $value): bool
    {
        return bccomp($value, bcadd($value, '0', 0), 10) === 0;
    }

    public static function abs(string $value): string
    {
        return ltrim($value, '-');
    }

    /**
     * Supprime les zéros inutiles pour l'affichage : « 2.500 » → « 2.5 », « 3.000 » → « 3 ».
     */
    public static function trim(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}

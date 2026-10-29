<?php

namespace App\Enums;

/**
 * Motifs de perte (cahier des charges §4.8). Les écarts d'inventaire passent par les inventaires.
 */
enum LossReason: string
{
    case Breakage = 'breakage';
    case Deterioration = 'deterioration';
    case Breakdown = 'breakdown';
    case Disappearance = 'disappearance';
    case Theft = 'theft';
    case Unusable = 'unusable';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Breakage => 'Casse',
            self::Deterioration => 'Détérioration',
            self::Breakdown => 'Panne',
            self::Disappearance => 'Disparition',
            self::Theft => 'Vol présumé ou constaté',
            self::Unusable => 'Produit inutilisable',
            self::Other => 'Autre motif',
        };
    }
}

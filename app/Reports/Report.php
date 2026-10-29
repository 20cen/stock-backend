<?php

namespace App\Reports;

/**
 * Résultat d'un rapport, indépendant du format de sortie (écran, Excel, PDF).
 *
 * Types de colonne : text, date (AAAA-MM-JJ), int, money (2 décimales), quantity (3 décimales), percent (fraction).
 * Les valeurs numériques sont des chaînes décimales exactes ; les totaux portent les mêmes clés que les colonnes.
 */
final class Report
{
    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $totals = [],
        public readonly array $notes = [],
        public readonly string $scope = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'scope' => $this->scope,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'totals' => $this->totals ?: null,
            'notes' => $this->notes,
        ];
    }
}

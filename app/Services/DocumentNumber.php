<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

/**
 * Numéros de documents par préfixe et par année : ACH-2026-00001, REC-2026-00001…
 *
 * À appeler dans la transaction qui crée le document : le compteur est verrouillé jusqu'à la validation,
 * et un échec annule aussi l'incrément. La numérotation reste donc continue, sans doublon ni trou.
 */
class DocumentNumber
{
    public function next(string $prefix, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return DB::transaction(function () use ($prefix, $year) {
            DocumentSequence::insertOrIgnore([
                'prefix' => $prefix, 'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $sequence = DocumentSequence::where('prefix', $prefix)->where('year', $year)->lockForUpdate()->sole();
            $sequence->increment('last_number');

            return sprintf('%s-%d-%05d', $prefix, $year, $sequence->last_number);
        });
    }
}

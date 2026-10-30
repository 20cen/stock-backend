<?php

namespace App\Support;

use App\Models\Setting;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Rendu PDF (Dompdf) d'une vue Blade : A4 avec pagination en pied de page, ou ticket de caisse ;
 * police DejaVu Sans (accents).
 * Les vues reçoivent `$shop` (nom, lignes d'en-tête), `$brand` (logo en data URI, couleurs) et `$editedAt`.
 */
class PdfRenderer
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array{0: float, 1: float}|null  $ticket  largeur et hauteur en points d'un ticket (pas de pagination)
     */
    public function render(string $view, array $data, string $orientation = 'portrait', ?array $ticket = null): string
    {
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setFontCache(storage_path('framework/cache'));
        $options->setTempDir(sys_get_temp_dir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data + [
            'shop' => Setting::shop(),
            // Identité visuelle : logo (data URI) et couleurs réglés dans Paramètres.
            'brand' => [
                'logo' => Setting::logoDataUri(),
                'primary' => Setting::get(Setting::BRAND_PRIMARY),
                'dark' => Setting::get(Setting::BRAND_DARK),
            ],
            'editedAt' => now()->format('d/m/Y à H:i'),
        ])->render());
        $dompdf->setPaper($ticket ? [0, 0, $ticket[0], $ticket[1]] : 'A4', $ticket ? 'portrait' : $orientation);
        $dompdf->render();
        if ($ticket) {
            return (string) $dompdf->output();
        }

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 23, 'Page {PAGE_NUM} / {PAGE_COUNT}', $font, 7, [0.39, 0.45, 0.55]);

        return (string) $dompdf->output();
    }
}

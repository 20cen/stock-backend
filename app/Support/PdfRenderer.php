<?php

namespace App\Support;

use App\Models\Setting;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Rendu PDF (Dompdf) d'une vue Blade : A4, police DejaVu Sans (accents), pagination en pied de page.
 * Les vues reçoivent `$shop` (nom, lignes d'en-tête) et `$editedAt`.
 */
class PdfRenderer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data, string $orientation = 'portrait'): string
    {
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setFontCache(storage_path('framework/cache'));
        $options->setTempDir(sys_get_temp_dir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data + [
            'shop' => Setting::shop(),
            'editedAt' => now()->format('d/m/Y à H:i'),
        ])->render());
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 23, 'Page {PAGE_NUM} / {PAGE_COUNT}', $font, 7, [0.39, 0.45, 0.55]);

        return (string) $dompdf->output();
    }
}

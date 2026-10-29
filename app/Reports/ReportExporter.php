<?php

namespace App\Reports;

use App\Models\Setting;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Exports Excel (.xlsx) et PDF d'un rapport. Les deux formats reprennent la même structure :
 * en-tête (boutique, titre, période et filtres, date d'édition), tableau typé, ligne de totaux, notes.
 */
class ReportExporter
{
    private const EXCEL_FORMATS = [
        'money' => '#,##0.00',
        'int' => '#,##0',
        'percent' => '0.0%',
        'date' => 'dd/mm/yyyy',
    ];

    public function filename(Report $report, string $extension): string
    {
        return 'rapport-'.str_replace('_', '-', $report->key).'-'.now()->format('Ymd-Hi').'.'.$extension;
    }

    public function xlsx(Report $report): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator($this->shopName())->setTitle($report->title);
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $report->title), 0, 31));
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $count = count($report->columns);
        $last = Coordinate::stringFromColumnIndex($count);

        $sheet->setCellValue('A1', $this->shopName().' — '.$report->title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $report->scope);
        $sheet->setCellValue('A3', 'Édité le '.now()->format('d/m/Y à H:i'));
        $sheet->getStyle('A2:A3')->getFont()->getColor()->setRGB('64748B');

        $header = 5;
        foreach ($report->columns as $i => $column) {
            $sheet->setCellValue([$i + 1, $header], $column['label']);
        }
        $headerRange = "A{$header}:{$last}{$header}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E293B');

        $row = $header + 1;
        foreach ($report->rows as $line) {
            $this->writeRow($sheet, $report->columns, $line, $row);
            $kind = $line['_kind'] ?? 'line';
            if ($kind === 'subtotal' || $kind === 'total') {
                $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
                $sheet->getStyle("A{$row}:{$last}{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            }
            if ($kind === 'total') {
                $sheet->getStyle("A{$row}:{$last}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
            }
            if ($kind === 'info') {
                $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setItalic(true)->getColor()->setRGB('64748B');
            }
            $row++;
        }

        if ($report->rows === []) {
            $sheet->setCellValue("A{$row}", 'Aucune donnée sur la période.');
            $row++;
        } elseif ($report->totals) {
            $this->writeRow($sheet, $report->columns, $report->totals + [$report->columns[0]['key'] => 'Total'], $row);
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:{$last}{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);
            $row++;
        }

        foreach ($report->notes as $note) {
            $row++;
            $sheet->setCellValue("A{$row}", $note);
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('64748B');
        }

        foreach ($report->columns as $i => $column) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($letter)->setAutoSize(true);
            if (! in_array($column['type'], ['text', 'date'])) {
                $sheet->getStyle("{$letter}{$header}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }
        // Lignes de titre et notes fusionnées : l'ajustement automatique des largeurs les ignore.
        foreach ([1, 2, 3] as $titleRow) {
            $sheet->mergeCells("A{$titleRow}:{$last}{$titleRow}");
        }
        for ($noteRow = $row - count($report->notes) + 1; $noteRow <= $row && $report->notes; $noteRow++) {
            $sheet->mergeCells("A{$noteRow}:{$last}{$noteRow}");
        }
        $sheet->freezePane('A'.($header + 1));
        $sheet->getPageSetup()->setOrientation($count > 6 ? 'landscape' : 'portrait')->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($header, $header);

        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    public function pdf(Report $report): string
    {
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setFontCache(storage_path('framework/cache'));
        $options->setTempDir(sys_get_temp_dir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('reports.pdf', [
            'report' => $report,
            'shop' => $this->shopName(),
            'cell' => fn (array $column, mixed $value) => $this->display($column['type'], $value),
            'editedAt' => now()->format('d/m/Y à H:i'),
        ])->render());
        $dompdf->setPaper('A4', count($report->columns) > 6 ? 'landscape' : 'portrait');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 23, 'Page {PAGE_NUM} / {PAGE_COUNT}', $font, 7, [0.39, 0.45, 0.55]);

        return (string) $dompdf->output();
    }

    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  array<string, mixed>  $line
     */
    private function writeRow(Worksheet $sheet, array $columns, array $line, int $row): void
    {
        $quantityFormat = '#,##0.###';
        foreach ($columns as $i => $column) {
            $value = $line[$column['key']] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $coordinate = [$i + 1, $row];
            switch ($column['type']) {
                case 'text':
                    $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                    break;
                case 'date':
                    $sheet->setCellValue($coordinate, is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? ExcelDate::stringToExcel($value) : $value);
                    break;
                default:
                    $sheet->setCellValue($coordinate, is_numeric($value) ? (float) $value : $value);
            }
            $format = $column['type'] === 'quantity'
                ? (is_numeric($value) && floor((float) $value) == (float) $value ? '#,##0' : $quantityFormat)
                : (self::EXCEL_FORMATS[$column['type']] ?? null);
            if ($format) {
                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($format);
            }
        }
    }

    /**
     * Valeur formatée à la française pour le PDF.
     */
    private function display(string $type, mixed $value): string
    {
        if ($value === null || $value === '') {
            return $type === 'text' ? '' : '—';
        }
        $number = fn (float $v, int $decimals) => number_format($v, $decimals, ',', "\u{202F}");

        return match ($type) {
            'money' => $number((float) $value, 2),
            'int' => $number((float) $value, 0),
            'quantity' => rtrim(rtrim($number((float) $value, 3), '0'), ','),
            'percent' => $number((float) $value * 100, 1).' %',
            'date' => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : (string) $value,
            default => (string) $value,
        };
    }

    private function shopName(): string
    {
        return (string) Setting::get(Setting::SHOP_NAME);
    }
}

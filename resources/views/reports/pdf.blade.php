<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $report->title }}</title>
<style>
    @page { margin: 34px 32px 44px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #0f172a; }
    .head { border-bottom: 2px solid #1e293b; padding-bottom: 8px; margin-bottom: 12px; }
    .shop { font-size: 9px; font-weight: bold; letter-spacing: 1.5px; color: #c2410c; text-transform: uppercase; }
    h1 { font-size: 15px; margin: 3px 0 4px; }
    .meta { color: #64748b; font-size: 8px; }
    .meta span { margin-right: 14px; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    th { background: #1e293b; color: #fff; font-weight: bold; text-align: left; padding: 5px 6px; font-size: 8px; }
    td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    tr:nth-child(even) td { background: #f8fafc; }
    .num { text-align: right; white-space: nowrap; }
    tr.subtotal td { font-weight: bold; border-top: 1px solid #94a3b8; }
    tr.total td { font-weight: bold; background: #e2e8f0; border-top: 1px solid #475569; font-size: 9.5px; }
    tr.info td { color: #64748b; font-style: italic; }
    tfoot td { font-weight: bold; border-top: 1.5px solid #1e293b; border-bottom: none; background: #f1f5f9; }
    .empty { padding: 18px; text-align: center; color: #64748b; }
    .notes { margin-top: 12px; color: #64748b; font-size: 7.5px; }
    .notes p { margin: 2px 0; }
    .footer { position: fixed; bottom: -28px; left: 0; color: #94a3b8; font-size: 7px; }
</style>
</head>
<body>
    <div class="footer">{{ $shop }} · {{ $report->title }} · édité le {{ $editedAt }}</div>

    <div class="head">
        <div class="shop">{{ $shop }}</div>
        <h1>{{ $report->title }}</h1>
        <div class="meta"><span>{{ $report->scope }}</span><span>Édité le {{ $editedAt }}</span></div>
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($report->columns as $column)
                    <th @class(['num' => ! in_array($column['type'], ['text', 'date'])])>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($report->rows as $row)
                <tr class="{{ $row['_kind'] ?? '' }}">
                    @foreach ($report->columns as $column)
                        <td @class(['num' => ! in_array($column['type'], ['text', 'date'])])>{{ $cell($column, $row[$column['key']] ?? null) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="empty" colspan="{{ count($report->columns) }}">Aucune donnée sur la période.</td></tr>
            @endforelse
        </tbody>
        @if ($report->rows && $report->totals)
            <tfoot>
                <tr>
                    @foreach ($report->columns as $i => $column)
                        @if ($i === 0)
                            <td>Total ({{ count($report->rows) }} ligne{{ count($report->rows) > 1 ? 's' : '' }})</td>
                        @else
                            <td @class(['num' => ! in_array($column['type'], ['text', 'date'])])>{{ array_key_exists($column['key'], $report->totals) ? $cell($column, $report->totals[$column['key']]) : '' }}</td>
                        @endif
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    @if ($report->notes)
        <div class="notes">
            @foreach ($report->notes as $note)
                <p>{{ $note }}</p>
            @endforeach
        </div>
    @endif
</body>
</html>

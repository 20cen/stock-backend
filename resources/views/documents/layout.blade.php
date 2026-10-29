<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>@yield('title')</title>
<style>
    @page { margin: 34px 36px 46px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #0f172a; }
    .head { width: 100%; border-bottom: 2px solid #1e293b; padding-bottom: 10px; margin-bottom: 16px; }
    .head td { vertical-align: top; padding: 0; border: none; }
    .shop { font-size: 14px; font-weight: bold; color: #1e293b; }
    .shop-lines { color: #475569; font-size: 8.5px; margin-top: 2px; }
    .doc { text-align: right; }
    .doc-title { font-size: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #c2410c; }
    .doc-ref { font-size: 11px; font-weight: bold; margin-top: 3px; }
    .doc-date { color: #64748b; font-size: 8.5px; margin-top: 2px; }
    .boxes { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 14px; }
    .boxes td { width: 50%; vertical-align: top; border: 1px solid #e2e8f0; border-radius: 4px; padding: 8px 10px; }
    .boxes td + td { border-left: none; }
    .box-title { font-size: 7.5px; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
    .box-main { font-size: 10.5px; font-weight: bold; }
    .muted { color: #64748b; }
    table.lines { width: 100%; border-collapse: collapse; }
    table.lines th { background: #1e293b; color: #fff; font-weight: bold; text-align: left; padding: 5px 6px; font-size: 8px; }
    table.lines td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; }
    .num { text-align: right; white-space: nowrap; }
    .totals { width: 45%; margin-left: 55%; margin-top: 12px; border-collapse: collapse; }
    .totals td { padding: 4px 6px; }
    .totals tr.strong td { font-weight: bold; font-size: 11px; border-top: 1.5px solid #1e293b; }
    .amount { font-size: 22px; font-weight: bold; text-align: center; border: 2px solid #1e293b; border-radius: 6px; padding: 12px; margin: 6px 0 14px; }
    .notes { margin-top: 12px; color: #475569; }
    .signatures { width: 100%; margin-top: 34px; border-collapse: collapse; }
    .signatures td { width: 50%; vertical-align: top; padding: 0 10px 0 0; }
    .signatures .line { border-top: 1px solid #94a3b8; margin-top: 46px; padding-top: 4px; color: #64748b; font-size: 8px; }
    .footer { position: fixed; bottom: -30px; left: 0; color: #94a3b8; font-size: 7px; }
</style>
</head>
<body>
    <div class="footer">{{ $shop['name'] }} · @yield('title') · édité le {{ $editedAt }}</div>

    <table class="head">
        <tr>
            <td>
                <div class="shop">{{ $shop['name'] }}</div>
                <div class="shop-lines">
                    @foreach ($shop['lines'] as $line)
                        {{ $line }}<br>
                    @endforeach
                </div>
            </td>
            <td class="doc">
                <div class="doc-title">@yield('doc-title')</div>
                <div class="doc-ref">@yield('doc-ref')</div>
                <div class="doc-date">@yield('doc-date')</div>
            </td>
        </tr>
    </table>

    @yield('content')
</body>
</html>

@php use App\Support\Fr; @endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Reçu {{ $sale->reference }}</title>
<style>
    /* Ticket 80 mm : même contenu que le reçu affiché à l'écran (SaleReceipt.vue). */
    @page { margin: 10px 12px; }
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: 8.5px; line-height: 1.35; color: #000; }
    p { margin: 0; }
    .center { text-align: center; }
    .shop { font-size: 11px; font-weight: bold; }
    .sep { border-top: 1px dashed #000; margin: 5px 0; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 0; vertical-align: top; }
    .r { text-align: right; white-space: nowrap; }
    .b { font-weight: bold; }
    .total td { font-size: 10px; font-weight: bold; }
    .line { margin-bottom: 3px; }
</style>
</head>
<body>
    <div class="center">
        @if ($brand['logo'])
            <img src="{{ $brand['logo'] }}" alt="" style="max-height: 40px; max-width: 120px; margin-bottom: 3px;">
        @endif
        <p class="shop">{{ $shop['name'] }}</p>
        @foreach ($shop['lines'] as $line)
            <p>{{ $line }}</p>
        @endforeach
    </div>
    <div class="sep"></div>
    @if ($sale->status === \App\Models\Sale::CANCELLED)
        <p class="center b">*** VENTE ANNULÉE ***</p>
    @endif
    <p>Reçu <b>{{ $sale->reference }}</b></p>
    <p>{{ $sale->sold_at->format('d/m/Y H:i') }} · {{ $sale->cashier->name }}</p>
    @if ($sale->customer)
        <p>Client : {{ $sale->customer->name }}</p>
    @endif
    <div class="sep"></div>

    @foreach ($sale->lines as $line)
        <div class="line">
            <p>{{ $line->article->designation }}</p>
            <table>
                <tr>
                    <td>{{ Fr::quantity($line->quantity) }} × {{ Fr::money($line->unit_price) }}</td>
                    <td class="r">{{ Fr::money($line->line_total) }}</td>
                </tr>
                @if (bccomp($line->discount, '0', 2) > 0)
                    <tr><td></td><td class="r">remise −{{ Fr::money($line->discount) }}</td></tr>
                @endif
            </table>
        </div>
    @endforeach

    <div class="sep"></div>
    <table>
        @if (bccomp($sale->discount_total, '0', 2) > 0)
            <tr><td>Remises</td><td class="r">−{{ Fr::money($sale->discount_total) }}</td></tr>
        @endif
        <tr class="total"><td>TOTAL</td><td class="r">{{ Fr::money($sale->total) }}</td></tr>
        @foreach ($sale->payments as $payment)
            <tr><td>{{ $payment->method->name }}</td><td class="r">{{ Fr::money($payment->amount) }}</td></tr>
        @endforeach
        @if (bccomp($sale->change_given, '0', 2) > 0)
            <tr><td>Monnaie rendue</td><td class="r">{{ Fr::money($sale->change_given) }}</td></tr>
        @endif
        @if (bccomp($sale->balance_due, '0', 2) > 0)
            <tr class="b"><td>Reste dû</td><td class="r">{{ Fr::money($sale->balance_due) }}</td></tr>
        @endif
    </table>
    <div class="sep"></div>
    @if ($shop['footer'])
        <p class="center">{{ $shop['footer'] }}</p>
    @endif
</body>
</html>

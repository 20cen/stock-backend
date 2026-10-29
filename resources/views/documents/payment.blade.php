@php use App\Support\Fr; @endphp
@extends('documents.layout')

@section('title', "Reçu de paiement {$number}")
@section('doc-title', 'Reçu de paiement')
@section('doc-ref', $number)
@section('doc-date', 'Payé le '.$payment->paid_at->format('d/m/Y à H:i'))

@section('content')
    <table class="boxes">
        <tr>
            <td>
                <div class="box-title">Payé à</div>
                <div class="box-main">{{ $purchase->supplier->name }}</div>
                @if ($purchase->supplier->phone)<div class="muted">Tél. {{ $purchase->supplier->phone }}</div>@endif
                @if ($purchase->supplier->address)<div class="muted">{{ $purchase->supplier->address }}</div>@endif
            </td>
            <td>
                <div class="box-title">Pour l'acquisition</div>
                <div class="box-main">{{ $purchase->reference }}</div>
                <div class="muted">du {{ $purchase->purchase_date->format('d/m/Y') }}@if ($purchase->supplier_reference) · réf. fournisseur {{ $purchase->supplier_reference }}@endif</div>
            </td>
        </tr>
    </table>

    <div class="amount">{{ Fr::money($payment->amount) }}</div>

    <table class="lines">
        <tbody>
            <tr><td>Mode de paiement</td><td class="num"><b>{{ $payment->method->name }}</b></td></tr>
            @if ($payment->reference)<tr><td>Référence (n° de transaction, de chèque…)</td><td class="num">{{ $payment->reference }}</td></tr>@endif
            <tr><td>Enregistré par</td><td class="num">{{ $payment->creator->name }}</td></tr>
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Total de l'acquisition</td><td class="num">{{ Fr::money($purchase->total) }}</td></tr>
        <tr><td>Payé avant ce paiement</td><td class="num">{{ Fr::money($paidBefore) }}</td></tr>
        <tr><td>Ce paiement</td><td class="num">{{ Fr::money($payment->amount) }}</td></tr>
        <tr class="strong"><td>Reste à payer</td><td class="num">{{ Fr::money($balanceAfter) }}</td></tr>
    </table>

    @if ($payment->notes)
        <p class="notes">Observations : {{ $payment->notes }}</p>
    @endif

    <table class="signatures">
        <tr>
            <td><div class="line">Pour {{ $shop['name'] }} (signature)</div></td>
            <td><div class="line">Reçu par le fournisseur (nom et signature)</div></td>
        </tr>
    </table>
@endsection

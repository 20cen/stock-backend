@php use App\Support\Fr; @endphp
@extends('documents.layout')

@section('title', "Bon de réception {$receipt->reference}")
@section('doc-title', 'Bon de réception')
@section('doc-ref', $receipt->reference)
@section('doc-date', 'Reçu le '.$receipt->received_at->format('d/m/Y à H:i'))

@section('content')
    <table class="boxes">
        <tr>
            <td>
                <div class="box-title">Fournisseur</div>
                <div class="box-main">{{ $purchase->supplier->name }}</div>
                @if ($purchase->supplier->phone)<div class="muted">Tél. {{ $purchase->supplier->phone }}</div>@endif
                @if ($purchase->supplier->address)<div class="muted">{{ $purchase->supplier->address }}</div>@endif
            </td>
            <td>
                <div class="box-title">Acquisition</div>
                <div class="box-main">{{ $purchase->reference }}</div>
                <div class="muted">du {{ $purchase->purchase_date->format('d/m/Y') }}@if ($purchase->supplier_reference) · réf. fournisseur {{ $purchase->supplier_reference }}@endif</div>
                <div class="muted">Entrée en stock : <b>{{ $receipt->location->name }}</b></div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Référence</th>
                <th>Article</th>
                <th class="num">Commandé</th>
                <th class="num">Reçu ce jour</th>
                <th class="num">Reçu au total</th>
                <th class="num">Reste à recevoir</th>
                <th>Unité</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['reference'] }}</td>
                    <td>{{ $line['designation'] }}</td>
                    <td class="num">{{ Fr::quantity($line['ordered']) }}</td>
                    <td class="num"><b>{{ Fr::quantity($line['received']) }}</b></td>
                    <td class="num">{{ Fr::quantity($line['cumulative']) }}</td>
                    <td class="num">{{ Fr::quantity($line['remaining']) }}</td>
                    <td>{{ $line['unit'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="notes">
        {{ count($lines) }} ligne(s) réceptionnée(s) par {{ $receipt->receiver->name }}.
        @if ($complete) Acquisition entièrement reçue. @else Livraison partielle : le reste est attendu. @endif
        @if ($receipt->notes)<br>Observations : {{ $receipt->notes }}@endif
    </p>

    <table class="signatures">
        <tr>
            <td><div class="line">Livré par (nom et signature)</div></td>
            <td><div class="line">Réceptionné par {{ $receipt->receiver->name }} (signature)</div></td>
        </tr>
    </table>
@endsection

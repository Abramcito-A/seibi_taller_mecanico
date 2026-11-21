@extends('layouts.public')
@section('title', 'Decisión registrada · '.$cotizacion->folio)
@section('subtitle', 'Decisión registrada')
@section('content')
    <div class="space-y-5">
        @if (session('portal_notice'))
            <x-ui.alert variant="success">{{ session('portal_notice') }}</x-ui.alert>
        @endif

        @include('portal.cotizaciones._resumen-decision')
    </div>
@endsection

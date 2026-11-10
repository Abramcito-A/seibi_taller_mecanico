@extends('layouts.app')
@section('title', 'Nueva cotización')
@section('content')
    <nav aria-label="Ruta de navegación" class="mb-4 flex items-center gap-2 text-xs text-slate-500">
        <a href="{{ route('ordenes.index') }}" class="hover:text-seibi-navy">Órdenes de servicio</a><span>/</span>
        <a href="{{ route('ordenes.show', ['orden'=>$orden,'seccion'=>'cotizaciones']) }}" class="hover:text-seibi-navy">{{ $orden->folio }}</a><span>/</span>
        <span class="font-medium text-seibi-ink">Nueva cotización</span>
    </nav>
    <x-ui.page-header title="Nueva cotización" :description="$orden->folio.' · '.$orden->vehiculo->marca.' '.$orden->vehiculo->modelo.' · '.$orden->vehiculo->cliente->nombre.' '.$orden->vehiculo->cliente->apellido">
        <x-slot:actions><x-ui.back-button :fallback="route('ordenes.show', ['orden'=>$orden,'seccion'=>'cotizaciones'])" /></x-slot:actions>
    </x-ui.page-header>
    <x-ui.card class="mb-5">
        <dl class="grid gap-4 sm:grid-cols-3"><div><dt class="text-xs font-semibold uppercase text-slate-500">Cliente</dt><dd class="mt-1 text-sm font-medium">{{ $orden->vehiculo->cliente->nombre }} {{ $orden->vehiculo->cliente->apellido }}</dd></div><div><dt class="text-xs font-semibold uppercase text-slate-500">Vehículo</dt><dd class="mt-1 text-sm">{{ $orden->vehiculo->marca }} {{ $orden->vehiculo->modelo }} · {{ $orden->vehiculo->anio }}</dd></div><div><dt class="text-xs font-semibold uppercase text-slate-500">Problema reportado</dt><dd class="mt-1 text-sm">{{ $orden->descripcion_problema }}</dd></div></dl>
    </x-ui.card>
    @include('cotizaciones._form', ['formAction'=>route('cotizaciones.store',$orden),'method'=>'POST','submitLabel'=>'Guardar borrador','cancelUrl'=>route('ordenes.show',['orden'=>$orden,'seccion'=>'cotizaciones']),'workTypes'=>$workTypes,'detailTypes'=>$detailTypes,'searchUrl'=>route('autopartes.search',$orden),'detailsUrl'=>route('autopartes.details',$orden),'offerAddUrl'=>null])
@endsection

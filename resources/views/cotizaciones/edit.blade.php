@extends('layouts.app')
@section('title', 'Editar cotización '.$cotizacion->folio)
@section('content')
    <nav aria-label="Ruta de navegación" class="mb-4 flex items-center gap-2 text-xs text-slate-500"><a href="{{ route('ordenes.index') }}" class="hover:text-seibi-navy">Órdenes de servicio</a><span>/</span><a href="{{ route('cotizaciones.index',$orden) }}" class="hover:text-seibi-navy">{{ $orden->folio }}</a><span>/</span><span class="font-medium text-seibi-ink">{{ $cotizacion->folio }}</span></nav>
    <x-ui.page-header :title="'Editar '.$cotizacion->folio" :description="$orden->vehiculo->marca.' '.$orden->vehiculo->modelo.' · '.$orden->vehiculo->cliente->nombre.' '.$orden->vehiculo->cliente->apellido"><x-slot:actions><x-ui.back-button :fallback="route('cotizaciones.show',[$orden,$cotizacion])" /></x-slot:actions></x-ui.page-header>
    @include('cotizaciones._form', ['formAction'=>route('cotizaciones.update',[$orden,$cotizacion]),'method'=>'PUT','submitLabel'=>'Guardar cambios','cancelUrl'=>route('cotizaciones.show',[$orden,$cotizacion]),'workTypes'=>$workTypes,'detailTypes'=>$detailTypes,'cotizacion'=>$cotizacion,'searchUrl'=>route('autopartes.search',$orden),'detailsUrl'=>route('autopartes.details',$orden),'offerAddUrl'=>route('autopartes.add',[$orden,$cotizacion])])
@endsection

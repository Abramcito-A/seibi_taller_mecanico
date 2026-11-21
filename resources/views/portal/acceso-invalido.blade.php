@extends('layouts.public')
@section('title', $title)
@section('content')
    <section class="rounded-panel border border-seibi-border bg-white p-6 text-center shadow-panel sm:p-8">
        <span class="mx-auto grid size-12 place-items-center rounded-full bg-amber-50 text-status-pending" aria-hidden="true">
            <i data-lucide="circle-alert" class="size-6"></i>
        </span>
        <h1 class="mt-4 text-xl font-semibold text-seibi-ink">{{ $title }}</h1>
        <p class="mx-auto mt-3 max-w-md text-sm leading-6 text-slate-600">{{ $message }}</p>
        <p class="mx-auto mt-5 max-w-md text-xs leading-5 text-slate-500">Por tu seguridad no mostramos información de la cotización en esta página.</p>
    </section>
@endsection

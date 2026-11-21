<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Vence las cotizaciones pendientes cuya vigencia terminó (el portal también lo comprueba en cada acceso).
Schedule::command('seibi:cotizaciones:vencer')->hourly()->withoutOverlapping();

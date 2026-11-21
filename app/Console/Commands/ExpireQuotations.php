<?php

namespace App\Console\Commands;

use App\Models\Cotizacion;
use App\Services\Cotizaciones\QuotationDispatchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('seibi:cotizaciones:vencer')]
#[Description('Marca como vencidas las cotizaciones pendientes de respuesta cuya vigencia terminó y revoca sus enlaces')]
class ExpireQuotations extends Command
{
    public function handle(QuotationDispatchService $dispatch): int
    {
        $expired = 0;

        Cotizacion::query()
            ->where('estado', 'enviada')
            ->where('decision_cliente', 'pendiente')
            ->whereNotNull('fecha_vencimiento')
            ->where('fecha_vencimiento', '<=', now())
            ->orderBy('id_cotizacion')
            ->chunkById(100, function ($quotations) use ($dispatch, &$expired): void {
                foreach ($quotations as $quotation) {
                    // expireIfOverdue vuelve a comprobar el estado con bloqueo, así que no duplica eventos.
                    if ($dispatch->expireIfOverdue($quotation, null, 'comando')) {
                        $expired++;
                        $this->line('Vencida: '.$quotation->folio);
                    }
                }
            }, 'id_cotizacion');

        $this->info($expired === 0 ? 'No hay cotizaciones pendientes vencidas.' : "Cotizaciones vencidas: $expired.");

        return self::SUCCESS;
    }
}

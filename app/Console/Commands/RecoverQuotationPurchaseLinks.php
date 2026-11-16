<?php

namespace App\Console\Commands;

use App\Models\Cotizacion;
use App\Models\DetalleCotizacion;
use App\Services\PartSearch\PartSearchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('seibi:cotizaciones:recuperar-enlaces
    {--cotizacion=* : Limitar a estas cotizaciones (id_cotizacion)}
    {--apply : Guardar los enlaces recuperados y registrar los intentos; sin esta opción solo se muestra el resultado}')]
#[Description('Revisa piezas sin URL directa de compra y recupera el enlace solo cuando coinciden comercio y precio de la oferta original')]
class RecoverQuotationPurchaseLinks extends Command
{
    public function handle(PartSearchService $partSearch): int
    {
        $apply = (bool) $this->option('apply');
        $quotationIds = array_map('intval', (array) $this->option('cotizacion'));

        $details = DetalleCotizacion::query()
            ->with('cotizacion:id_cotizacion,folio,estado')
            ->where('tipo_concepto', 'pieza')
            ->where(fn ($query) => $query->whereNull('url_origen')->orWhere('url_origen', ''))
            ->when($quotationIds !== [], fn ($query) => $query->whereIn('id_cotizacion', $quotationIds))
            ->orderBy('id_cotizacion')
            ->orderBy('id_detalle')
            ->get();

        if ($details->isEmpty()) {
            $this->info('No hay piezas sin URL directa de compra.');

            return self::SUCCESS;
        }

        $rows = [];
        $summary = ['recovered' => 0, 'recovered_conversion' => 0, 'not_recoverable' => 0, 'provider_error' => 0, 'protected' => 0];

        foreach ($details as $detail) {
            if (! $detail->cotizacion->isEditableDraft()) {
                $summary['protected']++;
                $rows[] = $this->row($detail, 'protegida', 'La cotización está "'.$detail->cotizacion->estado.'"; no se modifica la versión consultada por el cliente.', null);

                continue;
            }

            $result = $partSearch->recoverMerchantUrl($detail);
            $summary[$result['status']] = ($summary[$result['status']] ?? 0) + 1;
            $rows[] = $this->row($detail, $result['status'], $result['reason'], $result['url']);

            if ($apply && in_array($result['status'], ['recovered', 'recovered_conversion', 'not_recoverable'], true)) {
                $this->persist($detail, $result);
            }
        }

        $this->table(['Detalle', 'Cotización', 'Estado cot.', 'Descripción', 'Comercio', 'Precio', 'Resultado', 'Motivo', 'URL propuesta'], $rows);
        $this->line(sprintf(
            'Recuperables: %d · Recuperables por tipo de cambio: %d · No recuperables: %d · Error del proveedor: %d · Protegidas (no borrador): %d',
            $summary['recovered'], $summary['recovered_conversion'], $summary['not_recoverable'], $summary['provider_error'], $summary['protected'],
        ));
        $this->line($apply
            ? 'Cambios aplicados solo a borradores. Los errores del proveedor no se registran para poder reintentar.'
            : 'Vista previa: no se modificó ningún registro. Ejecuta con --apply para guardar.');

        return self::SUCCESS;
    }

    /**
     * @param  array{status: string, url: ?string, reason: string}  $result
     */
    private function persist(DetalleCotizacion $detail, array $result): void
    {
        DB::transaction(function () use ($detail, $result): void {
            $quotation = Cotizacion::query()->lockForUpdate()->findOrFail($detail->id_cotizacion);
            $locked = DetalleCotizacion::query()->lockForUpdate()->findOrFail($detail->id_detalle);
            if (! $quotation->isEditableDraft() || DetalleCotizacion::isSafeExternalUrl($locked->url_origen)) {
                return;
            }

            $recovered = in_array($result['status'], ['recovered', 'recovered_conversion'], true);
            $locked->forceFill([
                'url_origen' => $recovered ? $result['url'] : $locked->url_origen,
                'enlace_estado' => match ($result['status']) {
                    'recovered' => 'recuperado',
                    'recovered_conversion' => 'recuperado_conversion',
                    default => 'no_recuperable',
                },
                'enlace_motivo' => mb_substr($result['reason'], 0, 255),
                'enlace_verificado_en' => now(),
            ])->save();

            // Invalida formularios de edición abiertos para que no sobrescriban el enlace recuperado.
            $quotation->revision++;
            $quotation->save();
        });
    }

    /**
     * @return array<int, string>
     */
    private function row(DetalleCotizacion $detail, string $status, string $reason, ?string $url): array
    {
        return [
            (string) $detail->id_detalle,
            $detail->cotizacion->folio,
            $detail->cotizacion->estado,
            mb_strimwidth($detail->descripcion, 0, 45, '…'),
            (string) $detail->tienda_origen,
            '$'.number_format((float) ($detail->precio_proveedor ?? $detail->precio_unitario), 2),
            $status,
            $reason,
            $url ?? '—',
        ];
    }
}

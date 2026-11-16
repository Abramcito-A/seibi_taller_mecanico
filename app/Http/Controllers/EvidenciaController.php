<?php

namespace App\Http\Controllers;

use App\Models\Evidencia;
use App\Models\OrdenServicio;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenciaController extends Controller
{
    public function show(OrdenServicio $orden, Evidencia $evidencia): StreamedResponse
    {
        abort_unless(
            (int) $evidencia->id_orden === (int) $orden->id_orden
            && in_array($evidencia->etapa, [
                Evidencia::ETAPA_RECEPCION,
                Evidencia::ETAPA_DIAGNOSTICO,
                Evidencia::ETAPA_REPARACION,
                Evidencia::ETAPA_ENTREGA,
            ], true),
            404,
        );

        abort_unless(auth()->user()->hasRole('administrador', 'mecanico', 'recepcionista'), 403);

        $stagePattern = match ($evidencia->etapa) {
            Evidencia::ETAPA_RECEPCION => 'recepcion',
            Evidencia::ETAPA_DIAGNOSTICO => 'diagnosticos',
            Evidencia::ETAPA_REPARACION => 'reparacion',
            Evidencia::ETAPA_ENTREGA => 'entrega',
        };
        // Solo rutas generadas por el servidor: ordenes/{id}/{etapa}/[{id_diagnostico}/]{uuid}.{ext}.
        // Se rechaza cualquier otro segmento (p. ej. "..") para impedir llegar a archivos de otra orden.
        $pathPattern = '/^ordenes\/'.preg_quote((string) $orden->id_orden, '/').'\/'
            .preg_quote($stagePattern, '/').'\/(?:\d+\/)?[a-f0-9-]+\.(?:jpg|jpeg|png|webp)$/i';
        abort_unless(preg_match($pathPattern, $evidencia->ruta_archivo) === 1, 404);

        $disk = Storage::disk('private');

        abort_unless($disk->exists($evidencia->ruta_archivo), 404);

        $contentType = match (strtolower(pathinfo($evidencia->ruta_archivo, PATHINFO_EXTENSION))) {
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => abort(404),
        };

        return $disk->response($evidencia->ruta_archivo, $evidencia->nombre_archivo, [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

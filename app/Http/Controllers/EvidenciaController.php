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
            && in_array($evidencia->etapa, [Evidencia::ETAPA_RECEPCION, Evidencia::ETAPA_DIAGNOSTICO], true),
            404,
        );

        abort_unless(
            $evidencia->etapa === Evidencia::ETAPA_RECEPCION
            || auth()->user()->hasRole('administrador', 'mecanico'),
            403,
        );

        $pathPattern = $evidencia->etapa === Evidencia::ETAPA_RECEPCION
            ? '/^ordenes\/'.preg_quote((string) $orden->id_orden, '/').'\/recepcion\/[a-f0-9-]+\.(?:jpg|png|webp)$/i'
            : '/^ordenes\/'.preg_quote((string) $orden->id_orden, '/').'\/diagnosticos\/[0-9]+\/[a-f0-9-]+\.(?:jpg|png|webp)$/i';
        abort_unless(preg_match($pathPattern, $evidencia->ruta_archivo) === 1, 404);

        $disk = Storage::disk('private');

        abort_unless($disk->exists($evidencia->ruta_archivo), 404);

        $contentType = match (strtolower(pathinfo($evidencia->ruta_archivo, PATHINFO_EXTENSION))) {
            'jpg' => 'image/jpeg',
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

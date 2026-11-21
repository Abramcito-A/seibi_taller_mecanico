<?php

namespace App\Http\Controllers;

use App\Models\AccesoCliente;
use App\Models\ActualizacionReparacion;
use App\Models\OrdenServicio;
use App\Models\TrabajoReparacion;
use App\Services\Reparaciones\RepairProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Acciones del mecánico en la pestaña «Seguimiento de reparación» de la orden. La consulta se muestra en
 * ordenes.show; aquí solo se registran avances, publicaciones, revisión final, etapas y revocación de enlaces.
 */
class SeguimientoReparacionController extends Controller
{
    private const PHOTO_RULES = ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

    private const PHOTO_MESSAGES = [
        'fotografias.array' => 'Las fotografías no tienen un formato válido.',
        'fotografias.max' => 'Puedes adjuntar hasta '.RepairProgressService::MAX_PHOTOS.' fotografías por actualización.',
        'fotografias.*.image' => 'Cada archivo debe ser una imagen válida.',
        'fotografias.*.mimes' => 'Solo se admiten imágenes JPEG, PNG o WebP.',
        'fotografias.*.max' => 'Cada fotografía debe pesar como máximo 5 MB.',
        'confirmar_publicacion.accepted' => 'Confirma que el contenido y las fotografías serán visibles para el cliente.',
    ];

    public function __construct(private RepairProgressService $progress) {}

    public function storeUpdate(Request $request, OrdenServicio $orden): RedirectResponse
    {
        $this->authorizeMechanic();

        $validated = $request->validate([
            'id_trabajo' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(['avance', 'correccion'])],
            'id_actualizacion_corregida' => ['nullable', 'required_if:tipo,correccion', 'integer'],
            'estado' => ['nullable', Rule::in(array_keys(TrabajoReparacion::STATUSES))],
            'titulo' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:5000', 'required_if:estado,completado'],
            'motivo' => ['nullable', 'string', 'max:2000', 'required_if:estado,en_espera', 'required_if:estado,no_realizado'],
            'visibilidad' => ['required', Rule::in(['interna', 'cliente'])],
            'confirmar_publicacion' => ['exclude_unless:visibilidad,cliente', 'accepted'],
            'fotografias' => ['nullable', 'array', 'max:'.RepairProgressService::MAX_PHOTOS],
            'fotografias.*' => self::PHOTO_RULES,
        ], [
            'id_trabajo.required' => 'Selecciona el trabajo relacionado.',
            'titulo.required' => 'Escribe un título para el avance.',
            'descripcion.required_if' => 'Describe las actividades realizadas para completar el trabajo.',
            'motivo.required_if' => 'Indica el motivo; el estado seleccionado lo requiere.',
            'id_actualizacion_corregida.required_if' => 'Selecciona la publicación que vas a corregir.',
            ...self::PHOTO_MESSAGES,
        ]);

        $this->progress->recordUpdate($orden, $request->user(), $validated, $validated['fotografias'] ?? []);

        return $this->backToTracking($orden, $validated['visibilidad'] === 'cliente'
            ? 'Avance registrado y publicado para el cliente.'
            : 'Avance registrado como actualización interna.');
    }

    public function storeFinalReview(Request $request, OrdenServicio $orden): RedirectResponse
    {
        $this->authorizeMechanic();

        $validated = $request->validate([
            'titulo' => ['nullable', 'string', 'max:150'],
            'descripcion' => ['required', 'string', 'min:5', 'max:5000'],
            'visibilidad' => ['required', Rule::in(['interna', 'cliente'])],
            'confirmar_publicacion' => ['exclude_unless:visibilidad,cliente', 'accepted'],
            'fotografias' => ['nullable', 'array', 'max:'.RepairProgressService::MAX_PHOTOS],
            'fotografias.*' => self::PHOTO_RULES,
        ], [
            'descripcion.required' => 'Describe el resultado de la revisión final.',
            ...self::PHOTO_MESSAGES,
        ]);

        $this->progress->recordFinalReview($orden, $request->user(), $validated, $validated['fotografias'] ?? []);

        return $this->backToTracking($orden, 'Revisión final registrada.');
    }

    public function publish(Request $request, OrdenServicio $orden, ActualizacionReparacion $actualizacion): RedirectResponse
    {
        $this->authorizeMechanic();
        abort_unless((int) $actualizacion->id_orden === (int) $orden->id_orden, 404);

        $this->progress->publish($orden, $actualizacion, $request->user());

        return $this->backToTracking($orden, 'La actualización ahora es visible para el cliente.');
    }

    public function transition(Request $request, OrdenServicio $orden): RedirectResponse
    {
        $this->authorizeMechanic();

        $validated = $request->validate([
            'estado_orden' => ['required', Rule::in(array_keys(RepairProgressService::ORDER_TRANSITIONS))],
            'comentario' => ['nullable', 'string', 'max:2000'],
            'confirmar_advertencias' => ['nullable', 'boolean'],
        ]);

        $this->progress->transitionOrder(
            $orden,
            $validated['estado_orden'],
            $request->user(),
            $validated['comentario'] ?? null,
            (bool) ($validated['confirmar_advertencias'] ?? false),
        );

        return $this->backToTracking($orden, 'La orden cambió a «'.(OrdenServicio::STATUS_LABELS[$validated['estado_orden']] ?? $validated['estado_orden']).'».');
    }

    public function revokeAccess(Request $request, OrdenServicio $orden, AccesoCliente $acceso): RedirectResponse
    {
        $this->authorizeMechanic();
        abort_unless((int) $acceso->id_orden === (int) $orden->id_orden, 404);

        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'motivo.required' => 'Indica por qué se revoca el enlace del cliente.',
            'motivo.min' => 'Explica brevemente por qué se revoca el enlace.',
        ]);

        $this->progress->revokeAccess($orden, $acceso, $request->user(), $validated['motivo'], $request);

        return $this->backToTracking($orden, 'El enlace del cliente fue revocado.');
    }

    private function authorizeMechanic(): void
    {
        abort_unless(auth()->user()?->hasRole('mecanico'), 403);
    }

    private function backToTracking(OrdenServicio $orden, string $message): RedirectResponse
    {
        return redirect()->route('ordenes.show', ['orden' => $orden, 'seccion' => 'seguimiento'])->with('success', $message);
    }
}

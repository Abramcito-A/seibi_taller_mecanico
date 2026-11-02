<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Evidencia;
use App\Models\HistorialEstado;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class OrdenServicioController extends Controller
{
    private const MAX_PHOTOS = 5;

    private const STATUS_LABELS = [
        'recibida' => 'Recibida',
        'en_diagnostico' => 'En diagnóstico',
        'esperando_aprobacion' => 'Esperando aprobación',
        'esperando_refaccion' => 'Esperando refacciones',
        'esperando_refacciones' => 'Esperando refacciones',
        'en_reparacion' => 'En reparación',
        'lista_entrega' => 'Lista para entrega',
        'entregada' => 'Entregada',
        'cerrada' => 'Cerrada',
        'cancelada' => 'Cancelada',
    ];

    public function index(Request $request): View
    {
        $query = OrdenServicio::query()->with(['vehiculo.cliente']);
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('estado');

        if ($search !== '') {
            $query->where(function (Builder $orders) use ($search): void {
                $pattern = '%'.$search.'%';

                $orders->where('folio', 'like', $pattern)
                    ->orWhereHas('vehiculo', function (Builder $vehicles) use ($pattern): void {
                        $vehicles->where(function (Builder $vehicleFields) use ($pattern): void {
                            $vehicleFields->where('marca', 'like', $pattern)
                                ->orWhere('modelo', 'like', $pattern)
                                ->orWhere('placas', 'like', $pattern);
                        })->orWhereHas('cliente', function (Builder $clients) use ($pattern): void {
                            $clients->where('nombre', 'like', $pattern)
                                ->orWhere('apellido', 'like', $pattern)
                                ->orWhere('telefono', 'like', $pattern)
                                ->orWhere('correo', 'like', $pattern);
                        });
                    });
            });
        }

        if (is_string($status) && array_key_exists($status, self::STATUS_LABELS)) {
            if ($status === 'esperando_refacciones') {
                $query->whereIn('estado', ['esperando_refacciones', 'esperando_refaccion']);
            } else {
                $query->where('estado', $status);
            }
        }

        return view('ordenes.index', [
            'ordenes' => $query->latest('fecha_ingreso')->paginate(15)->withQueryString(),
            'statusLabels' => self::STATUS_LABELS,
            'statusTones' => [
                'recibida' => 'pending',
                'en_diagnostico' => 'info',
                'esperando_aprobacion' => 'pending',
                'esperando_refaccion' => 'pending',
                'esperando_refacciones' => 'pending',
                'en_reparacion' => 'info',
                'lista_entrega' => 'success',
                'entregada' => 'success',
                'cerrada' => 'neutral',
                'cancelada' => 'error',
            ],
            'filters' => ['q' => $search, 'estado' => $status],
        ]);
    }

    public function create(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $clientQuery = Cliente::query();

        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $clientQuery->where(function (Builder $clients) use ($pattern): void {
                $clients->where('nombre', 'like', $pattern)
                    ->orWhere('apellido', 'like', $pattern)
                    ->orWhere('telefono', 'like', $pattern)
                    ->orWhere('correo', 'like', $pattern);
            });
        }

        $selectedClientId = $request->integer('cliente_id') ?: null;
        $selectedClient = $selectedClientId === null
            ? null
            : Cliente::query()->with('vehiculos')->find($selectedClientId);
        $clients = $clientQuery->orderBy('apellido')->orderBy('nombre')->limit(50)->get();

        if ($selectedClient !== null && ! $clients->contains('id_cliente', $selectedClient->id_cliente)) {
            $clients->prepend($selectedClient);
        }

        return view('ordenes.create', [
            'clientes' => $clients,
            'selectedClient' => $selectedClient,
            'search' => $search,
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateReception($request);
        $photos = $validated['fotografias'] ?? [];
        $storedPaths = [];

        try {
            $orden = DB::transaction(function () use ($request, $validated, $photos, &$storedPaths): OrdenServicio {
                $orden = new OrdenServicio;
                $orden->id_vehiculo = $validated['id_vehiculo'];
                $orden->id_usuario = $request->user()->getKey();
                $orden->id_orden_origen = null;
                $orden->folio = $this->generateFolio();
                $orden->tipo_orden = 'normal';
                $orden->fecha_ingreso = Carbon::parse($validated['fecha_ingreso']);
                $orden->kilometraje_ingreso = $validated['kilometraje_ingreso'] ?? null;
                $orden->descripcion_problema = $validated['descripcion_problema'];
                $orden->observaciones = $validated['observaciones'] ?? null;
                $orden->estado = 'recibida';
                $orden->fecha_cierre = null;
                $orden->save();

                HistorialEstado::query()->create([
                    'id_orden' => $orden->id_orden,
                    'id_usuario' => $request->user()->getKey(),
                    'estado_anterior' => null,
                    'estado_nuevo' => 'recibida',
                    'comentario' => 'Recepción inicial del vehículo.',
                ]);

                $this->storeReceptionPhotos($orden, $photos, $request->user(), $storedPaths);

                return $orden;
            });
        } catch (Throwable $exception) {
            $this->removeStoredFiles($storedPaths);

            throw $exception;
        }

        return redirect()->route('ordenes.show', $orden)
            ->with('success', "Recepción guardada. Folio {$orden->folio}.");
    }

    public function show(Request $request, OrdenServicio $orden): View
    {
        $orden->load([
            'vehiculo.cliente',
            'usuarioResponsable',
            'historialEstados.usuario',
            'evidencias.usuario',
        ]);

        $validSections = ['resumen', 'recepcion', 'diagnosticos', 'evidencias', 'historial'];
        $section = $request->string('seccion')->toString();
        if (! in_array($section, $validSections, true)) {
            $section = 'resumen';
        }

        $diagnosisQuery = $orden->diagnosticos()
            ->with(['usuario', 'evidencias'])
            ->orderByDesc('fecha_diagnostico')
            ->orderByDesc('id_diagnostico');

        $diagnosisSearch = trim($request->string('diagnostico_q')->toString());
        $diagnosisSeverity = $request->string('severidad')->toString();
        if ($diagnosisSearch !== '') {
            $diagnosisQuery->where('titulo', 'like', '%'.$diagnosisSearch.'%');
        }
        if (in_array($diagnosisSeverity, ['baja', 'media', 'alta', 'critica'], true)) {
            $diagnosisQuery->where('severidad', $diagnosisSeverity);
        }

        $diagnosticos = $diagnosisQuery->paginate(5)->withQueryString();

        return view('ordenes.show', [
            'orden' => $orden,
            'statusLabels' => self::STATUS_LABELS,
            'section' => $section,
            'diagnosticos' => $diagnosticos,
            'diagnosisFilters' => [
                'q' => $diagnosisSearch,
                'severidad' => $diagnosisSeverity,
            ],
            'highlightedDiagnosisId' => $request->integer('destacado') ?: null,
        ]);
    }

    public function edit(Request $request, OrdenServicio $orden): View
    {
        abort_unless($orden->estado === 'recibida', 403);

        $search = trim((string) $request->query('q', ''));
        $clientQuery = Cliente::query();

        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $clientQuery->where(function (Builder $clients) use ($pattern): void {
                $clients->where('nombre', 'like', $pattern)
                    ->orWhere('apellido', 'like', $pattern)
                    ->orWhere('telefono', 'like', $pattern)
                    ->orWhere('correo', 'like', $pattern);
            });
        }

        $selectedClientId = $request->integer('cliente_id') ?: $orden->vehiculo->id_cliente;
        $selectedClient = Cliente::query()->with('vehiculos')->find($selectedClientId);
        $clients = $clientQuery->orderBy('apellido')->orderBy('nombre')->limit(50)->get();

        if ($selectedClient !== null && ! $clients->contains('id_cliente', $selectedClient->id_cliente)) {
            $clients->prepend($selectedClient);
        }

        return view('ordenes.edit', [
            'orden' => $orden->load('vehiculo'),
            'clientes' => $clients,
            'selectedClient' => $selectedClient,
            'search' => $search,
        ]);
    }

    public function update(Request $request, OrdenServicio $orden): RedirectResponse
    {
        abort_unless($orden->estado === 'recibida', 403);

        $validated = $this->validateReception($request);
        $photos = $validated['fotografias'] ?? [];
        $storedPaths = [];

        try {
            DB::transaction(function () use ($request, $orden, $validated, $photos, &$storedPaths): void {
                $lockedOrder = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id_orden);
                abort_unless($lockedOrder->estado === 'recibida', 403);

                $lockedOrder->id_vehiculo = $validated['id_vehiculo'];
                $lockedOrder->fecha_ingreso = Carbon::parse($validated['fecha_ingreso']);
                $lockedOrder->kilometraje_ingreso = $validated['kilometraje_ingreso'] ?? null;
                $lockedOrder->descripcion_problema = $validated['descripcion_problema'];
                $lockedOrder->observaciones = $validated['observaciones'] ?? null;
                $lockedOrder->save();

                $this->storeReceptionPhotos($lockedOrder, $photos, $request->user(), $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->removeStoredFiles($storedPaths);

            throw $exception;
        }

        return redirect()->route('ordenes.show', $orden)
            ->with('success', 'Datos de recepción actualizados.');
    }

    public function iniciarDiagnostico(Request $request, OrdenServicio $orden): RedirectResponse
    {
        DB::transaction(function () use ($request, $orden): void {
            $lockedOrder = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id_orden);
            abort_unless($lockedOrder->estado === 'recibida', 403);

            $lockedOrder->estado = 'en_diagnostico';
            $lockedOrder->save();

            HistorialEstado::query()->create([
                'id_orden' => $lockedOrder->id_orden,
                'id_usuario' => $request->user()->getKey(),
                'estado_anterior' => 'recibida',
                'estado_nuevo' => 'en_diagnostico',
                'comentario' => 'El diagnóstico fue iniciado por el personal técnico.',
            ]);
        });

        return redirect()->route('ordenes.show', $orden)
            ->with('success', 'La orden pasó a diagnóstico.');
    }

    private function validateReception(Request $request): array
    {
        $clientId = $request->input('id_cliente');

        return $request->validate([
            'id_cliente' => ['required', 'integer', Rule::exists('clientes', 'id_cliente')],
            'id_vehiculo' => [
                'required',
                'integer',
                Rule::exists('vehiculos', 'id_vehiculo')->where('id_cliente', $clientId),
            ],
            'fecha_ingreso' => ['required', 'date'],
            'kilometraje_ingreso' => ['nullable', 'integer', 'min:0'],
            'descripcion_problema' => ['required', 'string', 'max:5000'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
            'fotografias' => ['nullable', 'array', 'max:'.self::MAX_PHOTOS],
            'fotografias.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'id_cliente.required' => 'Selecciona un cliente.',
            'id_cliente.exists' => 'El cliente seleccionado no existe.',
            'id_vehiculo.required' => 'Selecciona un vehículo.',
            'id_vehiculo.exists' => 'El vehículo no pertenece al cliente seleccionado.',
            'fecha_ingreso.required' => 'Indica la fecha y hora de ingreso.',
            'kilometraje_ingreso.integer' => 'El kilometraje debe ser un número entero.',
            'kilometraje_ingreso.min' => 'El kilometraje no puede ser negativo.',
            'descripcion_problema.required' => 'Describe la falla o servicio reportado.',
            'fotografias.array' => 'Las fotografías no tienen un formato válido.',
            'fotografias.max' => 'Puedes adjuntar hasta '.self::MAX_PHOTOS.' fotografías.',
            'fotografias.*.image' => 'Cada archivo debe ser una imagen válida.',
            'fotografias.*.mimes' => 'Solo se admiten imágenes JPEG, PNG o WebP.',
            'fotografias.*.max' => 'Cada fotografía debe pesar como máximo 5 MB.',
        ]);
    }

    private function generateFolio(): string
    {
        do {
            $folio = 'OS-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
        } while (OrdenServicio::query()->where('folio', $folio)->exists());

        return $folio;
    }

    /**
     * @param  array<int, UploadedFile>  $photos
     * @param  array<int, string>  $storedPaths
     */
    private function storeReceptionPhotos(OrdenServicio $orden, array $photos, User $user, array &$storedPaths): void
    {
        $disk = Storage::disk('private');
        $directory = 'ordenes/'.$orden->id_orden.'/recepcion';

        foreach ($photos as $photo) {
            $mimeType = $photo->getMimeType();
            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => throw ValidationException::withMessages([
                    'fotografias' => 'Solo se admiten imágenes JPEG, PNG o WebP.',
                ]),
            };
            $internalName = Str::uuid().'.'.$extension;
            $expectedPath = $directory.'/'.$internalName;
            $storedPaths[] = $expectedPath;
            $path = $disk->putFileAs($directory, $photo, $internalName);

            if (! is_string($path) || $path !== $expectedPath) {
                throw new \RuntimeException('No se pudo guardar una fotografía de recepción.');
            }

            $evidence = new Evidencia;
            $evidence->id_orden = $orden->id_orden;
            $evidence->id_diagnostico = null;
            $evidence->id_usuario = $user->getKey();
            $evidence->tipo_archivo = 'imagen';
            $evidence->etapa = Evidencia::ETAPA_RECEPCION;
            $evidence->nombre_archivo = $this->safeOriginalFilename($photo);
            $evidence->ruta_archivo = $path;
            $evidence->tipo_mime = $mimeType;
            $evidence->descripcion = null;
            $evidence->visible_cliente = false;
            $evidence->save();
        }
    }

    private function safeOriginalFilename(UploadedFile $photo): string
    {
        $name = basename(str_replace('\\', '/', $photo->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim(mb_substr($name, 0, 255));

        return $name !== '' ? $name : 'fotografia';
    }

    /** @param  array<int, string>  $storedPaths */
    private function removeStoredFiles(array $storedPaths): void
    {
        if ($storedPaths !== []) {
            Storage::disk('private')->delete($storedPaths);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Diagnostico;
use App\Models\Evidencia;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class DiagnosticoController extends Controller
{
    private const MAX_PHOTOS = 5;

    public function create(Request $request, OrdenServicio $orden): View
    {
        abort_unless($orden->estado === 'en_diagnostico', 403);
        $orden->load(['vehiculo.cliente', 'evidencias']);
        $requestedUuid = $request->query('envio_uuid');
        $envioUuid = is_string($requestedUuid) && $this->isValidUuid($requestedUuid)
            ? $requestedUuid
            : (string) Str::uuid();

        return view('diagnosticos.create', [
            'orden' => $orden,
            'envioUuid' => $envioUuid,
        ]);
    }

    public function submissionStatus(Request $request, OrdenServicio $orden, string $envioUuid): JsonResponse
    {
        abort_unless($this->isValidUuid($envioUuid), 404);

        $diagnostico = Diagnostico::query()
            ->where('id_orden', $orden->id_orden)
            ->where('envio_uuid', $envioUuid)
            ->first();

        if ($diagnostico === null) {
            return response()->json(['processed' => false]);
        }

        return response()->json([
            'processed' => true,
            'diagnosis' => [
                'id' => $diagnostico->id_diagnostico,
                'titulo' => $diagnostico->titulo,
                'descripcion' => $diagnostico->descripcion,
                'severidad' => $diagnostico->severidad,
                'recomendacion' => $diagnostico->recomendacion,
                'url' => route('ordenes.show', [
                    'orden' => $orden,
                    'seccion' => 'diagnosticos',
                    'destacado' => $diagnostico->id_diagnostico,
                ]).'#diagnostico-'.$diagnostico->id_diagnostico,
            ],
        ]);
    }

    public function store(Request $request, OrdenServicio $orden): RedirectResponse|View
    {
        abort_unless($orden->estado === 'en_diagnostico', 403);
        $validated = $this->validateDiagnosis($request);
        $submissionUuid = $validated['envio_uuid'];
        $photos = $validated['fotografias'] ?? [];
        $submissionHash = $this->submissionHash($validated, $photos);

        $existing = Diagnostico::query()
            ->where('envio_uuid', $submissionUuid)
            ->where('id_orden', $orden->id_orden)
            ->first();
        if ($existing !== null) {
            if ($this->submissionMatches($existing, $validated, $submissionHash, $photos)) {
                return $this->alreadyProcessedRedirect($orden, $existing);
            }

            return $this->pendingChangesRedirect($request, $orden, $existing, $validated, $photos);
        }

        $storedPaths = [];
        $diagnostico = null;

        try {
            $diagnostico = DB::transaction(function () use ($request, $orden, $validated, $photos, $submissionHash, &$storedPaths): Diagnostico {
                $lockedOrder = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id_orden);
                abort_unless($lockedOrder->estado === 'en_diagnostico', 403);

                $diagnostico = Diagnostico::query()->create([
                    'id_orden' => $lockedOrder->id_orden,
                    'id_usuario' => $request->user()->getKey(),
                    'titulo' => $validated['titulo'],
                    'descripcion' => $validated['descripcion'],
                    'severidad' => $validated['severidad'],
                    'recomendacion' => $validated['recomendacion'] ?? null,
                    'envio_uuid' => $validated['envio_uuid'],
                    'envio_hash' => $submissionHash,
                ]);

                $this->storeDiagnosticPhotos($lockedOrder, $diagnostico, $photos, $request->user(), $storedPaths);

                return $diagnostico;
            });
        } catch (QueryException $exception) {
            $this->removeStoredFiles($storedPaths);

            if ($this->isUniqueConstraintViolation($exception)) {
                $diagnostico = Diagnostico::query()
                    ->where('envio_uuid', $submissionUuid)
                    ->where('id_orden', $orden->id_orden)
                    ->first();
                if ($diagnostico !== null) {
                    if ($this->submissionMatches($diagnostico, $validated, $submissionHash, $photos)) {
                        return $this->alreadyProcessedRedirect($orden, $diagnostico);
                    }

                    return $this->pendingChangesRedirect($request, $orden, $diagnostico, $validated, $photos);
                }
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->removeStoredFiles($storedPaths);
            throw $exception;
        }

        return $this->diagnosisRedirect($orden, $diagnostico);
    }

    public function confirmNew(Request $request, OrdenServicio $orden, string $token): RedirectResponse
    {
        abort_unless($orden->estado === 'en_diagnostico', 403);

        $pending = $request->session()->get('diagnostico_pendiente_'.$token);
        abort_unless(is_array($pending) && (int) $pending['id_orden'] === (int) $orden->id_orden, 404);

        $lockedOrder = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id_orden);
        abort_unless($lockedOrder->estado === 'en_diagnostico', 403);

        $newUuid = (string) Str::uuid();
        $storedPaths = [];
        $diagnostico = null;

        try {
            $diagnostico = DB::transaction(function () use ($lockedOrder, $pending, $newUuid, $request, &$storedPaths): Diagnostico {
                $diagnostico = Diagnostico::query()->create([
                    'id_orden' => $lockedOrder->id_orden,
                    'id_usuario' => $request->user()->getKey(),
                    'titulo' => $pending['titulo'],
                    'descripcion' => $pending['descripcion'],
                    'severidad' => $pending['severidad'],
                    'recomendacion' => $pending['recomendacion'],
                    'envio_uuid' => $newUuid,
                    'envio_hash' => $pending['envio_hash'],
                ]);

                foreach ($pending['photos'] ?? [] as $photo) {
                    $sourcePath = $photo['temp_path'];
                    if (! Storage::disk('private')->exists($sourcePath)) {
                        continue;
                    }

                    $extension = match ($photo['mime']) {
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                        default => null,
                    };
                    if ($extension === null) {
                        continue;
                    }

                    $finalPath = 'ordenes/'.$lockedOrder->id_orden.'/diagnosticos/'.$diagnostico->id_diagnostico.'/'.Str::uuid().'.'.$extension;
                    Storage::disk('private')->copy($sourcePath, $finalPath);
                    $storedPaths[] = $finalPath;

                    Evidencia::query()->create([
                        'id_orden' => $lockedOrder->id_orden,
                        'id_diagnostico' => $diagnostico->id_diagnostico,
                        'id_usuario' => $request->user()->getKey(),
                        'tipo_archivo' => 'imagen',
                        'etapa' => Evidencia::ETAPA_DIAGNOSTICO,
                        'nombre_archivo' => $photo['filename'],
                        'ruta_archivo' => $finalPath,
                        'tipo_mime' => $photo['mime'],
                        'descripcion' => null,
                        'visible_cliente' => false,
                    ]);
                }

                return $diagnostico;
            });
        } catch (Throwable $exception) {
            $this->removeStoredFiles($storedPaths);
            throw $exception;
        }

        $request->session()->forget('diagnostico_pendiente_'.$token);
        Storage::disk('private')->deleteDirectory('ordenes/'.$orden->id_orden.'/diagnosticos/tmp/'.$token);

        return $this->diagnosisRedirect($orden, $diagnostico);
    }

    public function discardChanges(Request $request, OrdenServicio $orden, string $token, Diagnostico $diagnostico): RedirectResponse
    {
        abort_unless((int) $diagnostico->id_orden === (int) $orden->id_orden, 404);

        $request->session()->forget('diagnostico_pendiente_'.$token);
        Storage::disk('private')->deleteDirectory('ordenes/'.$orden->id_orden.'/diagnosticos/tmp/'.$token);

        return redirect()->to(route('ordenes.show', [
            'orden' => $orden,
            'seccion' => 'diagnosticos',
            'destacado' => $diagnostico->id_diagnostico,
        ]).'#diagnostico-'.$diagnostico->id_diagnostico)
            ->with('info', 'Se descartaron los cambios nuevos. Se conserva el diagnóstico anterior.');
    }

    public function edit(OrdenServicio $orden, Diagnostico $diagnostico): View
    {
        abort_unless(
            (int) $diagnostico->id_orden === (int) $orden->id_orden
            && ! in_array($orden->estado, ['cerrada', 'cancelada'], true),
            404,
        );
        $orden->load(['vehiculo.cliente']);

        return view('diagnosticos.edit', compact('orden', 'diagnostico'));
    }

    public function update(Request $request, OrdenServicio $orden, Diagnostico $diagnostico): RedirectResponse
    {
        abort_unless(
            (int) $diagnostico->id_orden === (int) $orden->id_orden
            && ! in_array($orden->estado, ['cerrada', 'cancelada'], true),
            404,
        );
        $validated = $this->validateDiagnosis($request, false);
        $photos = $validated['fotografias'] ?? [];
        $storedPaths = [];

        try {
            DB::transaction(function () use ($request, $orden, $diagnostico, $validated, $photos, &$storedPaths): void {
                $lockedOrder = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id_orden);
                abort_unless(
                    (int) $diagnostico->id_orden === (int) $lockedOrder->id_orden
                    && ! in_array($lockedOrder->estado, ['cerrada', 'cancelada'], true),
                    404,
                );

                $diagnostico->update([
                    'titulo' => $validated['titulo'],
                    'descripcion' => $validated['descripcion'],
                    'severidad' => $validated['severidad'],
                    'recomendacion' => $validated['recomendacion'] ?? null,
                ]);

                $this->storeDiagnosticPhotos($lockedOrder, $diagnostico, $photos, $request->user(), $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->removeStoredFiles($storedPaths);
            throw $exception;
        }

        return redirect()->route('ordenes.show', $orden)
            ->with('success', 'Hallazgo técnico actualizado.');
    }

    private function validateDiagnosis(Request $request, bool $requirePhotos = true): array
    {
        return $request->validate([
            'envio_uuid' => [$requirePhotos ? 'required' : 'nullable', 'uuid'],
            'titulo' => ['required', 'string', 'max:150'],
            'descripcion' => ['required', 'string', 'max:5000'],
            'severidad' => ['required', Rule::in(['baja', 'media', 'alta', 'critica'])],
            'recomendacion' => ['nullable', 'string', 'max:5000'],
            'fotografias' => ['nullable', 'array', 'max:'.self::MAX_PHOTOS],
            'fotografias.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'titulo.required' => 'Indica el título del hallazgo.',
            'descripcion.required' => 'Describe el hallazgo técnico.',
            'severidad.in' => 'La severidad seleccionada no es válida.',
            'fotografias.max' => 'Puedes adjuntar hasta '.self::MAX_PHOTOS.' fotografías.',
            'fotografias.*.mimes' => 'Solo se admiten imágenes JPEG, PNG o WebP.',
            'fotografias.*.max' => 'Cada fotografía debe pesar como máximo 5 MB.',
        ]);
    }

    private function diagnosisRedirect(OrdenServicio $orden, Diagnostico $diagnostico): RedirectResponse
    {
        $url = route('ordenes.show', [
            'orden' => $orden,
            'seccion' => 'diagnosticos',
            'destacado' => $diagnostico->id_diagnostico,
        ]).'#diagnostico-'.$diagnostico->id_diagnostico;

        return redirect()->to($url)
            ->with('success', 'Hallazgo registrado correctamente')
            ->with('new_diagnosis_created', true);
    }

    private function alreadyProcessedRedirect(OrdenServicio $orden, Diagnostico $diagnostico): RedirectResponse
    {
        $url = route('ordenes.show', [
            'orden' => $orden,
            'seccion' => 'diagnosticos',
            'destacado' => $diagnostico->id_diagnostico,
        ]).'#diagnostico-'.$diagnostico->id_diagnostico;

        return redirect()->to($url)
            ->with('info', 'Este formulario ya había sido procesado. No se creó otro hallazgo')
            ->with('duplicate_diagnosis_url', $url);
    }

    private function pendingChangesRedirect(
        Request $request,
        OrdenServicio $orden,
        Diagnostico $diagnostico,
        array $validated,
        array $photos,
    ): View {
        $token = (string) Str::uuid();
        $tempDirectory = 'ordenes/'.$orden->id_orden.'/diagnosticos/tmp/'.$token;
        $pendingPhotos = [];
        $disk = Storage::disk('private');

        foreach ($photos as $photo) {
            $mime = $photo->getMimeType();
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => null,
            };
            if ($extension === null) {
                continue;
            }

            $tempPath = $tempDirectory.'/'.Str::uuid().'.'.$extension;
            $disk->put($tempPath, file_get_contents($photo->getRealPath()));
            $pendingPhotos[] = [
                'temp_path' => $tempPath,
                'filename' => $photo->getClientOriginalName(),
                'mime' => $mime,
            ];
        }

        $pending = [
            'id_orden' => $orden->id_orden,
            'titulo' => $validated['titulo'],
            'descripcion' => $validated['descripcion'],
            'severidad' => $validated['severidad'],
            'recomendacion' => $validated['recomendacion'] ?? null,
            'envio_hash' => $this->submissionHash($validated, $photos),
            'photos' => $pendingPhotos,
        ];
        $request->session()->put('diagnostico_pendiente_'.$token, $pending);

        return view('diagnosticos.confirm-new', [
            'orden' => $orden,
            'diagnostico' => $diagnostico,
            'pending' => $pending,
            'token' => $token,
        ]);
    }

    /** @param array<int, UploadedFile> $photos */
    private function submissionHash(array $validated, array $photos): string
    {
        $photoHashes = [];
        foreach ($photos as $photo) {
            $photoHashes[] = hash_file('sha256', $photo->getRealPath());
        }

        return hash('sha256', json_encode([
            'titulo' => $validated['titulo'],
            'descripcion' => $validated['descripcion'],
            'severidad' => $validated['severidad'],
            'recomendacion' => $validated['recomendacion'] ?? null,
            'photos' => $photoHashes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<int, UploadedFile> $photos */
    private function submissionMatches(
        Diagnostico $diagnostico,
        array $validated,
        string $submissionHash,
        array $photos,
    ): bool {
        if ($diagnostico->envio_hash !== null) {
            return hash_equals((string) $diagnostico->envio_hash, $submissionHash);
        }

        if ($photos !== []) {
            return false;
        }

        $textHash = hash('sha256', json_encode([
            'titulo' => $diagnostico->titulo,
            'descripcion' => $diagnostico->descripcion,
            'severidad' => $diagnostico->severidad,
            'recomendacion' => $diagnostico->recomendacion,
            'photos' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $matches = hash_equals($textHash, $submissionHash)
            || (
                $diagnostico->titulo === $validated['titulo']
                && $diagnostico->descripcion === $validated['descripcion']
                && $diagnostico->severidad === $validated['severidad']
                && ($diagnostico->recomendacion ?? null) === ($validated['recomendacion'] ?? null)
            );

        if ($matches && $diagnostico->envio_hash === null) {
            $diagnostico->forceFill(['envio_hash' => $submissionHash])->saveQuietly();
        }

        return $matches;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo;

        return $exception->getCode() === '23000'
            && (($errorInfo[1] ?? null) === 1062 || str_contains((string) ($errorInfo[2] ?? ''), 'Duplicate entry'));
    }

    private function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    /** @param array<int, UploadedFile> $photos @param array<int, string> $storedPaths */
    private function storeDiagnosticPhotos(
        OrdenServicio $orden,
        Diagnostico $diagnostico,
        array $photos,
        User $user,
        array &$storedPaths,
    ): void {
        $disk = Storage::disk('private');
        $directory = 'ordenes/'.$orden->id_orden.'/diagnosticos/'.$diagnostico->id_diagnostico;

        foreach ($photos as $photo) {
            $mimeType = $photo->getMimeType();
            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => throw ValidationException::withMessages(['fotografias' => 'Solo se admiten imágenes JPEG, PNG o WebP.']),
            };
            $internalName = Str::uuid().'.'.$extension;
            $expectedPath = $directory.'/'.$internalName;
            $path = $disk->putFileAs($directory, $photo, $internalName);
            if (! is_string($path) || $path !== $expectedPath) {
                throw new \RuntimeException('No se pudo guardar una fotografía diagnóstica.');
            }
            $storedPaths[] = $path;

            Evidencia::query()->create([
                'id_orden' => $orden->id_orden,
                'id_diagnostico' => $diagnostico->id_diagnostico,
                'id_usuario' => $user->getKey(),
                'tipo_archivo' => 'imagen',
                'etapa' => Evidencia::ETAPA_DIAGNOSTICO,
                'nombre_archivo' => $this->safeOriginalFilename($photo),
                'ruta_archivo' => $path,
                'tipo_mime' => $mimeType,
                'descripcion' => null,
                'visible_cliente' => false,
            ]);
        }
    }

    private function safeOriginalFilename(UploadedFile $photo): string
    {
        $name = basename(str_replace('\\', '/', $photo->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim(mb_substr($name, 0, 255));

        return $name !== '' ? $name : 'fotografia';
    }

    /** @param array<int, string> $storedPaths */
    private function removeStoredFiles(array $storedPaths): void
    {
        if ($storedPaths !== []) {
            Storage::disk('private')->delete($storedPaths);
        }
    }
}

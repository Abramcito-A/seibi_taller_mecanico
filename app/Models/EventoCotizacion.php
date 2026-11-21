<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class EventoCotizacion extends Model
{
    /**
     * Tipos de evento de la bitácora y su etiqueta para el panel interno.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'enviada' => 'Cotización enviada',
        'reenviada' => 'Cotización reenviada',
        'envio_aceptado' => 'Correo aceptado por el servidor SMTP',
        'envio_fallido' => 'Fallo al enviar el correo',
        'consultada' => 'Consultada por el cliente',
        'autorizada' => 'Autorizada por el cliente',
        'rechazada' => 'Rechazada por el cliente',
        'vencida' => 'Enlace vencido',
        'sustituida' => 'Sustituida por una nueva versión',
        'revocada' => 'Acceso revocado',
        'version_creada' => 'Nueva versión creada',
        'seguimiento_registrado' => 'Seguimiento del mecánico',
        'integridad_invalida' => 'Contenido alterado: autorización bloqueada',
    ];

    /**
     * Acciones de seguimiento que registra el mecánico tras conocer la decisión del cliente.
     *
     * @var array<string, string>
     */
    public const FOLLOW_UP_ACTIONS = [
        'continuar_autorizados' => 'Continuar con los conceptos autorizados',
        'contactar_cliente' => 'Contactar al cliente para aclarar la decisión',
        'detener_nueva_version' => 'Detener el trabajo y preparar una nueva versión',
    ];

    protected $table = 'eventos_cotizacion';

    protected $primaryKey = 'id_evento';

    public $timestamps = false;

    protected $fillable = ['id_cotizacion', 'id_acceso', 'id_usuario', 'tipo', 'datos', 'ip', 'user_agent', 'fecha'];

    protected function casts(): array
    {
        return ['datos' => 'array', 'fecha' => 'datetime'];
    }

    /**
     * Registra un evento. Los datos nunca deben incluir el token del enlace privado.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function record(
        Cotizacion $cotizacion,
        string $type,
        ?array $data = null,
        ?int $userId = null,
        ?int $accessId = null,
        ?Request $request = null,
    ): self {
        return self::query()->create([
            'id_cotizacion' => $cotizacion->id_cotizacion,
            'id_acceso' => $accessId,
            'id_usuario' => $userId,
            'tipo' => $type,
            'datos' => $data,
            'ip' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'fecha' => now(),
        ]);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->tipo] ?? ucfirst(str_replace('_', ' ', $this->tipo));
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'id_cotizacion', 'id_cotizacion');
    }

    public function acceso(): BelongsTo
    {
        return $this->belongsTo(AccesoCliente::class, 'id_acceso', 'id_acceso');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}

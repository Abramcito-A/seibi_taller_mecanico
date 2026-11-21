<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccesoCliente extends Model
{
    use HasFactory;

    /**
     * Token de la ruta pública: 32 bytes aleatorios en base64url sin relleno (43 caracteres).
     */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    protected $table = 'accesos_cliente';

    protected $primaryKey = 'id_acceso';

    public $timestamps = false;

    /**
     * Solo se guarda el hash SHA-256 del token; el token original existe únicamente en el enlace del correo.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    protected $fillable = [
        'id_orden', 'id_cotizacion', 'id_usuario', 'correo_destino', 'token_hash',
        'fecha_expiracion', 'revocado', 'ultimo_acceso',
    ];

    protected function casts(): array
    {
        return [
            'revocado' => 'boolean', 'fecha_creacion' => 'datetime',
            'fecha_expiracion' => 'datetime', 'ultimo_acceso' => 'datetime',
        ];
    }

    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function hasValidTokenFormat(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    public function isExpired(): bool
    {
        return $this->fecha_expiracion === null || $this->fecha_expiracion->isPast();
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class, 'id_orden', 'id_orden');
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'id_cotizacion', 'id_cotizacion');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}

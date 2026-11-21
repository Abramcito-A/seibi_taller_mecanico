<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Liga cada acceso privado a la cotización enviada, al usuario interno que la envió y al correo de destino.
     * Se conserva id_orden por compatibilidad; el token sigue guardándose solo como hash SHA-256.
     */
    public function up(): void
    {
        Schema::table('accesos_cliente', function (Blueprint $table) {
            $table->foreignId('id_cotizacion')
                ->nullable()
                ->after('id_orden')
                ->constrained('cotizaciones', 'id_cotizacion')
                ->restrictOnDelete();
            $table->foreignId('id_usuario')->nullable()->after('id_cotizacion')->constrained('users')->nullOnDelete();
            $table->string('correo_destino', 150)->nullable()->after('id_usuario');
            $table->index(['id_cotizacion', 'revocado']);
        });
    }

    public function down(): void
    {
        Schema::table('accesos_cliente', function (Blueprint $table) {
            $table->dropForeign(['id_cotizacion']);
            $table->dropForeign(['id_usuario']);
            $table->dropIndex(['id_cotizacion', 'revocado']);
            $table->dropColumn(['id_cotizacion', 'id_usuario', 'correo_destino']);
        });
    }
};

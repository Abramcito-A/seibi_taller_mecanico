<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidencia de la oferta original para verificar un enlace recuperado:
     * moneda_original / precio_moneda_original: precio publicado en moneda extranjera (alternative_price de Google
     * Shopping) cuando el comercio no publica en MXN; titulo_proveedor: título exacto de la publicación.
     */
    public function up(): void
    {
        Schema::table('detalles_cotizacion', function (Blueprint $table) {
            $table->char('moneda_original', 3)->nullable()->after('precio_proveedor');
            $table->decimal('precio_moneda_original', 12, 2)->nullable()->after('moneda_original');
            $table->string('titulo_proveedor', 255)->nullable()->after('precio_moneda_original');
        });
    }

    public function down(): void
    {
        Schema::table('detalles_cotizacion', function (Blueprint $table) {
            $table->dropColumn(['moneda_original', 'precio_moneda_original', 'titulo_proveedor']);
        });
    }
};

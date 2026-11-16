<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Token de la oferta exacta (immersive_product_page_token) que entregó la búsqueda. Permite reintentar la
     * recuperación del enlace directo; en pruebas reales el product_id dejó de devolver resultados y el token no.
     */
    public function up(): void
    {
        Schema::table('detalles_cotizacion', function (Blueprint $table) {
            $table->text('proveedor_token')->nullable()->after('proveedor_producto_id');
        });
    }

    public function down(): void
    {
        Schema::table('detalles_cotizacion', function (Blueprint $table) {
            $table->dropColumn('proveedor_token');
        });
    }
};

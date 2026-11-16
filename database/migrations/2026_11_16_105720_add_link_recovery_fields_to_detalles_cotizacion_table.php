<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * precio_proveedor: precio de la oferta al agregarla (el mecánico puede cambiar precio_unitario después);
     * se usa para verificar que un enlace recuperado pertenece a la misma oferta.
     * enlace_estado / enlace_motivo / enlace_verificado_en: resultado del último intento de recuperar la URL directa.
     */
    public function up(): void
    {
        Schema::table('detalles_cotizacion', function (Blueprint $table) {
            $table->decimal('precio_proveedor', 10, 2)->nullable()->after('precio_unitario');
            $table->string('enlace_estado', 30)->nullable()->after('url_shopping');
            $table->string('enlace_motivo', 255)->nullable()->after('enlace_estado');
            $table->dateTime('enlace_verificado_en')->nullable()->after('enlace_motivo');
        });
    }

    public function down(): void
    {
        Schema::table('detalles_cotizacion', function (Blueprint $table) {
            $table->dropColumn(['precio_proveedor', 'enlace_estado', 'enlace_motivo', 'enlace_verificado_en']);
        });
    }
};

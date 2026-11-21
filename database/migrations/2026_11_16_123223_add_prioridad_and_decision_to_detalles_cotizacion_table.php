<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * prioridad: clasificación que asigna el mecánico (vacía mientras la cotización es borrador).
     * decision_cliente / riesgo_confirmado_en: respuesta del cliente por concepto y la confirmación
     * explícita de la advertencia cuando rechaza un concepto necesario.
     */
    public function up(): void
    {
        Schema::table('detalles_cotizacion', function (Blueprint $table) {
            $table->enum('prioridad', ['necesario', 'recomendado', 'opcional'])->nullable()->after('tipo_concepto');
            $table->enum('decision_cliente', ['pendiente', 'aceptado', 'rechazado'])->default('pendiente')->after('importe');
            $table->dateTime('riesgo_confirmado_en')->nullable()->after('decision_cliente');
        });
    }

    public function down(): void
    {
        Schema::table('detalles_cotizacion', function (Blueprint $table) {
            $table->dropColumn(['prioridad', 'decision_cliente', 'riesgo_confirmado_en']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relaciona las fotografías de la etapa de reparación con la actualización del seguimiento que las incluye.
     * La visibilidad sigue controlándose con visible_cliente; los archivos permanecen en el disco privado.
     */
    public function up(): void
    {
        Schema::table('evidencias', function (Blueprint $table) {
            $table->unsignedBigInteger('id_actualizacion')->nullable()->after('id_diagnostico');
            $table->foreign('id_actualizacion')->references('id_actualizacion')->on('actualizaciones_reparacion')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evidencias', function (Blueprint $table) {
            $table->dropForeign(['id_actualizacion']);
            $table->dropColumn('id_actualizacion');
        });
    }
};

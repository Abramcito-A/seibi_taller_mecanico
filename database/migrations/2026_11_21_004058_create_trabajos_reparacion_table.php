<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un trabajo de reparación por cada concepto que el cliente aceptó en una versión exacta de la cotización.
     * Conserva una copia de la descripción, cantidad, tipo y prioridad (sin precios) para que el seguimiento
     * no dependa de cambios posteriores. id_detalle es único: reprocesar la autorización no genera duplicados.
     */
    public function up(): void
    {
        Schema::create('trabajos_reparacion', function (Blueprint $table) {
            $table->bigIncrements('id_trabajo');
            $table->foreignId('id_orden')->constrained('ordenes_servicio', 'id_orden')->restrictOnDelete();
            $table->foreignId('id_cotizacion')->constrained('cotizaciones', 'id_cotizacion')->restrictOnDelete();
            $table->unsignedBigInteger('id_detalle')->unique();
            $table->foreign('id_detalle')->references('id_detalle')->on('detalles_cotizacion')->restrictOnDelete();
            $table->unsignedInteger('version_cotizacion');
            $table->enum('tipo_concepto', ['pieza', 'servicio', 'mano_obra', 'otro'])->nullable();
            $table->string('descripcion', 255);
            $table->decimal('cantidad', 10, 2);
            $table->enum('prioridad', ['necesario', 'recomendado', 'opcional'])->nullable();
            $table->enum('estado', ['pendiente', 'en_proceso', 'en_espera', 'completado', 'no_realizado'])->default('pendiente');
            $table->dateTime('fecha_autorizacion');
            $table->dateTime('fecha_inicio')->nullable();
            $table->dateTime('fecha_finalizacion')->nullable();
            $table->foreignId('id_usuario_responsable')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->nullable();
            $table->index(['id_orden', 'estado']);
            $table->index(['id_cotizacion', 'version_cotizacion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trabajos_reparacion');
    }
};

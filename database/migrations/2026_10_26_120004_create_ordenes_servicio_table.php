<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_servicio', function (Blueprint $table) {
            $table->bigIncrements('id_orden');
            $table->foreignId('id_vehiculo')->constrained('vehiculos', 'id_vehiculo')->restrictOnDelete();
            $table->foreignId('id_usuario')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('id_orden_origen')->nullable();
            $table->foreign('id_orden_origen')->references('id_orden')->on('ordenes_servicio')->nullOnDelete();
            $table->string('folio', 30)->unique();
            $table->enum('tipo_orden', ['normal', 'garantia'])->default('normal');
            $table->dateTime('fecha_ingreso')->useCurrent();
            $table->unsignedInteger('kilometraje_ingreso')->nullable();
            $table->text('descripcion_problema');
            $table->text('observaciones')->nullable();
            $table->enum('estado', [
                'recibida', 'en_diagnostico', 'esperando_aprobacion', 'esperando_refaccion',
                'en_reparacion', 'lista_entrega', 'entregada', 'cerrada', 'cancelada',
            ])->default('recibida');
            $table->dateTime('fecha_cierre')->nullable();
            $table->dateTime('fecha_actualizacion')->nullable();
            $table->index(['estado', 'fecha_ingreso']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_servicio');
    }
};

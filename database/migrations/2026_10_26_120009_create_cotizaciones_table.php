<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->bigIncrements('id_cotizacion');
            $table->foreignId('id_orden')->constrained('ordenes_servicio', 'id_orden')->restrictOnDelete();
            $table->foreignId('id_usuario')->constrained('users')->restrictOnDelete();
            $table->foreignId('id_plantilla')->nullable()->constrained('plantillas_servicio', 'id_plantilla')->nullOnDelete();
            $table->string('folio', 30)->unique();
            $table->string('titulo', 150);
            $table->enum('tipo', ['mantenimiento', 'reparacion', 'diagnostico', 'otro']);
            $table->text('descripcion')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0.00);
            $table->decimal('total', 10, 2)->default(0.00);
            $table->enum('estado', ['borrador', 'enviada', 'aprobada', 'rechazada', 'cancelada', 'vencida'])->default('borrador');
            $table->enum('decision_cliente', ['pendiente', 'aprobada', 'rechazada'])->default('pendiente');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_envio')->nullable();
            $table->dateTime('fecha_decision')->nullable();
            $table->text('observaciones')->nullable();
            $table->dateTime('fecha_actualizacion')->nullable();
            $table->index(['id_orden', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizaciones');
    }
};

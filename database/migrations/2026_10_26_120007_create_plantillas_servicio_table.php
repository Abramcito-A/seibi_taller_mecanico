<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantillas_servicio', function (Blueprint $table) {
            $table->bigIncrements('id_plantilla');
            $table->string('nombre', 150);
            $table->enum('tipo_servicio', ['mantenimiento', 'reparacion', 'diagnostico', 'otro']);
            $table->text('descripcion')->nullable();
            $table->boolean('activa')->default(true);
            $table->foreignId('id_usuario')->constrained('users')->restrictOnDelete();
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantillas_servicio');
    }
};

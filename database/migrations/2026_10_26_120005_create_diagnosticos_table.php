<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosticos', function (Blueprint $table) {
            $table->bigIncrements('id_diagnostico');
            $table->foreignId('id_orden')->constrained('ordenes_servicio', 'id_orden')->restrictOnDelete();
            $table->foreignId('id_usuario')->constrained('users')->restrictOnDelete();
            $table->string('titulo', 150);
            $table->text('descripcion');
            $table->enum('severidad', ['baja', 'media', 'alta', 'critica'])->default('media');
            $table->text('recomendacion')->nullable();
            $table->dateTime('fecha_diagnostico')->useCurrent();
            $table->dateTime('fecha_actualizacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosticos');
    }
};

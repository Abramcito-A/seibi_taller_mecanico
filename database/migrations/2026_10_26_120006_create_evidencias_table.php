<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidencias', function (Blueprint $table) {
            $table->bigIncrements('id_evidencia');
            $table->foreignId('id_orden')->constrained('ordenes_servicio', 'id_orden')->restrictOnDelete();
            $table->foreignId('id_diagnostico')->nullable()->constrained('diagnosticos', 'id_diagnostico')->nullOnDelete();
            $table->foreignId('id_usuario')->constrained('users')->restrictOnDelete();
            $table->enum('tipo_archivo', ['imagen', 'documento'])->default('imagen');
            $table->string('nombre_archivo', 255);
            $table->string('ruta_archivo', 500);
            $table->string('tipo_mime', 100)->nullable();
            $table->string('descripcion', 255)->nullable();
            $table->dateTime('fecha_creacion')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidencias');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehiculos', function (Blueprint $table) {
            $table->bigIncrements('id_vehiculo');
            $table->foreignId('id_cliente')->constrained('clientes', 'id_cliente')->restrictOnDelete();
            $table->string('marca', 60);
            $table->string('modelo', 80);
            $table->year('anio');
            $table->string('version', 100)->nullable();
            $table->string('placas', 15)->nullable();
            $table->string('numero_serie', 50)->nullable();
            $table->unsignedInteger('kilometraje_actual')->nullable();
            $table->text('notas')->nullable();
            $table->dateTime('fecha_registro')->useCurrent();
            $table->dateTime('fecha_actualizacion')->nullable();
            $table->index(['marca', 'modelo', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehiculos');
    }
};

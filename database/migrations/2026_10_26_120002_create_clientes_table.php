<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->bigIncrements('id_cliente');
            $table->string('nombre', 100);
            $table->string('apellido', 100);
            $table->string('telefono', 20)->index();
            $table->string('correo', 150)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->dateTime('fecha_registro')->useCurrent();
            $table->dateTime('fecha_actualizacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};

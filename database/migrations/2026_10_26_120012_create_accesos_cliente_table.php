<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accesos_cliente', function (Blueprint $table) {
            $table->bigIncrements('id_acceso');
            $table->foreignId('id_orden')->constrained('ordenes_servicio', 'id_orden')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_expiracion')->nullable();
            $table->boolean('revocado')->default(false);
            $table->dateTime('ultimo_acceso')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accesos_cliente');
    }
};

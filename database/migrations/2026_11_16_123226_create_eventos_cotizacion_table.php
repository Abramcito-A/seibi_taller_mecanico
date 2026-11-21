<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de la cotización (envíos, consultas, decisiones, vencimientos y seguimiento), independiente
     * de historial_estados, que pertenece al ciclo de vida de la orden.
     */
    public function up(): void
    {
        Schema::create('eventos_cotizacion', function (Blueprint $table) {
            $table->bigIncrements('id_evento');
            $table->foreignId('id_cotizacion')->constrained('cotizaciones', 'id_cotizacion')->restrictOnDelete();
            $table->unsignedBigInteger('id_acceso')->nullable();
            $table->foreign('id_acceso')->references('id_acceso')->on('accesos_cliente')->nullOnDelete();
            $table->foreignId('id_usuario')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 40);
            $table->json('datos')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('fecha')->useCurrent();
            $table->index(['id_cotizacion', 'fecha']);
            $table->index(['id_cotizacion', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_cotizacion');
    }
};

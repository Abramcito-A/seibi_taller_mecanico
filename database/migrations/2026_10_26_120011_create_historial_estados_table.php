<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_estados', function (Blueprint $table) {
            $table->bigIncrements('id_historial');
            $table->foreignId('id_orden')->constrained('ordenes_servicio', 'id_orden')->restrictOnDelete();
            $table->foreignId('id_usuario')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado_anterior', 40)->nullable();
            $table->string('estado_nuevo', 40);
            $table->text('comentario')->nullable();
            $table->dateTime('fecha_cambio')->useCurrent();
            $table->index(['id_orden', 'fecha_cambio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_estados');
    }
};

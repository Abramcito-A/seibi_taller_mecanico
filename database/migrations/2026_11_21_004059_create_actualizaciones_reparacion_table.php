<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora auditable del seguimiento: avances y cambios de estado de cada trabajo, correcciones de
     * publicaciones anteriores y la revisión final de la orden (id_trabajo vacío). Los registros no se editan
     * ni se eliminan; una publicación solo puede pasar de interna a visible para el cliente.
     */
    public function up(): void
    {
        Schema::create('actualizaciones_reparacion', function (Blueprint $table) {
            $table->bigIncrements('id_actualizacion');
            $table->foreignId('id_orden')->constrained('ordenes_servicio', 'id_orden')->restrictOnDelete();
            $table->unsignedBigInteger('id_trabajo')->nullable();
            $table->foreign('id_trabajo')->references('id_trabajo')->on('trabajos_reparacion')->restrictOnDelete();
            $table->foreignId('id_usuario')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('tipo', ['avance', 'correccion', 'revision_final'])->default('avance');
            $table->unsignedBigInteger('id_actualizacion_corregida')->nullable();
            $table->foreign('id_actualizacion_corregida')->references('id_actualizacion')->on('actualizaciones_reparacion')->restrictOnDelete();
            $table->string('titulo', 150);
            $table->text('descripcion')->nullable();
            $table->text('motivo')->nullable();
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20)->nullable();
            $table->boolean('visible_cliente')->default(false);
            $table->dateTime('fecha_publicacion')->nullable();
            $table->foreignId('id_usuario_publicacion')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->index(['id_trabajo', 'fecha_creacion']);
            $table->index(['id_orden', 'visible_cliente', 'fecha_publicacion'], 'act_rep_orden_visible_pub_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actualizaciones_reparacion');
    }
};

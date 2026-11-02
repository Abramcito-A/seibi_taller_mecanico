<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalles_plantilla', function (Blueprint $table) {
            $table->bigIncrements('id_detalle_plantilla');
            $table->foreignId('id_plantilla')->constrained('plantillas_servicio', 'id_plantilla')->cascadeOnDelete();
            $table->enum('tipo_concepto', ['pieza', 'servicio', 'mano_obra', 'otro']);
            $table->string('descripcion', 255);
            $table->decimal('cantidad', 10, 2)->default(1.00);
            $table->decimal('precio_unitario_sugerido', 10, 2)->default(0.00);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalles_plantilla');
    }
};

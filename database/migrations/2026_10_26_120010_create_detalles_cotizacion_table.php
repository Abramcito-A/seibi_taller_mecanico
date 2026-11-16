<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalles_cotizacion', function (Blueprint $table) {
            $table->bigIncrements('id_detalle');
            $table->foreignId('id_cotizacion')->constrained('cotizaciones', 'id_cotizacion')->cascadeOnDelete();
            $table->enum('tipo_concepto', ['pieza', 'servicio', 'mano_obra', 'otro']);
            $table->string('descripcion', 255);
            $table->decimal('cantidad', 10, 2)->default(1.00);
            $table->decimal('precio_unitario', 10, 2)->default(0.00);
            $table->decimal('importe', 11, 2)->default(0.00);
            $table->char('moneda', 3)->default('MXN');
            $table->string('tienda_origen', 150)->nullable();
            $table->string('marca', 100)->nullable();
            $table->string('numero_parte', 100)->nullable();
            $table->text('url_origen')->nullable();
            $table->text('imagen_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalles_cotizacion');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ordenes_servicio', function (Blueprint $table): void {
            $table->enum('estado', [
                'recibida',
                'en_diagnostico',
                'esperando_aprobacion',
                'esperando_refaccion',
                'esperando_refacciones',
                'en_reparacion',
                'lista_entrega',
                'entregada',
                'cerrada',
                'cancelada',
            ])->default('recibida')->change();
        });

        Schema::table('evidencias', function (Blueprint $table): void {
            $table->string('etapa', 40)->default('recepcion')->after('tipo_archivo');
            $table->boolean('visible_cliente')->default(false)->after('descripcion');
            $table->index(['id_orden', 'etapa']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ordenes_servicio')
            ->where('estado', 'esperando_refacciones')
            ->update(['estado' => 'esperando_refaccion']);

        Schema::table('ordenes_servicio', function (Blueprint $table): void {
            $table->enum('estado', [
                'recibida',
                'en_diagnostico',
                'esperando_aprobacion',
                'esperando_refaccion',
                'en_reparacion',
                'lista_entrega',
                'entregada',
                'cerrada',
                'cancelada',
            ])->default('recibida')->change();
        });

        Schema::table('evidencias', function (Blueprint $table): void {
            $table->dropIndex('evidencias_id_orden_etapa_index');
            $table->dropColumn(['etapa', 'visible_cliente']);
        });
    }
};

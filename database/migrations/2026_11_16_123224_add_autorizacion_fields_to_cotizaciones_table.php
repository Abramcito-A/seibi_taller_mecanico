<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estados para la autorización parcial y la sustitución por una nueva versión, vigencia del enlace,
     * importe autorizado por el cliente y huella SHA-256 del contenido comercial enviado.
     */
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->enum('estado', [
                'borrador', 'enviada', 'aprobada', 'parcialmente_aprobada', 'rechazada', 'cancelada', 'vencida', 'sustituida',
            ])->default('borrador')->change();
            $table->enum('decision_cliente', ['pendiente', 'aprobada', 'parcial', 'rechazada'])->default('pendiente')->change();
            $table->decimal('importe_autorizado', 10, 2)->nullable()->after('total');
            $table->dateTime('fecha_vencimiento')->nullable()->after('fecha_envio');
            $table->char('huella_contenido', 64)->nullable()->after('id_cotizacion_origen');
            $table->index(['estado', 'fecha_vencimiento']);
        });
    }

    /**
     * Para poder restringir de nuevo los enum, los valores nuevos se convierten al valor previo más cercano.
     */
    public function down(): void
    {
        DB::table('cotizaciones')->where('estado', 'parcialmente_aprobada')->update(['estado' => 'aprobada']);
        DB::table('cotizaciones')->where('estado', 'sustituida')->update(['estado' => 'cancelada']);
        DB::table('cotizaciones')->where('decision_cliente', 'parcial')->update(['decision_cliente' => 'aprobada']);

        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropIndex(['estado', 'fecha_vencimiento']);
            $table->dropColumn(['importe_autorizado', 'fecha_vencimiento', 'huella_contenido']);
            $table->enum('estado', ['borrador', 'enviada', 'aprobada', 'rechazada', 'cancelada', 'vencida'])->default('borrador')->change();
            $table->enum('decision_cliente', ['pendiente', 'aprobada', 'rechazada'])->default('pendiente')->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * revision: contador de ediciones internas del borrador (control de concurrencia optimista).
     * version: número de versión visible para el cliente; una cotización enviada nunca se edita,
     * se crea una nueva versión que apunta a la original mediante id_cotizacion_origen.
     */
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1)->after('estado');
            $table->unsignedInteger('version')->default(1)->after('revision');
            $table->foreignId('id_cotizacion_origen')
                ->nullable()
                ->after('version')
                ->constrained('cotizaciones', 'id_cotizacion')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_cotizacion_origen');
            $table->dropColumn(['revision', 'version']);
        });
    }
};

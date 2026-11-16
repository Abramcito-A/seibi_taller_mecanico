<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('detalles_cotizacion', function (Blueprint $table) {
            $table->string('fabricante', 150)->nullable()->after('marca');
            $table->text('url_shopping')->nullable()->after('url_origen');
            $table->string('proveedor', 50)->nullable()->after('imagen_url');
            $table->string('proveedor_motor', 50)->nullable()->after('proveedor');
            $table->string('proveedor_producto_id', 100)->nullable()->after('proveedor_motor');
            $table->json('detalles_tecnicos')->nullable()->after('proveedor_producto_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('detalles_cotizacion', function (Blueprint $table) {
            $table->dropColumn([
                'fabricante',
                'url_shopping',
                'proveedor',
                'proveedor_motor',
                'proveedor_producto_id',
                'detalles_tecnicos',
            ]);
        });
    }
};

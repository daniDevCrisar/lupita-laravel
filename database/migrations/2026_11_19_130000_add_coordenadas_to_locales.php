<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coordenadas geograficas de cada local/planta.
 *
 * Mismo tipo y mismas cinco columnas que `geo_coordenadas` (la tabla de ubigeo),
 * con prefijo `geo_` en las tres de metadata para no ensuciar `locales`.
 *
 * POR QUE DECIMAL(9,6): es exactamente el tipo de `geo_coordenadas`, asi los dos
 * origenes de coordenadas del sistema se comparan sin convertir nada. Da 3 enteros
 * y 6 decimales; Peru va de lat -0,04 a -18,45 y lng -68,65 a -81,33. Google Maps
 * devuelve 7 decimales: redondear a 6 mueve el punto ~11 cm, nada para tiempos.
 *
 * POR QUE EN `locales` Y NO EN UNA TABLA APARTE: es 1:1 con el local y lo consultan
 * tiempos, plantas, reportes y prediccion; una tabla aparte obligaria a un JOIN en
 * cada una.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locales', function (Blueprint $table) {
            $table->decimal('lat', 9, 6)->nullable()->after('distrito_ubigeo')
                ->comment('latitud -90..90');
            $table->decimal('lng', 9, 6)->nullable()->after('lat')
                ->comment('longitud -180..180');
            $table->string('geo_texto', 200)->nullable()->after('lng')
                ->comment('nombre o direccion que trajo el link de Maps');
            $table->string('geo_fuente', 20)->nullable()->after('geo_texto')
                ->comment('maps | manual | distrito');
            $table->timestamp('geo_actualizado_en')->nullable()->after('geo_fuente');
        });
    }

    public function down(): void
    {
        Schema::table('locales', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng', 'geo_texto', 'geo_fuente', 'geo_actualizado_en']);
        });
    }
};

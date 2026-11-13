<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coordenadas del centro de cada distrito (ubigeo de 6 dígitos).
     *
     * Se guardan porque son un dato estable y geocodificar en cada request sería
     * lento y gastaría cuota; se llenan solas la primera vez que se necesitan
     * (ver `App\Prediccion\Geoapify::coord_ubigeo()`). Los TIEMPOS entre ubigeos
     * no se guardan: se piden en vivo cuando no hay tránsito medido.
     */
    public function up(): void
    {
        Schema::create('geo_coordenadas', function (Blueprint $table) {
            $table->string('ubigeo', 6)->primary();
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);
            $table->string('texto', 200)->nullable();
            $table->string('fuente', 20)->default('geoapify');
            $table->timestamp('actualizado_en')->nullable()->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_coordenadas');
    }
};

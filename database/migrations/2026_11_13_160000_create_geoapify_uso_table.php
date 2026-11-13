<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contador de llamadas a Geoapify: una fila por día y por endpoint.
     *
     * Existe para poder responder "¿cuántas veces se usó el API?" sin entrar al
     * panel de Geoapify: cada petición HTTP (también la que falla) suma uno acá
     * desde App\Prediccion\Geoapify, y la pantalla
     * /lupita/prediccion/geoapify lo muestra.
     *
     * Si la tabla no existe, la predicción funciona igual: el contador falla en
     * silencio (ver App\Database\DBGeoapify::registrar()).
     */
    public function up(): void
    {
        Schema::create('geoapify_uso', function (Blueprint $table) {
            $table->date('fecha');
            $table->string('tipo', 20);              // geocode | routing
            $table->unsignedInteger('llamadas')->default(0);
            $table->unsignedInteger('exitosas')->default(0);
            $table->unsignedInteger('fallidas')->default(0);
            $table->dateTime('actualizado_en')->nullable();

            $table->primary(['fecha', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geoapify_uso');
    }
};

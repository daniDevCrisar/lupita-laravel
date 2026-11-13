<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parámetros del motor de predicción de carga, editables desde la pantalla
     * (antes eran constantes en el código).
     *
     * La clase `App\Prediccion\Parametros` los lee una vez por request y, si la
     * tabla todavía no existe en el servidor (aquí no hay consola y el
     * `CREATE TABLE` se aplica a mano), usa los mismos valores por defecto de
     * `config/prediccion.php`: predecir nunca revienta por configuración.
     *
     * Los valores iniciales salen de esa config para que migración y config no
     * puedan divergir.
     */
    public function up(): void
    {
        Schema::create('prediccion_config', function (Blueprint $table) {
            $table->string('clave', 32)->primary();
            $table->string('valor', 32);
            $table->timestamp('updated_at')->nullable()->useCurrent();
        });

        $filas = [];
        foreach ((array) config('prediccion', []) as $clave => $valor) {
            $filas[] = ['clave' => (string) $clave, 'valor' => (string) $valor, 'updated_at' => now()];
        }

        if ($filas !== []) {
            DB::table('prediccion_config')->insert($filas);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prediccion_config');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Devuelve las banderas de las reglas al dominio real: 1 / 0 / vacio.
 *
 * QUE PASABA
 * La migracion `2026_11_20_140000_normaliza_banderas_en_reglas` habia pasado
 * `1` / `0` a `true` / `false` para que el desplegable mostrara el valor sin
 * "fuera de la lista". Pero las columnas de destino en `llamadas`
 * (`error_tecnico_llamada`, `conductor_cuelga`, `conductor_no_contesta`,
 * `entro_llamada`...) son **tinyint(1)**: el valor valido es 1, 0 o vacio.
 * `DBTmpLlamadas::etiqueta_valor()` tolera `true`/`false` y los convierte, pero
 * guardar un texto en una columna numerica depende de esa tolerancia: si algun
 * dia una de esas columnas se escribe cruda (como `entro_llamada`), el INSERT
 * falla con "Incorrect integer value" por el modo estricto de MySQL.
 *
 * Asi que el dominio queda donde corresponde: `1` / `0` / vacio, y "si"/"no"
 * tambien se traducen por si alguien los escribio a mano.
 *
 * OJO: las comparaciones de texto aqui son insensibles a mayusculas por la
 * colacion (`utf8mb4_*_ci`), que es justo lo que queremos: cubre `true`, `TRUE`
 * y `True` con un solo WHERE.
 */
return new class extends Migration
{
    /** Columnas de bandera cuyo valor de destino debe ser 1 / 0 / vacio. */
    private const BANDERAS = [
        'error_tecnico_llamada',
        'conductor_cuelga',
        'conductor_no_contesta',
        'entro_llamada',
    ];

    public function up(): void
    {
        DB::table('elevenlabs_mapeos_regla')
            ->whereIn('campo_destino', self::BANDERAS)
            ->whereIn('valor_destino', ['true', 'si'])
            ->update(['valor_destino' => '1', 'updated_at' => now()]);

        DB::table('elevenlabs_mapeos_regla')
            ->whereIn('campo_destino', self::BANDERAS)
            ->whereIn('valor_destino', ['false', 'no'])
            ->update(['valor_destino' => '0', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Sin vuelta atras: `true`/`false` no era el dominio correcto y no hay
        // forma de distinguir que filas lo tenian antes de esta migracion.
    }
};

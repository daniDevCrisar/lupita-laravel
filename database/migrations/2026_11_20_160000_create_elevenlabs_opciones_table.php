<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opciones del modulo Eleven Labs que se cambian desde la pantalla (Etapa 6).
 *
 * POR QUE UNA TABLA Y NO UN `config`
 * El pedido del modulo fue configurar todo desde la UI, sin editar codigo ni
 * volver a desplegar. Un `config/services.php` obligaria a tocar el repositorio
 * cada vez que logistica decida que estados entran al lote.
 *
 * QUE GUARDA HOY
 *  - `estados_a_importar`: lista separada por comas de los `status` de
 *    ElevenLabs que entran al lote. Antes estaba fijo: se saltaba `initiated` y
 *    nada mas. Medido en la cuenta: `done` 86, `initiated` 6, `failed` 8.
 *    Con `initiated` dentro, esas 6 llamadas entrarian SIN transcripcion ni
 *    analisis (la conversacion aun no termina), asi que el valor por defecto es
 *    `done,failed`.
 *
 * La clave es `clave` (una fila por opcion) para que agregar opciones nuevas no
 * exija otra migracion: basta con insertar la fila y dibujarla en la vista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elevenlabs_opciones', function (Blueprint $table) {
            $table->string('clave', 64)->primary();

            $table->string('valor', 255)->default('')
                ->comment('valor de la opcion; en las listas, valores separados por coma');

            $table->string('etiqueta', 120)
                ->comment('nombre visible en la pantalla de Equivalencias');

            $table->string('ayuda', 255)->nullable()
                ->comment('texto de apoyo debajo de la opcion');

            $table->timestamps();
        });

        DB::table('elevenlabs_opciones')->insert([
            [
                'clave' => 'estados_a_importar',
                'valor' => 'done,failed',
                'etiqueta' => 'Estados que entran al lote',
                'ayuda' => 'Las llamadas con otro estado no se importan y aparecen como «estado fuera de la politica».',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('elevenlabs_opciones');
    }
};

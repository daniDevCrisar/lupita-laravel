<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra las equivalencias de traduccion ElevenLabs -> `tmp_lotes_det`.
 *
 * Deja la configuracion EXACTAMENTE como la hacia el flujo de VAPI a mano, asi la
 * pantalla de Equivalencias abre con algo que ya funciona y se corrige encima, en
 * vez de abrir vacia y obligar a configurar 51 columnas antes de poder importar.
 *
 * Idempotente: usa updateOrInsert por `campo_destino`, y las reglas por
 * (ruta_origen, comparacion, valor_origen, campo_destino). Se puede correr las
 * veces que haga falta sin duplicar y sin pisar lo que el usuario ya ajusto...
 * salvo que se pase --force, que reescribe los valores por defecto.
 *
 *   php artisan db:seed --class=ElevenLabsMapeoSeeder
 */
class ElevenLabsMapeoSeeder extends Seeder
{
    /** dv = conversation_initiation_client_data.dynamic_variables */
    private const DV = 'conversation_initiation_client_data.dynamic_variables.';

    /**
     * Varias rutas candidatas en una sola celda: se separan por salto de linea y
     * el resolvedor las prueba en orden (`ResolvedorMapeo::candidatas()`).
     */
    private function cadena(array $rutas): string
    {
        return implode("\n", $rutas);
    }

    public function run(): void
    {
        $ahora = now();

        foreach ($this->campos() as $orden => $fila) {
            DB::table('elevenlabs_mapeos_campo')->updateOrInsert(
                ['campo_destino' => $fila['campo_destino']],
                array_merge($fila, ['orden' => $orden, 'updated_at' => $ahora, 'created_at' => $ahora])
            );
        }

        // Los nombres de razon tienen que existir de verdad: obtener_id_razon()
        // compara exacto contra razones_finalizacion.name (sensible a mayusculas),
        // asi que un nombre inventado dejaria la llamada en DESCONOCIDO en silencio.
        $nombresValidos = DB::table('razones_finalizacion')->pluck('name')->all();

        // Solo se siembran reglas sobre columnas de tipo regla: una regla sobre una
        // columna que se lee por campo o por constante no la aplica el resolvedor.
        $columnasDeRegla = DB::table('elevenlabs_mapeos_campo')
            ->where('tipo_origen', 'regla')
            ->pluck('campo_destino')
            ->all();

        foreach ($this->reglas() as $fila) {
            if ($fila['campo_destino'] === 'razon_finalizacion'
                && ! in_array($fila['valor_destino'], $nombresValidos, true)) {
                $this->command?->warn(
                    'Regla ignorada: "' . $fila['valor_destino'] . '" no es un name de razones_finalizacion.'
                );

                continue;
            }

            if (! in_array($fila['campo_destino'], $columnasDeRegla, true)) {
                $this->command?->warn(
                    'Regla ignorada: "' . $fila['campo_destino'] . '" no es una columna de tipo regla.'
                );

                continue;
            }

            // La `clave` es el identificador estable: casar por la clave natural
            // (ruta + comparacion + valor + columna) duplicaba filas en cuanto
            // cambiaba uno de esos cuatro valores.
            //
            // Si la clave todavia no existe pero hay una fila heredada (sin clave)
            // con la misma clave natural, se ADOPTA en vez de insertar otra: es el
            // caso de la primera siembra despues de agregar la columna.
            $existente = DB::table('elevenlabs_mapeos_regla')->where('clave', $fila['clave'])->first();

            if (! $existente) {
                $heredada = DB::table('elevenlabs_mapeos_regla')
                    ->whereNull('clave')
                    ->where('ruta_origen', $fila['ruta_origen'])
                    ->where('comparacion', $fila['comparacion'])
                    ->where('campo_destino', $fila['campo_destino'])
                    ->where(fn ($q) => $fila['valor_origen'] === null
                        ? $q->whereNull('valor_origen')
                        : $q->where('valor_origen', $fila['valor_origen']))
                    ->orderBy('id')
                    ->first();

                if ($heredada) {
                    DB::table('elevenlabs_mapeos_regla')
                        ->where('id', $heredada->id)
                        ->update(['clave' => $fila['clave']]);
                }
            }

            DB::table('elevenlabs_mapeos_regla')->updateOrInsert(
                ['clave' => $fila['clave']],
                array_merge($fila, ['updated_at' => $ahora, 'created_at' => $ahora])
            );
        }

        // Limpieza: filas heredadas que quedaron duplicando una regla sembrada con
        // el MISMO destino. Se comparan los cinco datos para no tocar reglas que el
        // usuario creo con el mismo disparador y columna pero otro valor.
        foreach (DB::table('elevenlabs_mapeos_regla')->whereNotNull('clave')->get() as $sembrada) {
            DB::table('elevenlabs_mapeos_regla')
                ->whereNull('clave')
                ->where('ruta_origen', $sembrada->ruta_origen)
                ->where('comparacion', $sembrada->comparacion)
                ->where('campo_destino', $sembrada->campo_destino)
                ->where('valor_destino', $sembrada->valor_destino)
                ->where(fn ($q) => $sembrada->valor_origen === null
                    ? $q->whereNull('valor_origen')
                    : $q->where('valor_origen', $sembrada->valor_origen))
                ->delete();
        }
    }

    /**
     * Las 51 columnas de DBColumns::tmpLotesDet(), en el mismo orden.
     * Tipo 14 campo + 22 constante + 8 regla + 7 calculado.
     *
     * @return array<int, array<string, mixed>>
     */
    private function campos(): array
    {
        $campo = fn (string $destino, string $ruta, string $transf = 'ninguna', string $defecto = '', string $desc = '') => [
            'campo_destino' => $destino, 'tipo_origen' => 'campo', 'ruta_origen' => $ruta,
            'valor_constante' => null, 'transformacion' => $transf, 'valor_por_defecto' => $defecto,
            'descripcion' => $desc, 'activo' => true,
        ];

        $constante = fn (string $destino, string $valor = '', string $desc = 'Lo mandaba vacio el flujo de VAPI.') => [
            'campo_destino' => $destino, 'tipo_origen' => 'constante', 'ruta_origen' => null,
            'valor_constante' => $valor, 'transformacion' => 'ninguna', 'valor_por_defecto' => null,
            'descripcion' => $desc, 'activo' => true,
        ];

        $regla = fn (string $destino, string $desc) => [
            'campo_destino' => $destino, 'tipo_origen' => 'regla', 'ruta_origen' => null,
            'valor_constante' => null, 'transformacion' => 'ninguna', 'valor_por_defecto' => '',
            'descripcion' => $desc, 'activo' => true,
        ];

        $calculado = fn (string $destino, string $desc) => [
            'campo_destino' => $destino, 'tipo_origen' => 'calculado', 'ruta_origen' => null,
            'valor_constante' => null, 'transformacion' => 'ninguna', 'valor_por_defecto' => null,
            'descripcion' => $desc, 'activo' => true,
        ];

        return [
            $calculado('lote_id', 'Se genera al crear el lote.'),

            $calculado('vapi_id', 'Es el conversation_id de ElevenLabs. Se escribe aqui porque es la llave de llamadas, mensajes y scoring.'),

            $regla('type', 'Solo informativo: outboundPhoneCall (llamo la IA) o inboundPhoneCall (llamo el humano), segun metadata.phone_call.direction.'),

            $campo('created_at', 'metadata.start_time_unix_secs', 'segundos_a_ms', '',
                'El pipeline divide entre 1000 y luego aplica FROM_UNIXTIME.'),

            $constante('created_at_excel', '', 'Solo servia para el Excel de VAPI; aqui no se usa.'),

            $calculado('llamada_tipo', 'Sale del catalogo de asistentes (pestana Asistentes).'),

            // Los campos de negocio llevan CADENA de rutas candidatas: se prueban
            // en orden y gana la primera con valor. Asi el mismo campo sirve para
            // asistentes que nombran distinto la variable, y tambien para los
            // nombres que usaba VAPI (driver_name, vehicle_plate, ...).
            $campo('ref', $this->cadena([self::DV . 'reference', self::DV . 'trip_code',
                self::DV . 'referencia', self::DV . 'ref']), 'texto', '',
                'Candidatas en orden: reference, trip_code, referencia, ref.'),
            $campo('origen', $this->cadena([self::DV . 'origen', self::DV . 'origen_nombre', self::DV . 'desde'])),
            $campo('destino', $this->cadena([self::DV . 'destino', self::DV . 'destino_nombre', self::DV . 'hacia'])),
            $campo('telefono', $this->cadena([self::DV . 'telefono', self::DV . 'celular',
                self::DV . 'telefono_conductor', 'metadata.phone_call.external_number']),
                'telefono', '', 'Quita el + y todo lo que no sea digito.'),
            $campo('conductor', $this->cadena([self::DV . 'carrier_name', self::DV . 'nombre_transportista',
                self::DV . 'driver_name', self::DV . 'conductor']), 'mayusculas', '',
                'En inbound todas vienen vacias (pendiente la decision D2). No se usa `nombre`: en inbound trae la empresa.'),
            $campo('placa', $this->cadena([self::DV . 'placa', self::DV . 'vehicle_plate',
                self::DV . 'placa_vehiculo']), 'alfanumerico', '',
                'ElevenLabs todavia no la envia: las rutas quedan listas para cuando exista. Se normaliza a solo letras y numeros en mayusculas.'),
            $campo('fecha_prometida', $this->cadena([self::DV . 'fecha_carga_texto',
                self::DV . 'fecha_compromiso_carga', self::DV . 'fecha_cita_carga']), 'fecha_texto', '',
                'Texto natural -> dd/mm/aaaa HH:mm. Si una no se entiende, se pasa a la siguiente.'),

            $calculado('mensajes_conten', 'Se arma recorriendo transcript[] como "//BOT: ... //USER: ...".'),

            $calculado('audio', 'Ruta propia /eleven-labs/audio/{id}. El MP3 no se almacena.'),

            $campo('audio_duracion', 'metadata.call_duration_secs', 'numero'),
            $campo('costo', 'metadata.cost_fiat', 'numero', '', 'USD. Los creditos estan en metadata.cost.'),

            // El veredicto lo determina ElevenLabs (analysis.call_successful).
            // Solo se adapta el formato: el pipeline compara exacto con 'TRUE'.
            $campo('exitosa_segun_ia', 'analysis.call_successful', 'veredicto_ia', 'FALSE',
                'Lo determina ElevenLabs: success -> TRUE, cualquier otra cosa -> FALSE.'),

            $regla('entro_llamada', 'Se resuelve por call_result / motivo de fin.'),

            $regla('razon_finalizacion', 'El valor debe ser un name exacto de razones_finalizacion.'),

            $constante('razon_finalizacion_espanol', '', 'No se usa al importar.'),

            $campo('transportista', self::DV . 'operador_logistico', 'texto', '',
                'VAPI lo dejaba vacio; el TRT real lo resuelve el pipeline del lote.'),

            $campo('ia_result_comments_text', 'analysis.transcript_summary', 'truncar_255'),

            // ElevenLabs NO tiene equivalente de `delay_reason_desc` (esa columna la
            // llenaba VAPI desde `analysis.structuredData`). Se usa el titulo corto
            // del analisis, que es el dato corto mas parecido que manda la API.
            $campo('ia_result_delay_reason_desc', 'analysis.call_summary_title', 'truncar_255', '',
                'ElevenLabs no tiene "delay reason": se usa el titulo corto del analisis.'),

            $calculado('analisis_transcripcion', 'Analizador de transcripcion (buzon / NO HABLA / CUELGA).'),

            $constante('analisis_audio'),

            $constante('conductor_confirma'),

            // La enciende ElevenLabs en features_usage.voicemail_detection.used.
            // Es un campo, no una regla: el agente es quien detecta el buzon. El
            // valor sale como texto "true"/"false" y DBTmpLlamadas::etiqueta_valor()
            // lo traduce a 1/0 (ojo: en PHP el string "false" es verdadero).
            $campo('buzon_de_voz', 'metadata.features_usage.voicemail_detection.used',
                'texto_booleano', 'false',
                'ElevenLabs avisa si el agente detecto buzon. Valores: true / false.'),

            $calculado('conductor_contesta_pero_no_habla', 'Analizador de transcripcion.'),

            $constante('conductor_no_escucha'),
            $constante('conductor_da_motivos'),
            $constante('conductor_mala_senal'),
            $constante('confusion_en_llamada'),
            $constante('contesta_otra_persona'),
            $constante('numero_equivocado'),
            $constante('conversacion_fluida'),
            $constante('llamada_interesante'),

            $constante('ia_se_confunde'),
            $constante('ia_no_escucha'),
            $constante('ia_cambio_de_datos'),
            $constante('ia_error_interpretacion'),
            $constante('ia_dice_variable'),
            $constante('ia_mala_pronunciacion'),
            $constante('ia_cuelga_en_plena_llamada'),

            $regla('conductor_cuelga', 'Se resuelve por el motivo de fin.'),
            $regla('conductor_no_contesta', 'Se resuelve por el motivo de fin.'),

            $constante('conductor_conducta_inapropiada'),

            $regla('error_tecnico_llamada', 'Error de configuracion del agente (variables obligatorias faltantes).'),

            $constante('error_audio'),

            $regla('error_origen', 'Ojo: DBTmpLlamadas lo sobrescribe con la razon de finalizacion.'),

            $constante('llamada_exitosa'),
        ];
    }

    /**
     * Reglas iniciales. Medidas contra 100 llamadas reales y los 5 fixtures.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reglas(): array
    {
        $terminacion = 'metadata.termination_reason';
        $resultado = 'metadata.phone_call.call_result';
        $direccion = 'metadata.phone_call.direction';

        $r = fn (string $clave, int $prioridad, string $ruta, string $comparacion, ?string $valor, string $destino, string $resultadoValor, string $desc) => [
            'clave' => $clave,
            'ruta_origen' => $ruta, 'comparacion' => $comparacion, 'valor_origen' => $valor,
            'campo_destino' => $destino, 'valor_destino' => $resultadoValor,
            'prioridad' => $prioridad, 'descripcion' => $desc, 'activo' => true,
        ];

        return [
            // Direccion de la llamada. VAPI lo llamaba `type` con estos mismos
            // valores; ElevenLabs lo parte en direction = outbound | inbound.
            // Ojo: NO existe un `direction` de nivel raiz en el detalle, solo
            // dentro de metadata.phone_call (en el LISTADO si viene arriba).
            $r('direccion_outbound', 5, $direccion, 'igual', 'outbound',
                'type', 'outboundPhoneCall', 'Llamo la IA al conductor.'),
            $r('direccion_inbound', 6, $direccion, 'igual', 'inbound',
                'type', 'inboundPhoneCall', 'Llamo el conductor a la linea de la IA.'),

            // Fallo de configuracion del agente: NO es una razon, es una bandera.
            $r('error_agente_config', 10, $terminacion, 'contiene', 'Missing required dynamic variables',
                'error_tecnico_llamada', 'true', 'El agente no pudo arrancar por variables obligatorias faltantes.'),

            // Buzon detectado por la herramienta del agente: la bandera la resuelve
            // el campo `buzon_de_voz`; aqui solo se traduce el motivo de fin.
            $r('buzon_por_terminacion_razon', 21, $terminacion, 'contiene', 'voicemail_detection',
                'razon_finalizacion', 'CUSTOMER-DID-NOT-ANSWER', 'Un buzon equivale a que el conductor no contesto.'),

            // Motivos de fin normales.
            $r('fin_end_call', 30, $terminacion, 'igual', 'end_call tool was called.',
                'razon_finalizacion', 'ASSISTANT-ENDED-CALL', 'La IA decidio cerrar la llamada.'),
            $r('fin_remote_party', 40, $terminacion, 'igual', 'Call ended by remote party',
                'razon_finalizacion', 'CUSTOMER-ENDED-CALL', 'El conductor corto.'),

            // Resultado del marcado telefonico.
            $r('razon_por_call_result_voicemail', 50, $resultado, 'igual', 'voicemail',
                'razon_finalizacion', 'CUSTOMER-DID-NOT-ANSWER', 'Twilio reporta buzon: el conductor no contesto.'),
            $r('fin_busy', 60, $resultado, 'igual', 'busy',
                'razon_finalizacion', 'CUSTOMER-BUSY', 'Linea ocupada.'),
            $r('fin_no_answer', 70, $resultado, 'igual', 'no-answer',
                'razon_finalizacion', 'CUSTOMER-DID-NOT-ANSWER', 'No contesto.'),
            $r('entro_por_answered', 80, $resultado, 'igual', 'answered',
                'entro_llamada', '1', 'La llamada fue contestada.'),

            // Redes de seguridad. Pregunta legitima: "¿y si no se cumple ninguna
            // condicion?". Con la prioridad, estas dos cierran el caso para
            // razon_finalizacion: la 998 agarra cualquier motivo que NO haya
            // matcheado arriba y la 999 el motivo vacio. Asi la columna nunca
            // queda en blanco y siempre se obtiene un id de razon (UNKNOWN = 0
            // si el motivo es desconocido).
            $r('razon_no_reconocida', 998, $terminacion, 'no_vacio', null,
                'razon_finalizacion', 'UNKNOWN', 'Motivo de fin no reconocido: queda como DESCONOCIDO.'),
            $r('razon_vacia', 999, $terminacion, 'vacio', null,
                'razon_finalizacion', 'UNKNOWN', 'Sin motivo de finalizacion.'),
        ];
    }
}

<?php

namespace App\ElevenLabs;

/**
 * Arma `tmp_lotes_det.mensajes_conten` a partir del `transcript[]` de ElevenLabs.
 *
 * EL FORMATO NO ES LIBRE
 * El pipeline existente parte esa cadena en `DBTmpLlamadas::guardar_mensajes()`
 * (`explode('//')`, `explode(':')`) y en `procesarTranscripcion` del JS viejo.
 * Tiene que quedar exactamente como `//BOT: texto //USER: texto`:
 *   - separador de turno: `//`
 *   - separador rol/texto: `:`
 *   - roles: BOT (la IA) y USER (el conductor)
 *
 * DIFERENCIAS DELIBERADAS CON EL FLUJO DE VAPI
 * 1. VAPI mapeaba el rol `agent` de ElevenLabs a BOT. Aqui se hace igual, pero
 *    cualquier rol desconocido se descarta en vez de escribirse crudo (el
 *    pipeline espera un enum BOT/USER).
 * 2. El JS viejo se saltaba el PRIMER turno (`if (index > 0)`), o sea perdia el
 *    saludo. Aqui se conservan todos: el analizador solo mira el texto del
 *    conductor, asi que el saludo no altera el analisis y se gana informacion.
 * 3. El JS viejo cortaba el texto en el segundo `:` (`split(':')` + `[1]`), y
 *    `guardar_mensajes` descartaba el turno entero si habia mas de uno. Aqui el
 *    texto se conserva completo y se sanea el separador `//`.
 */
class MensajesTranscripcion
{
    /** Mapa de roles de ElevenLabs al enum que espera el pipeline. */
    private const ROLES = [
        'agent' => 'BOT',
        'assistant' => 'BOT',
        'bot' => 'BOT',
        'user' => 'USER',
        'human' => 'USER',
        'customer' => 'USER',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $transcript
     * @return array<int, array{rol: string, texto: string}>  solo turnos válidos
     */
    public function turnos(array $transcript): array
    {
        $turnos = [];

        foreach ($transcript as $turno) {
            $rolCrudo = mb_strtolower(trim((string) ($turno['role'] ?? '')));
            $rol = self::ROLES[$rolCrudo] ?? null;

            if ($rol === null) {
                continue;
            }

            // `message` viene truncado con "..." en las respuestas del agente;
            // `original_message` trae el completo cuando existe (en esta cuenta
            // suele venir null incluso en los turnos del agente).
            $texto = (string) (($turno['original_message'] ?? null) ?: ($turno['message'] ?? ''));
            $texto = $this->limpiarTexto($texto);

            // Un turno que solo ejecuta herramientas no aporta texto, pero se
            // conserva si trae algo que decir. Los vacios se omiten.
            if ($texto === '') {
                continue;
            }

            $turnos[] = ['rol' => $rol, 'texto' => $texto];
        }

        return $turnos;
    }

    /**
     * @param  array<int, array<string, mixed>>  $transcript
     */
    public function construir(array $transcript): string
    {
        $partes = [];

        foreach ($this->turnos($transcript) as $turno) {
            $partes[] = '//' . $turno['rol'] . ': ' . $turno['texto'];
        }

        // Sin turnos se devuelve cadena vacia: `guardar_mensajes` la recorre y no
        // inserta nada, que es lo correcto para una llamada sin transcripcion.
        return implode('', $partes);
    }

    /**
     * Deja el texto apto para el formato: sin saltos de linea (rompen el `//`),
     * sin el separador de turno y sin el separador rol/texto al inicio.
     */
    private function limpiarTexto(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $texto);
        $texto = preg_replace('/\s+/', ' ', $texto) ?? $texto;

        // `//` es el separador de turnos: dos barras dentro de un mensaje
        // partirian el turno en dos y el pipeline descartaria el trozo suelto.
        $texto = str_replace('//', '/ /', $texto);

        return trim($texto);
    }
}

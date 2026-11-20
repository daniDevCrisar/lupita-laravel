<?php

namespace App\ElevenLabs;

use Illuminate\Support\Facades\DB;

/**
 * Opciones del modulo Eleven Labs que se editan desde la pantalla (Etapa 6).
 *
 * Viven en `elevenlabs_opciones` (clave -> valor) para no volver a tocar codigo
 * cada vez que cambia una politica. Hoy hay una sola opcion: que estados de
 * llamada entran al lote.
 *
 * El valor se guarda como texto (`done,failed`) para que la tabla sirva tambien
 * para opciones de un solo valor sin agregar columnas; esta clase lo parte en
 * array y valida contra las opciones conocidas antes de guardar.
 */
class OpcionesElevenLabs
{
    /**
     * Opciones conocidas: que se puede elegir y como se dibuja.
     *
     * `opciones` son los valores permitidos (clave => etiqueta). Lo que no este
     * aqui no se guarda, y lo que se lea de la base se filtra: asi una fila
     * editada a mano en MySQL no rompe la importacion.
     */
    public const DEFINICIONES = [
        'estados_a_importar' => [
            'multiple' => true,
            'opciones' => [
                'done' => 'Terminada (done)',
                'initiated' => 'Iniciada (initiated)',
                'failed' => 'Fallida (failed)',
            ],
            // Con `initiated` adentro entran llamadas que aun no tienen
            // transcripcion ni analisis: la conversacion no ha terminado.
            'por_defecto' => ['done', 'failed'],
        ],
    ];

    /** @var array<string, array<int, string>>|null cache por peticion */
    private static ?array $valores = null;

    /**
     * Estados que entran al lote, en el orden en que se configuraron.
     *
     * @return array<int, string>
     */
    public function estadosAImportar(): array
    {
        return $this->valores()['estados_a_importar'] ?? self::DEFINICIONES['estados_a_importar']['por_defecto'];
    }

    /**
     * ¿Este `status` de ElevenLabs entra al lote?
     *
     * Un `status` vacio entra: preferimos importar y que se vea en el lote antes
     * que descartar una llamada en silencio por un campo que no vino.
     */
    public function estadoEsImportable(?string $estado): bool
    {
        $estado = trim((string) $estado);

        if ($estado === '') {
            return true;
        }

        return in_array($estado, $this->estadosAImportar(), true);
    }

    /**
     * Todas las opciones con lo necesario para dibujarlas.
     *
     * @return array<int, array{clave: string, etiqueta: string, ayuda: ?string, multiple: bool, opciones: array<string, string>, valores: array<int, string>, valor_texto: string}>
     */
    public function paraPantalla(): array
    {
        $filas = DB::table('elevenlabs_opciones')->orderBy('clave')->get();
        $valores = $this->valores();
        $salida = [];

        foreach ($filas as $fila) {
            $clave = (string) $fila->clave;
            $definicion = self::DEFINICIONES[$clave] ?? ['multiple' => false, 'opciones' => []];

            $salida[] = [
                'clave' => $clave,
                'etiqueta' => (string) $fila->etiqueta,
                'ayuda' => $fila->ayuda !== null ? (string) $fila->ayuda : null,
                'multiple' => (bool) ($definicion['multiple'] ?? false),
                'opciones' => $definicion['opciones'] ?? [],
                'valores' => $valores[$clave] ?? [],
                'valor_texto' => (string) $fila->valor,
            ];
        }

        return $salida;
    }

    /**
     * Guarda las opciones recibidas de la pantalla.
     *
     * Ignora claves desconocidas y valores fuera de la lista permitida: el
     * formulario puede venir manipulado y esto termina en la importacion.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<int, string> claves realmente guardadas
     */
    public function guardar(array $entrada): array
    {
        $guardadas = [];

        foreach (self::DEFINICIONES as $clave => $definicion) {
            if (! array_key_exists($clave, $entrada)) {
                continue;
            }

            $permitidos = array_keys($definicion['opciones'] ?? []);
            $recibidos = is_array($entrada[$clave]) ? $entrada[$clave] : [$entrada[$clave]];

            $limpios = [];
            foreach ($recibidos as $valor) {
                $valor = trim((string) $valor);
                if ($valor !== '' && in_array($valor, $permitidos, true) && ! in_array($valor, $limpios, true)) {
                    $limpios[] = $valor;
                }
            }

            // Una lista vacia dejaria el modulo sin importar nada: se mantiene lo
            // que ya estaba en vez de aceptar un guardado que no trae nada.
            if ($limpios === [] && ($definicion['multiple'] ?? false)) {
                continue;
            }

            DB::table('elevenlabs_opciones')
                ->where('clave', $clave)
                ->update([
                    'valor' => mb_substr(implode(',', $limpios), 0, 255),
                    'updated_at' => now(),
                ]);

            $guardadas[] = $clave;
        }

        self::$valores = null;

        return $guardadas;
    }

    /**
     * Lee todas las opciones de una vez y las cachea para la peticion.
     *
     * @return array<string, array<int, string>>
     */
    public function valores(): array
    {
        if (self::$valores !== null) {
            return self::$valores;
        }

        $valores = [];

        foreach (DB::table('elevenlabs_opciones')->get(['clave', 'valor']) as $fila) {
            $clave = (string) $fila->clave;
            $partes = array_values(array_filter(array_map('trim', explode(',', (string) $fila->valor)), fn ($v) => $v !== ''));

            $permitidos = array_keys(self::DEFINICIONES[$clave]['opciones'] ?? []);
            if ($permitidos !== []) {
                $partes = array_values(array_intersect($partes, $permitidos));
            }

            $valores[$clave] = $partes;
        }

        self::$valores = $valores;

        return self::$valores;
    }

    /** Limpia el cache (pruebas y despues de guardar). */
    public static function olvidar(): void
    {
        self::$valores = null;
    }
}

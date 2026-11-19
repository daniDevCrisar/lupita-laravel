<?php

namespace App\Ubicaciones;

/**
 * AnalizadorTitulos
 *
 * Replica en PHP del procedimiento `sp_actualizar_ruta_id` (V3, sql/rutas.sql:243-400).
 *
 * PARA QUE SIRVE: el modulo /lupita/ubicaciones necesita responder "¿que pasaria
 * con este titulo?" sobre texto que todavia no esta en la base, y necesita mostrar
 * por que fallo una ref. El SP no puede hacer eso (trabaja ref por ref sobre
 * `referencias`), asi que se replica aca.
 *
 * REGLA: este analizador NO escribe nada y NO arregla las rarezas del SP. Si fuera
 * mas listo que el SP, el diagnostico mentiria. Se copia tal cual:
 *  - corta el titulo en el PRIMER '/';
 *  - separa con ' - ', si no con '-', si no con ' A ' (el primero que aparezca);
 *  - borra parentesis ANTES de todo, asi que PUCALLPA(CORONADO) queda
 *    PUCALLPACORONADO y ya no matchea PUCALLPA;
 *  - compara insensible a mayusculas y tildes (imita el collation _ai_ci de MySQL);
 *  - en `palabra_clave`, el '|' es alternancia de regex y el texto viaja crudo;
 *  - gana la PRIMERA fila del catalogo que matchee, sin ordenar por "mejor";
 *  - si no hay local, usa el ubigeo del distrito como id.
 *
 * Es predictivo, no autoritativo: el SP busca con `LIMIT 1` sin `ORDER BY`, asi que
 * puede resolver distinto. Lo que vale es lo que devuelve el SP.
 */
class AnalizadorTitulos
{
    /** El titulo se pudo leer y ambos lados resolvieron. */
    public const OK = 'ok';

    /** La ref no tiene titulo de viaje. */
    public const SIN_TITULO = 'sin_titulo';

    /** El SP las rechaza a proposito antes de partirlas. */
    public const MULTIPARADA = 'multiparada';

    /** @var array<int, array{id: string, palabra_clave: string}> */
    private array $locales;

    /** @var array<int, array{ubigeo: string, distrito: string}> */
    private array $distritos;

    /**
     * @param array<int, array> $locales   filas con `id` y `palabra_clave`, en el orden del catalogo
     * @param array<int, array> $distritos filas con `ubigeo` y `distrito`
     */
    public function __construct(array $locales, array $distritos)
    {
        $this->locales = $locales;
        $this->distritos = $distritos;
    }

    /**
     * Analiza un titulo y devuelve el diagnostico completo.
     *
     * Claves: motivo, titulo, origen_raw, destino_raw, origen, destino,
     * origen_resuelto, destino_resuelto, ruta_id, falla, ya_resolveria.
     *
     * @return array<string, mixed>
     */
    public function analizar(?string $titulo): array
    {
        $titulo = (string) $titulo;

        $salida = [
            'motivo'           => self::OK,
            'titulo'           => $titulo,
            'origen_raw'       => '',
            'destino_raw'      => '',
            'origen'           => '',
            'destino'          => '',
            'origen_resuelto'  => null,
            'destino_resuelto' => null,
            'ruta_id'          => null,
            'falla'            => null,
            'ya_resolveria'    => false,
        ];

        if (trim($titulo) === '') {
            $salida['motivo'] = self::SIN_TITULO;

            return $salida;
        }

        // MySQL compara con collation _ai_ci: INSTR encuentra "multiparada" en minusculas
        if (mb_stripos($titulo, 'MULTIPARADA') !== false) {
            $salida['motivo'] = self::MULTIPARADA;

            return $salida;
        }

        // PASO 1: cortar en el primer '/'
        $sin_fecha = trim(explode('/', $titulo)[0]);

        // PASO 2: separar origen y destino
        [$origen_raw, $destino_raw] = $this->separar($sin_fecha);

        // PASO 3: limpiar
        $origen = $this->limpiar_origen($origen_raw);
        $destino = $this->limpiar_destino($destino_raw);

        $salida['origen_raw'] = $origen_raw;
        $salida['destino_raw'] = $destino_raw;
        $salida['origen'] = $origen;
        $salida['destino'] = $destino;

        // PASOS 4 y 5: local primero, ubigeo del distrito despues
        $salida['origen_resuelto'] = $this->resolver($origen);
        $salida['destino_resuelto'] = $this->resolver($destino);

        // PASO 6: que fallo y que id habria quedado
        $falla_origen = $salida['origen_resuelto'] === null;
        $falla_destino = $salida['destino_resuelto'] === null;

        if ($falla_origen && $falla_destino) {
            $salida['falla'] = 'ambos';
        } elseif ($falla_origen) {
            $salida['falla'] = 'origen';
        } elseif ($falla_destino) {
            $salida['falla'] = 'destino';
        }

        if ($salida['falla'] === null) {
            $salida['ruta_id'] = 'RUT_'
                . str_replace('LOC_', '', (string) $salida['origen_resuelto']['id']) . '-'
                . str_replace('LOC_', '', (string) $salida['destino_resuelto']['id']);
            $salida['ya_resolveria'] = true;
        }

        return $salida;
    }

    /**
     * Separa origen y destino. Precedencia y primer hallazgo, como el SP.
     *
     * @return array{0: string, 1: string}
     */
    private function separar(string $texto): array
    {
        foreach ([' - ', '-', ' A '] as $separador) {
            $pos = mb_strpos($texto, $separador);
            if ($pos !== false) {
                return [
                    trim(mb_substr($texto, 0, $pos)),
                    trim(mb_substr($texto, $pos + mb_strlen($separador))),
                ];
            }
        }

        return ['', ''];
    }

    /**
     * Quita parentesis y el prefijo del tipo de movimiento.
     */
    private function limpiar_origen(string $texto): string
    {
        $texto = str_replace(['(', ')'], '', $texto);
        $texto = (string) preg_replace('/^(ENVIO|DESPACHO|CARGA|RETORNO|RECOJO)\s+(PT|PLANTA)?\s*/iu', '', $texto);
        $texto = (string) preg_replace('/\s*(PT|PLANTA)\s*$/iu', '', $texto);

        return trim($texto);
    }

    /**
     * Quita parentesis y las fechas sueltas del final ("-18/04/2026").
     */
    private function limpiar_destino(string $texto): string
    {
        $texto = str_replace(['(', ')'], '', $texto);
        $texto = (string) preg_replace('#-\d{2}/\d{2}/\d{4}$#u', '', $texto);

        return trim($texto);
    }

    /**
     * Busca el local por palabra clave y, si no hay, el distrito del ubigeo.
     *
     * @return array{tipo: string, id: string, nombre: ?string}|null
     */
    private function resolver(string $texto): ?array
    {
        if ($texto === '') {
            return null;
        }

        foreach ($this->locales as $local) {
            $clave = trim((string) ($local['palabra_clave'] ?? ''));
            if ($clave === '') {
                continue;
            }

            // dos sentidos, como el SP: el texto contiene la clave, o la clave contiene el texto.
            // Se usa @ para que una palabra clave con regex invalido ("CORP. (CHRISTIAN")
            // devuelva false en vez de un warning.
            if ($this->matchea($texto, $clave) || $this->matchea($clave, $texto)) {
                return [
                    'tipo'   => 'local',
                    'id'     => (string) $local['id'],
                    'nombre' => isset($local['nombre']) ? (string) $local['nombre'] : null,
                ];
            }
        }

        return $this->buscar_distrito($texto);
    }

    /**
     * ¿El aguja aparece como palabra dentro del pajar? (regex del SP, insensible)
     */
    private function matchea(string $pajar, string $aguja): bool
    {
        return @preg_match('/\b(' . $aguja . ')\b/iu', $pajar) === 1;
    }

    /**
     * Si no hay local, el id es el ubigeo del distrito que aparezca en el texto.
     *
     * @return array{tipo: string, id: string, nombre: ?string}|null
     */
    private function buscar_distrito(string $texto): ?array
    {
        // igualdad exacta primero, como el SP (solo para el destino, pero aca no hace dano)
        foreach ($this->distritos as $distrito) {
            $nombre = (string) ($distrito['distrito'] ?? '');
            if ($nombre !== '' && mb_strtoupper($nombre) === mb_strtoupper($texto)) {
                return ['tipo' => 'distrito', 'id' => (string) $distrito['ubigeo'], 'nombre' => $nombre];
            }
        }

        foreach ($this->distritos as $distrito) {
            $nombre = (string) ($distrito['distrito'] ?? '');
            if ($nombre === '') {
                continue;
            }

            // preg_quote: el nombre del distrito es texto, no un patron
            if (@preg_match('/\b' . preg_quote(mb_strtoupper($nombre), '/') . '\b/iu', $texto) === 1) {
                return ['tipo' => 'distrito', 'id' => (string) $distrito['ubigeo'], 'nombre' => $nombre];
            }
        }

        return null;
    }
}

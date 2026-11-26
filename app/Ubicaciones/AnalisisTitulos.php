<?php

namespace App\Ubicaciones;

/**
 * AnalisisTitulos
 *
 * Orquesta el diagnostico de /lupita/ubicaciones: recibe las refs sin `ruta_id`,
 * las agrupa por titulo, corre el analizador, junta los fragmentos que no
 * resolvieron y los convierte en candidatos clasificados.
 *
 * POR QUE AGRUPA POR TITULO: 691 refs son 508 titulos distintos. Agrupar primero
 * evita analizar 691 veces el mismo texto y es lo que hace que la pantalla muestre
 * "MAQUILA AGUA, 92 refs" y no 92 filas iguales.
 */
class AnalisisTitulos
{
    private AnalizadorTitulos $analizador;
    private ExtractorCandidatos $extractor;
    private Clasificador $clasificador;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        AnalizadorTitulos $analizador,
        ExtractorCandidatos $extractor,
        Clasificador $clasificador,
        ?array $config = null
    ) {
        $this->analizador = $analizador;
        $this->extractor = $extractor;
        $this->clasificador = $clasificador;
        $this->config = array_merge([
            'maximo_fragmento' => 120,
        ], $config ?? (array) config('ubicaciones', []));
    }

    /**
     * Un analizador armado con el catalogo de la base y el resto de las piezas.
     */
    public static function desdeBase(?array $config = null): self
    {
        $analizador = \App\Database\DBUbicaciones::analizador();
        $config = $config ?? (array) config('ubicaciones', []);

        return new self(
            $analizador,
            new ExtractorCandidatos($config),
            new Clasificador($analizador, $config),
            $config
        );
    }

    /**
     * Diagnostico por titulo agrupado, para la pantalla "Títulos sin ruta".
     *
     * @param array<int, array{ref: string, titulo: string}> $refs
     *
     * @return array<int, array<string, mixed>>
     */
    private function filas_por_titulo(array $grupos): array
    {
        $salida = [];

        foreach ($grupos as $grupo) {
            $analisis = $this->analizador->analizar($grupo['titulo']);

            $salida[] = [
                'titulo'           => $grupo['titulo'],
                'refs'             => count($grupo['refs']),
                'refs_lista'       => $grupo['refs'],
                'motivo'           => $analisis['motivo'],
                'falla'            => $analisis['falla'],
                'origen'           => $analisis['origen'],
                'origen_resuelto'  => $analisis['origen_resuelto'],
                'destino'          => $analisis['destino'],
                'destino_resuelto' => $analisis['destino_resuelto'],
                'ruta_id'          => $analisis['ruta_id'],
                'ya_resolveria'    => $analisis['ya_resolveria'],
            ];
        }

        usort($salida, function (array $a, array $b) {
            if ($a['refs'] !== $b['refs']) {
                return $b['refs'] <=> $a['refs'];
            }

            return strcmp($a['titulo'], $b['titulo']);
        });

        return $salida;
    }

    /**
     * @param array<int, array{ref: string, titulo: string}> $refs
     *
     * @return array{
     *   refs: int, titulos: int, fragmentos: int, ya_resolverian: int,
     *   candidatos: array<int, array<string, mixed>>,
     *   detalle_fragmentos: array<int, array<string, mixed>>
     * }
     */
    /**
     * De una lista ref => titulo, cuales resolverian HOY a local en AMBOS lados.
     *
     * Sirve para las rutas que quedaron con un ubigeo en el id: si ahora hay locales
     * para los dos lados, reprocesar la ref la mejora (deja de usar el distrito).
     *
     * @param array<string, string> $refs_con_titulo
     *
     * @return array<string, string> ref => ruta_id que quedaria
     */
    public function refsConAmbosLadosLocal(array $refs_con_titulo): array
    {
        $salida = [];
        $cache = [];

        foreach ($refs_con_titulo as $ref => $titulo) {
            $titulo = (string) $titulo;

            // los titulos se repiten mucho (3.700 refs son ~1.000 titulos distintos):
            // analizar el mismo texto una sola vez baja el calculo a la mitad o menos
            if (!array_key_exists($titulo, $cache)) {
                $cache[$titulo] = $this->analizador->analizar($titulo);
            }

            $analisis = $cache[$titulo];

            if ($analisis['motivo'] !== AnalizadorTitulos::OK || !$analisis['ya_resolveria']) {
                continue;
            }

            if (($analisis['origen_resuelto']['tipo'] ?? '') === 'local'
                && ($analisis['destino_resuelto']['tipo'] ?? '') === 'local') {
                $salida[(string) $ref] = (string) $analisis['ruta_id'];
            }
        }

        return $salida;
    }

    public function calcular(array $refs): array
    {
        $grupos = $this->agrupar($refs);

        $candidatos = [];
        $fragmentos = [];
        $ya_resolverian = 0;

        foreach ($grupos as $grupo) {
            $analisis = $this->analizador->analizar($grupo['titulo']);
            $cantidad = count($grupo['refs']);

            if ($analisis['motivo'] !== AnalizadorTitulos::OK) {
                continue;
            }

            if ($analisis['ya_resolveria']) {
                $ya_resolverian += $cantidad;
                continue;
            }

            foreach (['origen', 'destino'] as $lado) {
                if ($analisis[$lado . '_resuelto'] !== null) {
                    continue;
                }

                $fragmento = trim((string) $analisis[$lado]);
                if ($fragmento === '') {
                    continue;
                }

                $this->sumar_fragmento($fragmentos, $fragmento, $lado, $cantidad, $grupo);

                $contrario = $lado === 'origen' ? 'destino_resuelto' : 'origen_resuelto';
                $cliente = $this->cliente_de($analisis[$contrario]);

                $this->sumar_candidatos($candidatos, $fragmento, $cantidad, $grupo, $cliente);
            }
        }

        return [
            'refs'               => count($refs),
            'titulos'            => count($grupos),
            'fragmentos'         => count($fragmentos),
            'ya_resolverian'     => $ya_resolverian,
            'candidatos'         => $this->clasificar_y_ordenar($candidatos),
            'detalle_fragmentos' => $this->ordenar_fragmentos($fragmentos),
            'titulos_detalle'    => $this->filas_por_titulo($grupos),
        ];
    }

    /**
     * Agrupa por titulo normalizado (sin fechas ni numeros) guardando sus refs.
     *
     * @param array<int, array{ref: string, titulo: string}> $refs
     *
     * @return array<string, array{titulo: string, refs: array<int, string>}>
     */
    private function agrupar(array $refs): array
    {
        $grupos = [];

        foreach ($refs as $fila) {
            $titulo = (string) ($fila['titulo'] ?? '');
            $clave = ExtractorCandidatos::normalizar($this->sin_numeros($titulo));

            if (!isset($grupos[$clave])) {
                $grupos[$clave] = ['titulo' => $titulo, 'refs' => []];
            }

            $grupos[$clave]['refs'][] = (string) ($fila['ref'] ?? '');
        }

        return $grupos;
    }

    /**
     * Borra fechas y numeros del titulo para que "CARGA 01.01" y "CARGA 02.01"
     * caigan en el mismo grupo.
     */
    private function sin_numeros(string $titulo): string
    {
        $titulo = (string) preg_replace('/\d{1,2}[.\/-]\d{1,2}([.\/-]\d{2,4})?/u', ' ', $titulo);
        $titulo = (string) preg_replace('/\d+/u', ' ', $titulo);

        return trim((string) preg_replace('/\s+/u', ' ', $titulo));
    }

    /**
     * Suma un fragmento sin resolver, sin duplicarlo entre titulos distintos.
     */
    private function sumar_fragmento(
        array &$fragmentos,
        string $fragmento,
        string $lado,
        int $cantidad,
        array $grupo
    ): void {
        $clave = ExtractorCandidatos::clave($fragmento);

        if (!isset($fragmentos[$clave])) {
            $fragmentos[$clave] = [
                'fragmento'      => mb_substr($fragmento, 0, (int) $this->config['maximo_fragmento']),
                'lado'           => $lado,
                'refs'           => 0,
                'titulos'        => 0,
                'ejemplo_ref'    => (string) ($grupo['refs'][0] ?? ''),
                'ejemplo_titulo' => $grupo['titulo'],
            ];
        }

        $fragmentos[$clave]['refs'] += $cantidad;
        $fragmentos[$clave]['titulos']++;
    }

    /**
     * Suma las frases del fragmento (palabras, n-gramas y palabras pegadas).
     */
    private function sumar_candidatos(
        array &$candidatos,
        string $fragmento,
        int $cantidad,
        array $grupo,
        ?string $cliente
    ): void {
        $frases = array_merge(
            $this->extractor->desdeFragmento($fragmento),
            $this->palabras_pegadas($fragmento)
        );

        foreach ($frases as $frase) {
            $clave = ExtractorCandidatos::clave($frase);

            if (!isset($candidatos[$clave])) {
                $candidatos[$clave] = [
                    'frase'          => $frase,
                    'clave'          => $clave,
                    'refs'           => 0,
                    'titulos'        => 0,
                    'ejemplo_ref'    => (string) ($grupo['refs'][0] ?? ''),
                    'ejemplo_titulo' => $grupo['titulo'],
                    'lado'           => '',
                    'clientes'       => [],
                ];
            }

            $candidatos[$clave]['refs'] += $cantidad;
            $candidatos[$clave]['titulos']++;
            $candidatos[$clave]['lado'] = $candidatos[$clave]['lado'] ?: $fragmento;

            if ($cliente !== null) {
                $candidatos[$clave]['clientes'][$cliente] = true;
            }
        }
    }

    /**
     * Palabras clave del catalogo que aparecen PEGADAS dentro del fragmento.
     *
     * Es el caso PUCALLPA: el SP borra los parentesis y deja PUCALLPACORONADO, asi
     * que el match por palabra falla, pero la palabra clave sigue estando ahi. Sin
     * esto, esos fragmentos (unos 90 en esta base) no aparecerian nunca como
     * "el local ya existe, el titulo se corto".
     *
     * @return array<int, string>
     */
    private function palabras_pegadas(string $fragmento): array
    {
        $buscado = ExtractorCandidatos::normalizar($fragmento);
        if ($buscado === '') {
            return [];
        }

        $salida = [];

        foreach ($this->analizador->locales() as $local) {
            foreach (explode('|', (string) ($local['palabra_clave'] ?? '')) as $variante) {
                $variante = trim($variante, " \t\n\r\0\x0B()");
                $normalizada = ExtractorCandidatos::normalizar($variante);

                // 4 caracteres como piso: "SMI" o "ECO" sueltos generan ruido
                if (mb_strlen($normalizada) < 4) {
                    continue;
                }

                if (str_contains($buscado, $normalizada)) {
                    $salida[ExtractorCandidatos::clave($variante)] = $variante;
                }
            }
        }

        return array_values($salida);
    }

    /**
     * Cliente del local con el que resolvio el lado contrario del titulo.
     */
    private function cliente_de(?array $resuelto): ?string
    {
        if ($resuelto === null || ($resuelto['tipo'] ?? '') !== 'local') {
            return null;
        }

        foreach ($this->analizador->locales() as $local) {
            if ((string) $local['id'] === (string) $resuelto['id']) {
                $cliente = (string) ($local['cliente_id'] ?? '');

                return $cliente !== '' ? $cliente : null;
            }
        }

        return null;
    }

    /**
     * Clasifica cada candidato y ordena: mas refs, despues la frase mas especifica
     * (mas palabras) y despues alfabetico.
     *
     * @return array<int, array<string, mixed>>
     */
    private function clasificar_y_ordenar(array $candidatos): array
    {
        $salida = [];

        foreach ($candidatos as $clave => $datos) {
            $clientes = array_keys($datos['clientes']);
            $clasificado = $this->clasificador->clasificar($datos['frase'], [
                'clientes' => $clientes,
                'refs'     => $datos['refs'],
            ]);

            $salida[] = [
                'frase'          => $datos['frase'],
                'clave'          => $clave,
                'refs'           => $datos['refs'],
                'titulos'        => $datos['titulos'],
                'veredicto'      => $clasificado['veredicto'],
                'local_id'       => $clasificado['local_id'],
                'detalle'        => $clasificado['detalle'],
                'ejemplo_ref'    => $datos['ejemplo_ref'],
                'ejemplo_titulo' => $datos['ejemplo_titulo'],
                'lado'           => $datos['lado'],
                'clientes'       => $clientes,
            ];
        }

        usort($salida, function (array $a, array $b) {
            if ($a['refs'] !== $b['refs']) {
                return $b['refs'] <=> $a['refs'];
            }

            $palabras_a = count(preg_split('/\s+/u', $a['frase']) ?: []);
            $palabras_b = count(preg_split('/\s+/u', $b['frase']) ?: []);
            if ($palabras_a !== $palabras_b) {
                return $palabras_b <=> $palabras_a;
            }

            return strcmp($a['frase'], $b['frase']);
        });

        return $salida;
    }

    /**
     * Fragmentos alfabeticos, para la solapa de lectura.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ordenar_fragmentos(array $fragmentos): array
    {
        $salida = array_values($fragmentos);

        usort($salida, fn (array $a, array $b) => strcmp($a['fragmento'], $b['fragmento']));

        return $salida;
    }
}

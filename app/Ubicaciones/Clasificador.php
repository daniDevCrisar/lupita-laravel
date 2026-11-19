<?php

namespace App\Ubicaciones;

/**
 * Clasificador
 *
 * Decide que hacer con una frase candidata comparandola contra el catalogo de
 * `locales`. Los veredictos, en el orden en que se prueban:
 *
 *  basura          solo signos ('----'): descartar
 *  generica        aparece en titulos de varios clientes, o el catalogo solo la
 *                  cubre por una variante entre parentesis ('(ACEITE)'): es un
 *                  producto, no un cliente. NO se ofrece crear local
 *  familia         dos o mas locales empiezan con la frase ('MAKRO ...'): falta
 *                  decidir si es un local por sede o uno generico
 *  cubierto_parseo algun local ya la cubre, pero el fragmento completo no resolvio:
 *                  el titulo se corto (parentesis, guion), no falta catalogo
 *  typo            se parece a la palabra clave de un local ('HUCHIPA' ~ HUACHIPA)
 *  nuevo           no se parece a nada: hay que crear el local
 */
class Clasificador
{
    public const BASURA = 'basura';
    public const GENERICA = 'generica';
    public const FAMILIA = 'familia';
    public const CUBIERTO = 'cubierto_parseo';
    public const TYPO = 'typo';
    public const NUEVO = 'nuevo';

    private AnalizadorTitulos $analizador;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(AnalizadorTitulos $analizador, ?array $config = null)
    {
        $this->analizador = $analizador;
        $this->config = array_merge([
            'parecido_porcentaje' => 85,
            'parecido_distancia'  => 2,
        ], $config ?? (array) config('ubicaciones', []));
    }

    /**
     * @param array{clientes?: array<int, string>, refs?: int} $contexto
     *
     * @return array{veredicto: string, local_id: ?string, detalle: ?string}
     */
    public function clasificar(string $frase, array $contexto = []): array
    {
        $frase = trim($frase);
        $clientes = array_values(array_unique(array_filter((array) ($contexto['clientes'] ?? []))));

        // 1. basura: no queda ninguna letra ni numero
        if (preg_replace('/[^\p{L}\p{N}]+/u', '', $frase) === '') {
            return $this->resultado(self::BASURA, null, 'solo signos o espacios');
        }

        // 2. generica por clientes: una palabra que aparece en titulos de clientes distintos
        if (count($clientes) > 1) {
            return $this->resultado(self::GENERICA, null, sprintf(
                'aparece en titulos de %d clientes distintos (%s): no es un local',
                count($clientes),
                implode(', ', $clientes)
            ));
        }

        // 3. generica por parentesis: el catalogo solo la cubre con una variante "(...)"
        $cubierto = $this->cubierto_por($frase);
        $por_parentesis = $this->cubierto_solo_por_parentesis($frase);
        if ($por_parentesis !== null && $cubierto !== null) {
            return $this->resultado(self::GENERICA, null, sprintf(
                'coincide con la variante «%s» de %s; por el paréntesis parece un producto y no un cliente',
                $por_parentesis['variante'],
                $por_parentesis['local_id']
            ));
        }

        // 4. familia: varias sedes del mismo nombre
        $familia = $this->familia($frase);
        if (count($familia) >= 2) {
            return $this->resultado(self::FAMILIA, null, 'el catalogo tiene varias sedes: '
                . implode(', ', $familia) . '. Decidir si se crea una por sede o una generica');
        }

        // 5. el local ya existe (no es que falte: el titulo se corto)
        if ($cubierto !== null) {
            return $this->resultado(self::CUBIERTO, $cubierto, 'el local ya existe: ' . $cubierto);
        }

        // 6. parecido a un local existente
        $parecido = $this->parecido($frase);
        if ($parecido !== null) {
            return $this->resultado(self::TYPO, $parecido, 'se parece a ' . $parecido);
        }

        return $this->resultado(self::NUEVO, null, 'no se parece a ningun local del catalogo');
    }

    /**
     * @return array{veredicto: string, local_id: ?string, detalle: ?string}
     */
    private function resultado(string $veredicto, ?string $local_id, ?string $detalle): array
    {
        return ['veredicto' => $veredicto, 'local_id' => $local_id, 'detalle' => $detalle];
    }

    /**
     * Id del primer local que cubre la frase (mismos dos sentidos que el analizador).
     */
    private function cubierto_por(string $frase): ?string
    {
        if ($frase === '') {
            return null;
        }

        foreach ($this->analizador->locales() as $local) {
            foreach ($this->variantes($local) as $variante) {
                if ($this->matchea($frase, $variante) || $this->matchea($variante, $frase)) {
                    return (string) $local['id'];
                }
            }
        }

        return null;
    }

    /**
     * Si la frase solo matchea variantes escritas entre parentesis, devuelve esa
     * variante. Es la firma de una palabra clave que declara un producto: el caso
     * `(ACEITE)` de LOC_AJEPER_GRUPO_PACIFIC_OIL.
     *
     * @return array{local_id: string, variante: string}|null
     */
    private function cubierto_solo_por_parentesis(string $frase): ?array
    {
        $encontrada = null;

        foreach ($this->analizador->locales() as $local) {
            foreach ($this->variantes($local) as $variante) {
                if (!$this->matchea($frase, $variante) && !$this->matchea($variante, $frase)) {
                    continue;
                }

                if ($this->es_parentesis($variante)) {
                    $encontrada = $encontrada ?? [
                        'local_id' => (string) $local['id'],
                        'variante' => trim($variante, '()'),
                    ];
                    continue;
                }

                // matchea una variante normal: no es "solo parentesis"
                return null;
            }
        }

        return $encontrada;
    }

    /**
     * Locales cuyas palabras clave empiezan con la frase: varias sedes del mismo nombre.
     *
     * @return array<int, string>
     */
    private function familia(string $frase): array
    {
        $buscada = ExtractorCandidatos::normalizar($frase) . ' ';
        $ids = [];

        foreach ($this->analizador->locales() as $local) {
            foreach ($this->variantes($local) as $variante) {
                if (str_starts_with(ExtractorCandidatos::normalizar($variante) . ' ', $buscada)) {
                    $ids[(string) $local['id']] = true;
                    break;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Local con la palabra clave mas parecida (porcentaje o distancia de edicion).
     */
    private function parecido(string $frase): ?string
    {
        $buscado = ExtractorCandidatos::normalizar($frase);
        if ($buscado === '') {
            return null;
        }

        $mejor = null;
        $mejor_porcentaje = -1;

        foreach ($this->analizador->locales() as $local) {
            foreach ($this->variantes($local) as $variante) {
                $normalizada = ExtractorCandidatos::normalizar(trim($variante, '()'));
                if ($normalizada === '') {
                    continue;
                }

                $porcentaje = 0.0;
                similar_text($buscado, $normalizada, $porcentaje);
                $distancia = levenshtein($buscado, $normalizada);

                $es_parecido = $porcentaje >= (float) $this->config['parecido_porcentaje']
                    || $distancia <= (int) $this->config['parecido_distancia'];

                if ($es_parecido && $porcentaje > $mejor_porcentaje) {
                    $mejor_porcentaje = $porcentaje;
                    $mejor = (string) $local['id'];
                }
            }
        }

        return $mejor;
    }

    /**
     * Variantes de la palabra clave (`AMAZON|BABEL AMAZON` son dos).
     *
     * @return array<int, string>
     */
    private function variantes(array $local): array
    {
        $clave = (string) ($local['palabra_clave'] ?? '');
        if (trim($clave) === '') {
            return [];
        }

        $variantes = [];
        foreach (explode('|', $clave) as $variante) {
            $variante = trim($variante);
            if ($variante !== '') {
                $variantes[] = $variante;
            }
        }

        return $variantes;
    }

    private function es_parentesis(string $variante): bool
    {
        return str_starts_with($variante, '(') && str_ends_with($variante, ')');
    }

    private function matchea(string $pajar, string $aguja): bool
    {
        if ($aguja === '') {
            return false;
        }

        return @preg_match('/\b(' . $aguja . ')\b/iu', $pajar) === 1;
    }
}

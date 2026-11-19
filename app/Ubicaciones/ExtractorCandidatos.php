<?php

namespace App\Ubicaciones;

/**
 * ExtractorCandidatos
 *
 * Convierte un fragmento de titulo ("DE PT MAQUILA AGUA") en frases candidatas a
 * `palabra_clave`: sus palabras sueltas y sus combinaciones de 2 y 3 palabras.
 *
 * POR QUE N-GRAMAS Y NO SOLO PALABRAS: `MAQUILA AGUA` es el local que falta, pero
 * tambien aparece como `MAQUILA` y `AGUA` por separado. Sin n-gramas, el ranking
 * solo muestra palabras y el usuario tiene que juntarlas a mano.
 *
 * El tope `maximo_candidato` existe para que ninguna frase que se ofrezca como
 * `palabra_clave` choque con el VARCHAR(100) de la columna ni con el `max:100`
 * de la validacion de locales.
 */
class ExtractorCandidatos
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var array<int, string> palabras ignoradas ya normalizadas */
    private array $ignoradas = [];

    public function __construct(?array $config = null)
    {
        $this->config = array_merge([
            'palabras_ignoradas' => [],
            'minimo_caracteres'  => 3,
            'maximo_ngrama'      => 3,
            'maximo_candidato'   => 60,
        ], $config ?? (array) config('ubicaciones', []));

        foreach ((array) $this->config['palabras_ignoradas'] as $palabra) {
            $this->ignoradas[] = self::normalizar((string) $palabra);
        }
    }

    /**
     * Frases candidatas del fragmento, sin repetir y en orden de aparicion.
     *
     * @return array<int, string>
     */
    public function desdeFragmento(string $fragmento): array
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', $fragmento));
        if ($limpio === '') {
            return [];
        }

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $limpio, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $utiles = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) < (int) $this->config['minimo_caracteres']) {
                continue;
            }
            if (is_numeric($token)) {
                continue;
            }
            if (in_array(self::normalizar($token), $this->ignoradas, true)) {
                continue;
            }
            $utiles[] = $token;
        }

        $salida = [];
        $vistas = [];
        $max_ngrama = max(1, (int) $this->config['maximo_ngrama']);
        $max_largo = (int) $this->config['maximo_candidato'];

        for ($n = 1; $n <= $max_ngrama; $n++) {
            for ($i = 0; $i + $n <= count($utiles); $i++) {
                $frase = implode(' ', array_slice($utiles, $i, $n));

                if (mb_strlen($frase) > $max_largo) {
                    continue;
                }

                $clave = self::clave($frase);
                if (isset($vistas[$clave])) {
                    continue;
                }

                $vistas[$clave] = true;
                $salida[] = $frase;
            }
        }

        return $salida;
    }

    /**
     * Mayusculas, sin tildes y con los espacios colapsados.
     *
     * Se usa para comparar y para la clave de descarte: asi "RECOJO  ACEITE",
     * "recojo aceite" y "RECOJO ACEITE" son el mismo candidato.
     */
    public static function normalizar(string $texto): string
    {
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);

        $texto = mb_strtoupper($texto);
        $texto = (string) preg_replace('/\s+/u', ' ', $texto);

        return trim($texto);
    }

    /**
     * Clave estable de un candidato (32 hex), la que se guarda en los descartes.
     */
    public static function clave(string $texto): string
    {
        return md5(self::normalizar($texto));
    }
}

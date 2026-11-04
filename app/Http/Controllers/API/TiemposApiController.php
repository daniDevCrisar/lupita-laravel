<?php

namespace App\Http\Controllers\Api;

use App\Database\DBTiemposRuta;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * TiemposApiController
 *
 * Endpoint publico (grupo /api, sin token, solo JSON) que expone la tabla de
 * tiempos promedio POR RUTA, exactamente la misma logica que la pantalla
 * interna `/lupita/tiempos` (DBTiemposRuta), pero en formato JSON.
 *
 *  GET /api/tiempos/ruta
 *
 * Parametros (query string, iguales que la pantalla):
 *  max_etapa     Tope por etapa (min): descarta el viaje si alguna E2..E6
 *                registrada cae fuera de [0, tope] o tiene hitos invertidos.
 *  fecha_inicio / fecha_fin
 *                Rango por las FECHAS DE LAS ETAPAS (E2..E6) de `referencias`,
 *                NO por llamadas. Un viaje entra si CUALQUIER hito de etapa
 *                cae dentro del rango.
 *  ruta_id       una sola ruta (ej. RUT_HUACHIPA-MAXO).
 *  conductor     id numerico o nombre LIKE.
 *  trt           id numerico o nombre LIKE.
 *  min_viajes    minimo de viajes por ruta para aparecer (default 1).
 *  tiene_ruta_promedio
 *                si viene verdadero, solo deja las rutas que tienen promedio de
 *                recorrido origen -> destino (etapa 4). Afecta al listado, no al
 *                resumen global.
 *  ordenar_por   ruta|ciclo|viajes|conductores.
 *  orden         0 = DESC, 1 = ASC.
 *  limit         filas (1..1000, default 100).
 *
 * Todos los tiempos se devuelven en MINUTOS. Las etapas expuestas son E2..E6
 * (la E1 queda fuera del ciclo y NO se incluye). Las ubicaciones (origen y
 * destino) se listan UNA sola vez en el mapa `ubicaciones`; cada ruta las
 * referencia por su clave, asi no se repiten las palabras clave por ruta.
 */
class TiemposApiController extends Controller
{
    /**
     * Mapa de ubicaciones unicas acumuladas al formatear las rutas:
     * clave => ['nombre' => ..., 'palabras_claves' => [...]].
     */
    private array $ubicaciones = [];

    public function por_ruta(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'ruta_id' => 'nullable|string|max:100',
            'conductor' => 'nullable|string|max:100',
            'trt' => 'nullable|string|max:100',
            'min_viajes' => 'nullable|numeric',
            'max_etapa' => 'nullable|numeric|min:1',
            'tiene_ruta_promedio' => 'nullable',
            'limit' => 'nullable|numeric',
            'ordenar_por' => 'nullable|string|max:30',
            'orden' => 'nullable|numeric',
        ]);

        DBTiemposRuta::set_filtro($request);

        // evitar desbordamiento por tiempos de espera largos (igual que la pantalla)
        \Illuminate\Support\Facades\DB::statement('SET SESSION wait_timeout = 600');

        $resumen = DBTiemposRuta::resumen();
        $descartados = DBTiemposRuta::viajes_descartados();
        $filas = DBTiemposRuta::tiempo_promedio_por_ruta();

        // el mapa de ubicaciones unicas se reconstruye en cada request
        $this->ubicaciones = [];
        $rutas = [];
        foreach ($filas as $fila) {
            $rutas[] = $this->formatear($fila);
        }

        return response()->json([
            'success' => true,
            'filtros' => [
                'fecha_inicio' => $request->fecha_inicio,
                'fecha_fin' => $request->fecha_fin,
                'max_etapa' => DBTiemposRuta::max_etapa(),
                'tiene_ruta_promedio' => DBTiemposRuta::tiene_ruta_promedio(),
                'ruta_id' => $request->ruta_id,
                'conductor' => $request->conductor,
                'trt' => $request->trt,
                'min_viajes' => (int) ($request->min_viajes ?? 1),
                'ordenar_por' => $request->ordenar_por,
                'orden' => $request->orden === null ? null : (int) $request->orden,
                'limit' => (int) ($request->limit ?? 100),
            ],
            'resumen' => [
                'rutas' => (int) ($resumen->rutas ?? 0),
                'viajes' => (int) ($resumen->viajes ?? 0),
                'conductores' => (int) ($resumen->conductores ?? 0),
                'ruta_promedio' => $this->num($resumen->ruta_promedio ?? null),
                'ciclo_promedio' => $this->num($resumen->ciclo_promedio ?? null),
                'descartados_por_tope' => $descartados === null ? null : (int) $descartados,
            ],
            'ubicaciones' => (object) $this->ubicaciones,
            'rutas' => $rutas,
        ]);
    }

    /**
     * Una fila de tiempo_promedio_por_ruta() a su representacion JSON.
     */
    private function formatear($f): array
    {
        return [
            'ruta_id' => $f->ruta_id,
            'nombre' => DBTiemposRuta::nombre_ruta($f),
            // origen/destino son CLAVES del mapa `ubicaciones` (unicas, no se repiten)
            'origen' => $this->ubicacion($f, 'origen'),
            'destino' => $this->ubicacion($f, 'destino'),
            'viajes' => (int) ($f->viajes ?? 0),
            'conductores' => (int) ($f->conductores ?? 0),
            // recorrido origen -> destino (etapa 4)
            'ruta_promedio' => $this->num($f->ruta_promedio ?? null),
            'ruta_min' => $this->num($f->ruta_min ?? null),
            'ruta_max' => $this->num($f->ruta_max ?? null),
            // ciclo completo = suma de promedios de E2..E6 con datos
            'ciclo_promedio' => $this->num($f->ciclo_promedio ?? null),
            'ciclo_etapas' => (int) ($f->ciclo_etapas ?? 0),
            'ciclo_viajes' => (int) ($f->ciclo_viajes ?? 0),
            'ciclo_min' => $this->num($f->ciclo_min ?? null),
            'ciclo_max' => $this->num($f->ciclo_max ?? null),
            // etapas del ciclo E2..E6 (la E1 queda fuera del ciclo)
            'etapas' => [
                'E2' => $this->etapa($f, 2),
                'E3' => $this->etapa($f, 3),
                'E4' => $this->etapa($f, 4),
                'E5' => $this->etapa($f, 5),
                'E6' => $this->etapa($f, 6),
            ],
        ];
    }

    /**
     * Registra la ubicacion (origen o destino) si aun no esta y devuelve su clave
     * unica. La clave es el id del local; si no hay local, el codigo de ubigeo.
     */
    private function ubicacion($f, string $lado): string
    {
        $o = $lado === 'destino' ? 'destino' : 'origen';

        $id = $f->{$o . '_id'} ?? null;
        $nombre_local = $f->{'loc_' . $o . '_nombre'} ?? null;
        $palabra = $f->{'loc_' . $o . '_palabra'} ?? null;
        $ubigeo = trim((string) ($f->{'ubigeo_' . $o} ?? ''));
        $distrito = $f->{'ubg_' . $o . '_nombre'} ?? null;

        // clave unica: id del local si existe, si no el ubigeo prefijado
        $clave = trim((string) ($id ?? ''));
        if ($clave === '') {
            $clave = 'UBG:' . $ubigeo;
        }

        if (!isset($this->ubicaciones[$clave])) {
            // palabras_claves: variantes del local separadas por '|'. Si no hay
            // local registrado se usa el NOMBRE del distrito (legible, ej.
            // "PUENTE PIEDRA"); solo si tampoco hay distrito cae al codigo ubigeo.
            $palabras = [];
            foreach (explode('|', (string) ($palabra ?? '')) as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $palabras[] = $p;
                }
            }
            if (!$palabras) {
                if ($distrito) {
                    $palabras[] = $distrito;
                } elseif ($ubigeo !== '') {
                    $palabras[] = $ubigeo;
                }
            }

            // nombre: el del local; si no hay local, el codigo de ubigeo
            // (ej. "150125"); si no, el distrito; y si no, la propia clave.
            $nombre = $nombre_local ?: ($ubigeo !== '' ? $ubigeo : ($distrito ?: $clave));

            $this->ubicaciones[$clave] = [
                'nombre' => $nombre,
                'palabras_claves' => $palabras,
            ];
        }

        return $clave;
    }

    private function etapa($f, int $n): array
    {
        return [
            'nombre' => DBTiemposRuta::$etapas[$n] ?? '',
            'promedio' => $this->num($f->{"etapa_{$n}_promedio"} ?? null),
            'viajes' => (int) ($f->{"etapa_{$n}_viajes"} ?? 0),
        ];
    }

    private function num($v)
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }
}

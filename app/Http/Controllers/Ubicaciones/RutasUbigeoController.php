<?php

namespace App\Http\Controllers\Ubicaciones;

use App\Database\DBUbicaciones;
use App\Http\Controllers\Controller;
use App\Ubicaciones\AnalisisTitulos;

/**
 * Ubicaciones\RutasUbigeoController
 *
 * Pantalla "Rutas con ubigeo" (/lupita/ubicaciones/rutas-ubigeo).
 *
 * EL PROBLEMA QUE RESUELVE: cuando el SP no encontraba un local, usaba el distrito y
 * dejaba `rutas.origen_id` (o `destino_id`) con un ubigeo de 6 digitos. Si despues se
 * da de alta ese local, las refs de esa ruta siguen apuntando al ubigeo: hay que
 * reprocesarlas para que el SP ahora si encuentre el local.
 *
 * La pantalla muestra cuantas son y cuantas refs YA resolverian a local-local, y manda
 * esas refs al mismo boton de reprocesar (no escribe nada por su cuenta).
 *
 * REGLA DE ACCESO: `role:admin` en la ruta.
 */
class RutasUbigeoController extends Controller
{
    public function index()
    {
        $rutas = DBUbicaciones::rutasConUbigeo();
        $ids = array_map(fn (array $r) => $r['id'], $rutas);

        $refsConTitulo = DBUbicaciones::refsDeRutas($ids);

        $resolverian = AnalisisTitulos::desdeBase()->refsConAmbosLadosLocal($refsConTitulo);

        // cuantas refs mejorables tiene cada ruta, para mostrarlo en la tabla
        $porRuta = [];
        foreach ($resolverian as $ref => $rutaId) {
            $porRuta[$rutaId] = ($porRuta[$rutaId] ?? 0) + 1;
        }

        foreach ($rutas as $i => $ruta) {
            $rutas[$i]['resolverian'] = $porRuta[$ruta['id']] ?? 0;
        }

        // los nombres de distrito de los ubigeos, para que la tabla se lea
        $ubigeos = [];
        foreach ($rutas as $ruta) {
            foreach ([$ruta['origen_id'], $ruta['destino_id']] as $lado) {
                if (preg_match('/^\d{6}$/', $lado) === 1) {
                    $ubigeos[$lado] = $lado;
                }
            }
        }

        return view('lupita.ubicaciones.rutas_ubigeo', [
            'rutas'          => $rutas,
            'distritos'      => $this->distritos(array_values($ubigeos)),
            'refs_totales'   => count($refsConTitulo),
            'refs_mejorables' => count($resolverian),
            'sp_disponible'  => \App\Database\DBReprocesar::spDisponible(),
        ]);
    }

    /**
     * ubigeo => "DISTRITO (PROVINCIA, DEPARTAMENTO)".
     *
     * @param array<int, string> $ubigeos
     *
     * @return array<string, string>
     */
    private function distritos(array $ubigeos): array
    {
        $salida = [];

        foreach (array_chunk($ubigeos, 500) as $lote) {
            $filas = \Illuminate\Support\Facades\DB::table('ubigeo_distritos as d')
                ->leftJoin('ubigeo_provincias as p', 'p.id', '=', 'd.provincia_id')
                ->leftJoin('ubigeo_departamentos as dep', 'dep.id', '=', 'd.departamento_id')
                ->whereIn('d.ubigeo', $lote)
                ->get(['d.ubigeo', 'd.distrito', 'p.provincia', 'dep.departamento']);

            foreach ($filas as $f) {
                $partes = array_filter([
                    (string) $f->distrito,
                    (string) ($f->provincia ?? ''),
                    (string) ($f->departamento ?? ''),
                ], fn (string $p) => $p !== '');

                $salida[(string) $f->ubigeo] = implode(', ', $partes);
            }
        }

        return $salida;
    }
}

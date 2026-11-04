<?php

namespace App\Http\Controllers;

use App\Database\DBComportamiento;
use App\Database\DBTiemposRuta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reporte de comportamiento por placa o conductor sobre las referencias
 * (rutas habituales, horario habitual, puntualidad y tiempos en planta).
 *
 * Dos pantallas:
 *  - index(): ranking con los filtros del rango
 *  - ficha(): detalle de un grupo, con mapa de calor horario y ultimos viajes
 */
class ComportamientoController extends Controller
{
    public static function index(Request $request)
    {
        $datos = self::preparar($request);

        // Solo se calcula el agrupamiento elegido: cada ranking hace tres consultas
        // auxiliares (horario, rutas y pares) y calcular los dos duplica la espera.
        $datos->ranking = DBComportamiento::ranking($datos->grupo, DBComportamiento::limite());
        $datos->confianza = DBComportamiento::confiabilidad_global();

        return view('lupita.comportamiento', array_merge(self::comunes(), [
            'datos' => $datos,
            'grupo' => $datos->grupo,
        ]));
    }

    public static function ficha(Request $request)
    {
        $request->validate([
            'grupo' => 'required|in:placa,conductor',
            'clave' => 'required|string|max:150',
        ]);

        $datos = self::preparar($request);

        $grupo = (string) $request->input('grupo');
        $clave = trim((string) $request->input('clave'));

        $datos->ficha = DBComportamiento::ficha($grupo, $clave);
        $datos->global = DBComportamiento::resumen();
        $datos->confianza = DBComportamiento::confiabilidad_global();
        $datos->chofer = self::chofer_habitual($grupo, $clave, $datos->ficha);

        return view('lupita.comportamiento_ficha', array_merge(self::comunes(), [
            'datos' => $datos,
            'grupo' => $grupo,
            'clave' => $clave,
        ]));
    }

    /**
     * Chofer que concentra mas de la mitad de los viajes de una placa, con sus
     * propios promedios. Con otro reparto devuelve null: los promedios de un
     * chofer minoritario no describen la placa.
     */
    private static function chofer_habitual(string $grupo, string $clave, stdClass $ficha): ?stdClass
    {
        if ($grupo !== 'placa') {
            return null;
        }

        $par = $ficha->pares[0] ?? null;
        $viajes = (int) ($ficha->fila->viajes ?? 0);
        if (!$par || $viajes <= 0 || $par['valor'] === null) {
            return null;
        }

        $pct = round($par['n'] / $viajes * 100, 1);
        if ($pct <= 50) {
            return null;
        }

        return (object) [
            'nombre' => $par['nombre'],
            'n' => (int) $par['n'],
            'pct' => $pct,
            'resumen' => DBComportamiento::resumen_par($clave, $par['valor']),
        ];
    }

    /** Valida el formulario, fija el filtro y calcula los totales del rango. */
    private static function preparar(Request $request): stdClass
    {
        // Sin rango explicito se trabaja con el ultimo mes: la base guarda hitos
        // sueltos con anios viejos que distorsionan cualquier total historico.
        if (!$request->filled('fecha_inicio') && !$request->filled('fecha_fin')) {
            $request->merge([
                'fecha_inicio' => now()->subDays(29)->toDateString(),
                'fecha_fin' => now()->toDateString(),
            ]);
        }

        if (!$request->filled('tope')) {
            $request->merge(['tope' => 2880]);
        }

        if (!in_array((string) $request->input('grupo'), ['placa', 'conductor'], true)) {
            $request->merge(['grupo' => 'placa']);
        }

        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'placa' => 'nullable|string|max:20',
            'conductor' => 'nullable|string|max:150',
            'trt' => 'nullable|string|max:150',
            'ruta_id' => 'nullable|string|max:100',
            'tope' => 'nullable|numeric',
            'min_viajes' => 'nullable|numeric',
            'limite' => 'nullable|numeric',
            'ordenar_por' => 'nullable|in:viajes,ciclo,ciclo_real,ruta_prom,desvio,cerrados,rutas,dias,hora,regularidad,'
                . 'hora_presenta,regularidad_presenta,hora_llegada,regularidad_llegada,confiabilidad,ruta_pct,par_pct',
            'orden' => 'nullable|in:asc,desc',
        ]);

        // El join referencias x llamadas puede ser pesado con rangos largos.
        DB::statement('SET SESSION wait_timeout = 600');

        DBComportamiento::set_filtro($request);

        $datos = new stdClass();
        $datos->grupo = (string) $request->input('grupo');
        $datos->resumen = DBComportamiento::resumen();
        $datos->tope = DBComportamiento::tope();

        return $datos;
    }

    /** Catalogo de rutas y etiquetas que comparten las dos pantallas. */
    private static function comunes(): array
    {
        $rutas = DBTiemposRuta::rutas_lista();
        $nombres = [];
        foreach ($rutas as $ruta) {
            $nombres[$ruta->id] = DBTiemposRuta::nombre_ruta($ruta);
        }

        return [
            'rutas' => $rutas,
            'nombres_rutas' => $nombres,
            'etapas' => DBComportamiento::$etapas,
            'topes' => DBComportamiento::TOPES,
            'dias' => DBComportamiento::$dias,
        ];
    }
}

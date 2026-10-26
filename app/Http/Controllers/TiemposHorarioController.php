<?php

namespace App\Http\Controllers;

use App\Database\DBTiemposHorario;
use App\Database\DBTiemposRuta;
use Illuminate\Http\Request;
use stdClass;

class TiemposHorarioController extends Controller
{
    public static function index(Request $request)
    {
        // El filtro de rutas llega como arreglo (?ruta_id[]=A&ruta_id[]=B);
        // se normaliza antes de validar para aceptar tambien enlaces antiguos con una sola ruta.
        $request->merge(['ruta_id' => (array) $request->input('ruta_id')]);

        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'ruta_id' => 'array',
            'ruta_id.*' => 'string|max:100',
            'granularidad' => 'nullable|in:hora,turno',
            'conductor' => 'nullable|numeric',
            'tope' => 'nullable|numeric',
            'min_viajes' => 'nullable|numeric',
            'dia_semana' => 'nullable|numeric|min:0|max:6',
        ]);

        $horario = new DBTiemposHorario();
        $horario::set_filtro($request);

        // Al elegir un conductor se marcan de una vez todas sus rutas.
        // Si el usuario toco las casillas a mano, se respeta su seleccion.
        $rutas_conductor = [];
        if ($horario::filtrando_conductor()) {
            $rutas_conductor = $horario::rutas_conductor();

            if ($rutas_conductor && !$request->filled('rutas_tocadas')) {
                $horario::set_rutas($rutas_conductor);
            }
        }

        //-------------EVITAR DESBORDAMIENTO POR TIEMPO DE ESPERA
        \Illuminate\Support\Facades\DB::statement('SET SESSION wait_timeout = 600');
        //------------------------

        $datos = new stdClass();
        $datos->resumen = $horario::resumen();
        $datos->descartados = $horario::descartados();
        $datos->por_horario = $horario::por_horario();
        $datos->matriz_dia = $horario::matriz_dia();

        // En modo turno se mantiene el detalle de las 24 horas como complemento
        $datos->por_hora = $horario::granularidad() === 'turno' ? $horario::por_horario('hora') : [];

        // Mapa ruta x hora: las seleccionadas por el usuario o, si no hay filtro, el top por viajes
        $filtrando = $horario::filtrando_rutas();
        $datos->rutas_top = $horario::rutas_top($filtrando ? 50 : 12, !$filtrando);
        $datos->matriz_ruta = $horario::matriz_ruta(array_column($datos->rutas_top, 'ruta_id'));
        $datos->filtrando_rutas = $filtrando;

        $rutas = DBTiemposRuta::rutas_lista();

        // Con un conductor elegido el selector solo ofrece sus rutas
        if ($rutas_conductor) {
            $rutas = array_values(array_filter(
                $rutas,
                fn ($ruta) => in_array($ruta->id, $rutas_conductor, true)
            ));
        }

        $nombres_rutas = [];
        foreach ($rutas as $ruta) {
            $nombres_rutas[$ruta->id] = DBTiemposRuta::nombre_ruta($ruta);
        }

        return view('lupita.tiempos_horario', [
            'horario' => $horario,
            'datos' => $datos,
            'rutas' => $rutas,
            'nombres_rutas' => $nombres_rutas,
            'rutas_sel' => $horario::rutas(),
            'conductores' => $horario::conductores_lista(),
            'conductor_sel' => $horario::conductor(),
            'conductor_nombre' => $horario::filtrando_conductor()
                ? $horario::nombre_conductor($horario::conductor())
                : '',
            'turnos' => DBTiemposHorario::$turnos,
            'dias' => DBTiemposHorario::$dias,
            'topes' => DBTiemposHorario::$topes,
        ]);
    }
}

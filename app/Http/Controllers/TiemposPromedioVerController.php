<?php

namespace App\Http\Controllers;

use App\Database\DBTiemposHorario;
use App\Database\DBTiemposPromedio;
use App\Database\DBTiemposPromedioCompilado;

/**
 * Pantallas de consulta de los promedios YA compilados.
 *
 * No calculan ni escriben: solo arman los filtros y apuntan al endpoint JSON
 * (`/api/tiempos/promedio` y `/api/tiempos/promedio/horario`), igual que la
 * pantalla de prueba del API de tiempos. Las pantallas de compilacion siguen
 * siendo `TiemposPromedioController`.
 */
class TiemposPromedioVerController extends Controller
{
    /**
     * Promedios compilados por ruta (inicio de ruta -> llegada a destino).
     */
    public function rutas()
    {
        return view('lupita.tiempos_promedio_ver', [
            'endpoint' => url('/api/tiempos/promedio'),
            'estado' => DBTiemposPromedio::estado(),
            'rutas' => DBTiemposPromedio::rutas(),
            'departamentos' => DBTiemposPromedio::departamentos(),
            'ordenes' => DBTiemposPromedioCompilado::ORDENES,
            'limite' => 300,
        ]);
    }

    /**
     * Promedios compilados por dia de la semana y hora de inicio de ruta.
     */
    public function horario()
    {
        return view('lupita.tiempos_promedio_horario_ver', [
            'endpoint' => url('/api/tiempos/promedio/horario'),
            'estado' => DBTiemposPromedio::estado_horario(),
            'rutas' => DBTiemposPromedio::rutas(),
            'dias' => DBTiemposHorario::$dias,
            'ordenes' => DBTiemposPromedioCompilado::ORDENES_HORARIO,
            'limite' => DBTiemposPromedioCompilado::LIMITE_HORARIO,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Database\DBTiemposPromedio;

/**
 * Pantalla de TOOLS: piezas compartidas entre pantallas, con su demo.
 *
 * Hoy tiene una sola: el selector con búsqueda (`lupita/tools/selector.blade.php`
 * + `public/js/lupita-selectores.js`). Antes cada pantalla lo resolvía a su
 * manera:
 *
 *   - tiempos/horario: un <select> + un input de búsqueda + JS `buscadorDeSelect`;
 *   - tiempos/promedio: un dropdown propio con `data-combo`;
 *   - tiempos/promedio/ver, tiempos/promedio/horario/ver y referencias: <select>
 *     pelado o un input de texto libre.
 *
 * La idea de esta página es que se pueda ver cómo queda y copiar el `@include`
 * sin tener que leer el JS.
 */
class ToolsController extends Controller
{
    /** Selector con búsqueda: demo y documentación. */
    public function selectores()
    {
        // el ejemplo simple: las rutas compiladas (id + nombre)
        $rutas = [];
        try {
            foreach (DBTiemposPromedio::rutas() as $id => $ruta) {
                $rutas[] = ['valor' => (string) $id, 'texto' => (string) $ruta->nombre, 'extra' => (string) $id];
            }
        } catch (\Throwable $e) {
            $rutas = [];
        }

        // el ejemplo con "extra": departamentos (código + nombre)
        $departamentos = [];
        try {
            foreach (DBTiemposPromedio::departamentos() as $codigo => $nombre) {
                $departamentos[] = ['valor' => (string) $codigo, 'texto' => (string) $nombre];
            }
        } catch (\Throwable $e) {
            $departamentos = [];
        }

        return view('lupita.tools.selectores', [
            'rutas' => $rutas,
            'departamentos' => $departamentos,
            'ejemplo_valor' => (string) request('ruta_id', ''),
            'ejemplo_valores' => array_map('strval', (array) request('ruta_ids', [])),
        ]);
    }
}

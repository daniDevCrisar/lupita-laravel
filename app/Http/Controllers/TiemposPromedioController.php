<?php

namespace App\Http\Controllers;

use App\Database\DBTiemposPromedio;
use Illuminate\Http\Request;

/**
 * Pantalla para compilar los promedios de la etapa de ruta (inicio de ruta ->
 * llegada a destino) con un tope en minutos.
 *
 * No calcula nada por su cuenta: todo el SQL vive en `DBTiemposPromedio`. La
 * pantalla solo previsualiza lo que se pierde con el tope elegido y, si se
 * confirma, guarda el resultado por partes.
 */
class TiemposPromedioController extends Controller
{
    /** Filas de la tabla por ruta que se muestran de una vez. */
    private const LIMITE_FILAS = 300;

    public function index(Request $request)
    {
        $tope = $this->tope($request);
        $estado = DBTiemposPromedio::estado();
        $topes = $this->topes($request);
        $modo = $this->modo($request, $estado->rutas, $topes);
        $ruta_id = trim((string) $request->get('ruta_id', '')) ?: null;
        $dep_origen = $this->departamento($request->get('dep_origen'));
        $dep_destino = $this->departamento($request->get('dep_destino'));
        $orden = $this->orden($request);
        // con el filtro activo la pantalla mide solo lo que falta compilar
        $ocultar_compiladas = $request->boolean('ocultar_compiladas');

        $rutas_tabla = DBTiemposPromedio::por_ruta($tope, $modo, $ruta_id, $dep_origen, $dep_destino, $orden, $topes, $ocultar_compiladas);

        $datos = (object) [
            'tope' => $tope,
            'modo' => $modo,
            'ruta_id' => $ruta_id,
            'dep_origen' => $dep_origen,
            'dep_destino' => $dep_destino,
            'orden' => $orden,
            'ordenes' => DBTiemposPromedio::ORDENES,
            'topes' => $topes,
            'ocultar_compiladas' => $ocultar_compiladas,
            'ocultas' => $rutas_tabla->ocultas,
            'perdida' => DBTiemposPromedio::perdida($tope, $modo, $ruta_id, $dep_origen, $dep_destino, $topes, $ocultar_compiladas),
            'filas' => $rutas_tabla->filas,
            'guardados' => DBTiemposPromedio::guardados_por_ruta(),
            'estado' => $estado,
            'rutas' => DBTiemposPromedio::rutas(),
            'departamentos' => DBTiemposPromedio::departamentos(),
            'limite' => self::LIMITE_FILAS,
        ];

        return view('lupita.tiempos_promedio', compact('datos'));
    }

    /**
     * Compila y guarda. `alcance` decide el subconjunto: todo lo que entra en
     * el filtro de la vista, una ruta, solo las que todavia no tienen promedio
     * (compilacion por partes) o las rutas marcadas con los checks.
     */
    public function compilar(Request $request)
    {
        $validado = $request->validate([
            'tope' => ['nullable', 'integer', 'min:' . DBTiemposPromedio::TOPE_MINIMO, 'max:100000'],
            'modo' => ['nullable', 'in:global,ruta,propio'],
            'ruta_id' => ['nullable', 'string', 'max:100'],
            'alcance' => ['nullable', 'in:todas,ruta,faltantes,seleccion'],
            'solo_faltantes' => ['nullable'],
            'dep_origen' => ['nullable', 'string', 'max:6'],
            'dep_destino' => ['nullable', 'string', 'max:6'],
            'rutas' => ['nullable', 'array'],
            'rutas.*' => ['string', 'max:100'],
            'orden' => ['nullable', 'string', 'max:20'],
            'ocultar_compiladas' => ['nullable'],
        ]);

        $tope = max(DBTiemposPromedio::TOPE_MINIMO, (int) ($validado['tope'] ?? DBTiemposPromedio::TOPE_DEFECTO));
        $modo = $this->modo($request, DBTiemposPromedio::estado()->rutas);
        $alcance = $validado['alcance'] ?? 'todas';
        $orden = $this->orden($request);
        $dep_origen = $this->departamento($validado['dep_origen'] ?? null);
        $dep_destino = $this->departamento($validado['dep_destino'] ?? null);

        $ruta_id = null;
        $solo_faltantes = false;
        $solo_rutas = [];

        if ($alcance === 'ruta') {
            $ruta_id = trim((string) ($validado['ruta_id'] ?? ''));
            if ($ruta_id === '') {
                return back()->with('error', 'Elige una ruta para compilar solo ese tramo.');
            }
        }
        if ($alcance === 'faltantes') {
            $solo_faltantes = true;
        }

        // con el filtro de compiladas el lote "todas" ya viene sin las rutas
        // guardadas, asi que se compila igual que "solo las que faltan"
        $ocultar_compiladas = $request->boolean('ocultar_compiladas');
        if ($ocultar_compiladas && $alcance === 'todas') {
            $solo_faltantes = true;
        }
        if ($alcance === 'seleccion') {
            $solo_rutas = array_values(array_filter(
                array_map('strval', (array) ($validado['rutas'] ?? [])),
                fn ($id) => trim($id) !== ''
            ));
            if ($solo_rutas === []) {
                return back()->with('error', 'Marca al menos una ruta en la tabla para compilar la seleccion.');
            }
        }

        try {
            // el filtro de departamento solo acota los lotes; una ruta o una
            // seleccion explicita ya vienen elegidas a mano en la pantalla
            $filtro_origen = in_array($alcance, ['ruta', 'seleccion'], true) ? null : $dep_origen;
            $filtro_destino = in_array($alcance, ['ruta', 'seleccion'], true) ? null : $dep_destino;

            $resultado = DBTiemposPromedio::compilar($tope, $modo, $ruta_id, $solo_faltantes, $solo_rutas, $filtro_origen, $filtro_destino);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'No se pudo compilar: ' . $e->getMessage());
        }

        $mensaje = sprintf(
            'Se compilaron %d ruta(s) %s: %d viaje(s) usados, %d descartados, %d celda(s) de dia/hora.',
            $resultado->rutas,
            $modo === 'propio' ? 'con el tope guardado de cada una' : 'con tope de ' . $resultado->tope . ' min',
            $resultado->viajes,
            $resultado->descartados,
            $resultado->celdas
        );

        if ($resultado->rutas === 0) {
            return back()->with('error', 'No se compilo ninguna ruta: con este tope no queda ningun viaje medible o la tabla ya estaba completa. ' . $mensaje);
        }

        $query = [
            'tope' => $tope,
            'modo' => $modo,
            'ruta_id' => $ruta_id,
            'dep_origen' => $dep_origen,
            'dep_destino' => $dep_destino,
            'orden' => $orden,
        ];
        if ($ocultar_compiladas) {
            $query['ocultar_compiladas'] = 1;
        }

        return redirect()
            ->route('lupita.tiempos.promedio', $query)
            ->with('success', $mensaje);
    }

    private function tope(Request $request): int
    {
        $tope = (int) $request->get('tope', DBTiemposPromedio::TOPE_DEFECTO);

        return max(DBTiemposPromedio::TOPE_MINIMO, $tope);
    }

    /**
     * Codigo de departamento del ubigeo (dos digitos) o null si viene vacio o
     * con basura.
     */
    private function departamento($valor): ?string
    {
        $valor = trim((string) $valor);

        return preg_match('/^\d{1,2}$/', $valor) === 1 ? str_pad($valor, 2, '0', STR_PAD_LEFT) : null;
    }

    /**
     * Orden de la tabla por ruta; cualquier valor raro cae al de por defecto.
     */
    private function orden(Request $request): string
    {
        $orden = (string) $request->get('orden', 'viajes');

        return array_key_exists($orden, DBTiemposPromedio::ORDENES) ? $orden : 'viajes';
    }

    /**
     * Tope a aplicar: global, p97 por ruta o el tope de cada ruta (el escrito en
     * la tabla o el que se fijo al compilarla). Sin ninguno de los dos no hay
     * tope propio que aplicar, asi que ese modo cae al global.
     *
     * @param  array<string, int>  $topes
     */
    private function modo(Request $request, int $rutas_guardadas, array $topes = []): string
    {
        $modo = (string) $request->get('modo', 'global');

        if (!in_array($modo, ['ruta', 'propio'], true)) {
            return 'global';
        }

        return ($modo === 'propio' && $rutas_guardadas === 0 && $topes === []) ? 'global' : $modo;
    }

    /**
     * Topes escritos en la columna "Tope por ruta" que llegan al recalcular:
     * `topes[ruta_id] = minutos`. Se descarta lo que no sea un entero valido.
     *
     * @return array<string, int>
     */
    private function topes(Request $request): array
    {
        $entrada = (array) $request->get('topes', []);
        $salida = [];

        foreach ($entrada as $ruta_id => $minutos) {
            $ruta_id = trim((string) $ruta_id);
            $minutos = is_numeric($minutos) ? (int) $minutos : 0;

            if ($ruta_id === '' || strlen($ruta_id) > 100) {
                continue;
            }
            if ($minutos < DBTiemposPromedio::TOPE_MINIMO || $minutos > 100000) {
                continue;
            }

            $salida[$ruta_id] = $minutos;
        }

        return array_slice($salida, 0, 300, true);
    }

    //------------------- promedios por dia y hora -------------------

    /**
     * Previsualiza la matriz ruta x dia x hora con el tope elegido: cuantos
     * viajes arrancaron en cada celda y cuantos sobreviven al tope.
     */
    public function horario(Request $request)
    {
        $tope = $this->tope($request);
        // el horario solo ofrece tope global o p97 por ruta
        $modo = $request->get('modo') === 'ruta' ? 'ruta' : 'global';
        $ruta_id = trim((string) $request->get('ruta_id', '')) ?: null;
        $min_viajes = $this->min_viajes($request);

        $datos = (object) [
            'tope' => $tope,
            'modo' => $modo,
            'ruta_id' => $ruta_id,
            'min_viajes' => $min_viajes,
            'horario' => DBTiemposPromedio::horario($tope, $modo, $ruta_id, $min_viajes),
            'estado' => DBTiemposPromedio::estado_horario(),
            'rutas' => DBTiemposPromedio::rutas(),
            'limite' => self::LIMITE_FILAS,
        ];

        return view('lupita.tiempos_promedio_horario', compact('datos'));
    }

    /**
     * Compila SOLO las celdas dia x hora. No toca los promedios de ruta, asi
     * que se puede recompilar el horario con otro tope sin perder nada.
     */
    public function compilar_horario(Request $request)
    {
        $validado = $request->validate([
            'tope' => ['nullable', 'integer', 'min:' . DBTiemposPromedio::TOPE_MINIMO, 'max:100000'],
            'modo' => ['nullable', 'in:global,ruta'],
            'ruta_id' => ['nullable', 'string', 'max:100'],
            'alcance' => ['nullable', 'in:todas,ruta'],
            'min_viajes' => ['nullable', 'integer'],
        ]);

        $tope = max(DBTiemposPromedio::TOPE_MINIMO, (int) ($validado['tope'] ?? DBTiemposPromedio::TOPE_DEFECTO));
        $modo = ($validado['modo'] ?? 'global') === 'ruta' ? 'ruta' : 'global';
        $ruta_id = trim((string) ($validado['ruta_id'] ?? '')) ?: null;

        if (($validado['alcance'] ?? 'todas') === 'ruta' && $ruta_id === null) {
            return back()->with('error', 'Elige una ruta para compilar solo su horario.');
        }

        try {
            $resultado = DBTiemposPromedio::compilar_solo_horario($tope, $modo, $ruta_id);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'No se pudo compilar el horario: ' . $e->getMessage());
        }

        $mensaje = sprintf(
            'Se compilaron %d celda(s) día/hora de %d ruta(s) con tope de %d min. Los promedios de ruta no se tocaron.',
            $resultado->celdas,
            $resultado->rutas,
            $resultado->tope
        );

        if ($resultado->celdas === 0) {
            return back()->with('error', 'No se compiló ninguna celda: con este tope no queda ningún viaje medible. ' . $mensaje);
        }

        return redirect()
            ->route('lupita.tiempos.promedio.horario', [
                'tope' => $tope,
                'modo' => $modo,
                'ruta_id' => $ruta_id,
                'min_viajes' => $validado['min_viajes'] ?? 1,
            ])
            ->with('success', $mensaje);
    }

    /**
     * Compila una sola ruta con un tope propio escrito en la tabla. Queda
     * marcada con `modo = propio` para distinguirla de una compilacion global.
     */
    public function compilar_ruta(Request $request)
    {
        $validado = $request->validate([
            'ruta_id' => ['required', 'string', 'max:100'],
            'tope' => ['required', 'integer', 'min:' . DBTiemposPromedio::TOPE_MINIMO, 'max:100000'],
            'ocultar_compiladas' => ['nullable'],
        ]);

        $ruta_id = trim((string) $validado['ruta_id']);
        $tope = max(DBTiemposPromedio::TOPE_MINIMO, (int) $validado['tope']);

        try {
            $resultado = DBTiemposPromedio::compilar($tope, 'propio', $ruta_id);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'No se pudo compilar ' . $ruta_id . ': ' . $e->getMessage());
        }

        if ($resultado->rutas === 0) {
            return back()->with('error', 'Con un tope de ' . $tope . ' min no queda ningún viaje medible en ' . $ruta_id . ': el promedio anterior no se tocó.');
        }

        // la fila recien compilada desaparece si el filtro esta activo, asi que
        // el flag viaja para no perderlo al volver a la pantalla
        $query = ['tope' => $tope, 'modo' => 'global', 'ruta_id' => $ruta_id];
        if ($request->boolean('ocultar_compiladas')) {
            $query['ocultar_compiladas'] = 1;
        }

        return redirect()
            ->route('lupita.tiempos.promedio', $query)
            ->with('success', sprintf(
                '%s compilada con su tope propio de %d min: %d viaje(s) usados, %d descartados, %d celda(s) día/hora.',
                DBTiemposPromedio::nombre($ruta_id),
                $tope,
                $resultado->viajes,
                $resultado->descartados,
                $resultado->celdas
            ));
    }

    /**
     * Mínimo de viajes por celda que se muestra en la tabla (solo lectura).
     */
    private function min_viajes(Request $request): int
    {
        $minimo = (int) $request->get('min_viajes', 1);

        return in_array($minimo, [1, 2, 3, 5, 10], true) ? $minimo : 1;
    }
}

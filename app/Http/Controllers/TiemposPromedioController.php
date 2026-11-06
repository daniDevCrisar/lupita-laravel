<?php

namespace App\Http\Controllers;

use App\Database\DBTiemposPlanta;
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

    /** Minimo de viajes medibles que trae el filtro de la pantalla. */
    private const MIN_VIAJES_DEFECTO = 50;

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
        // las rutas con menos viajes medibles que este minimo no entran
        $min_viajes = $this->minimo_viajes($request);
        // esta pantalla solo compila el recorrido: los topes de espera fuera y
        // dentro de planta viven en la pestana de plantas
        $espera = [];

        $rutas_tabla = DBTiemposPromedio::por_ruta($tope, $modo, $ruta_id, $dep_origen, $dep_destino, $orden, $topes, $ocultar_compiladas, $min_viajes, $espera);

        $datos = (object) [
            'tope' => $tope,
            'modo' => $modo,
            'modos' => DBTiemposPromedio::MODOS,
            'ruta_id' => $ruta_id,
            'dep_origen' => $dep_origen,
            'dep_destino' => $dep_destino,
            'orden' => $orden,
            'ordenes' => DBTiemposPromedio::ORDENES,
            'topes' => $topes,
            'ocultar_compiladas' => $ocultar_compiladas,
            'ocultas' => $rutas_tabla->ocultas,
            'min_viajes' => $min_viajes,
            'ocultas_min' => $rutas_tabla->ocultas_min,
            'perdida' => DBTiemposPromedio::perdida($tope, $modo, $ruta_id, $dep_origen, $dep_destino, $topes, $ocultar_compiladas, $min_viajes, $espera),
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
            'tope' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'modo' => ['nullable', 'in:' . implode(',', array_keys(DBTiemposPromedio::MODOS))],
            'ruta_id' => ['nullable', 'string', 'max:100'],
            'alcance' => ['nullable', 'in:todas,ruta,faltantes,seleccion'],
            'solo_faltantes' => ['nullable'],
            'dep_origen' => ['nullable', 'string', 'max:6'],
            'dep_destino' => ['nullable', 'string', 'max:6'],
            'rutas' => ['nullable', 'array'],
            'rutas.*' => ['string', 'max:100'],
            'orden' => ['nullable', 'string', 'max:20'],
            'ocultar_compiladas' => ['nullable'],
            'topes' => ['nullable', 'array'],
            'min_viajes' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        // topes escritos en la columna "Tope por ruta": ruta => minutos
        $topes = $this->topes($request);
        // sin topes de espera: esta pantalla solo compila el recorrido
        $espera = [];
        $tope = $this->tope($request);
        $modo = $this->modo($request, DBTiemposPromedio::estado()->rutas, $topes);
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

        // el minimo de viajes tambien acota los lotes; la seleccion marcada a
        // mano se compila igual, por si se quiere una ruta con poca muestra
        $min_viajes = $this->minimo_viajes($request);
        if ($alcance === 'seleccion') {
            $solo_rutas = array_values(array_filter(
                array_map('strval', (array) ($validado['rutas'] ?? [])),
                fn ($id) => trim($id) !== ''
            ));
            if ($solo_rutas === []) {
                return back()->with('error', 'Marca al menos una ruta en la tabla para compilar la seleccion.');
            }

            // cada marcada se compila con el tope de su fila: no hay un tope
            // unico que valga para todas, asi que se guarda en modo propio
            if ($topes !== []) {
                $modo = 'propio';
            }
        }

        try {
            // el filtro de departamento solo acota los lotes; una ruta o una
            // seleccion explicita ya vienen elegidas a mano en la pantalla
            $filtro_origen = in_array($alcance, ['ruta', 'seleccion'], true) ? null : $dep_origen;
            $filtro_destino = in_array($alcance, ['ruta', 'seleccion'], true) ? null : $dep_destino;

            $resultado = DBTiemposPromedio::compilar($tope, $modo, $ruta_id, $solo_faltantes, $solo_rutas, $filtro_origen, $filtro_destino, $topes, $alcance === 'seleccion' ? 0 : $min_viajes, $espera);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'No se pudo compilar: ' . $e->getMessage());
        }

        if ($modo === 'propio') {
            $etiqueta_tope = $topes !== []
                ? 'cada una con el tope escrito en su fila'
                : 'con el tope guardado de cada una';
        } elseif ($tope <= 0) {
            // tope 0 no es un corte de cero minutos: no habia un tope global que
            // aplicar, asi que el guardado uso el p82 recomendado de cada ruta
            $etiqueta_tope = $resultado->modo === 'global'
                ? 'sin tope global'
                : 'sin tope global, con el p82 de cada ruta';
        } else {
            $etiqueta_tope = 'con tope de ' . $resultado->tope . ' min';
        }

        $mensaje = sprintf(
            'Se compilaron %d ruta(s) %s: %d viaje(s) usados, %d descartados, %d celda(s) de dia/hora.',
            $resultado->rutas,
            $etiqueta_tope,
            $resultado->viajes,
            $resultado->descartados,
            $resultado->celdas
        );

        if ($resultado->rutas === 0) {
            return back()->with('error', 'No se compilo ninguna ruta: con este tope no queda ningun viaje medible o la tabla ya estaba completa. ' . $mensaje);
        }

        $query = [
            'tope' => $tope,
            // con tope 0 el global cae al p82: la pantalla vuelve con el modo
            // que de verdad se aplico, no con el que estaba marcado
            'modo' => $resultado->modo,
            'ruta_id' => $ruta_id,
            'dep_origen' => $dep_origen,
            'dep_destino' => $dep_destino,
            'orden' => $orden,
            'min_viajes' => $min_viajes,
        ];
        if ($ocultar_compiladas) {
            $query['ocultar_compiladas'] = 1;
        }

        return redirect()
            ->route('lupita.tiempos.promedio', $query)
            ->with('success', $mensaje);
    }

    /**
     * Minimo de viajes medibles por ruta que filtra la pantalla y acota los
     * lotes. Sin valor (o con basura) cae al minimo por defecto; 0 deja pasar
     * todas las rutas.
     */
    private function minimo_viajes(Request $request): int
    {
        $minimo = filter_var($request->get('min_viajes', self::MIN_VIAJES_DEFECTO), FILTER_VALIDATE_INT);

        if ($minimo === false) {
            return self::MIN_VIAJES_DEFECTO;
        }

        return max(0, min(100000, $minimo));
    }

    /**
     * Tope global de la pantalla. Abre en 0 (`DBTiemposPromedio::TOPE_PANTALLA`),
     * que no es un corte de cero minutos: significa "no mando un tope fijo",
     * asi que lo que aplica es el combo de tope a aplicar. Un valor basura o
     * vacio vuelve al 0; lo que se escribe se respeta tal cual.
     */
    private function tope(Request $request): int
    {
        $tope = filter_var($request->get('tope', DBTiemposPromedio::TOPE_PANTALLA), FILTER_VALIDATE_INT);

        if ($tope === false) {
            return DBTiemposPromedio::TOPE_PANTALLA;
        }

        return max(0, min(100000, $tope));
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
     * Tope a aplicar: global, p82 por ruta o el tope de cada ruta (el escrito en
     * la tabla o el que se fijo al compilarla). La pantalla abre en el modo
     * recomendado porque el tope viene en 0, y sin tope propio que aplicar
     * (nadie compilado y nada escrito) se cae tambien al recomendado.
     *
     * @param  array<string, int>  $topes
     */
    private function modo(Request $request, int $rutas_guardadas, array $topes = []): string
    {
        $modo = (string) $request->get('modo', DBTiemposPromedio::MODO_DEFECTO);

        if (!array_key_exists($modo, DBTiemposPromedio::MODOS)) {
            return DBTiemposPromedio::MODO_DEFECTO;
        }

        return ($modo === 'propio' && $rutas_guardadas === 0 && $topes === [])
            ? DBTiemposPromedio::MODO_DEFECTO
            : $modo;
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

    /**
     * Topes de espera de la pantalla: el global de fuera y dentro de planta y
     * los escritos en la fila de cada ruta. Vacio o 0 significa "sin tope".
     *
     * @return array{fuera: ?int, dentro: ?int, fuera_rutas: array<string, int>, dentro_rutas: array<string, int>}
     */
    private function espera(Request $request): array
    {
        return [
            'fuera' => $this->tope_espera($request->get('tope_fuera')),
            'dentro' => $this->tope_espera($request->get('tope_dentro')),
            'fuera_rutas' => $this->topes_filas($request->get('topes_fuera')),
            'dentro_rutas' => $this->topes_filas($request->get('topes_dentro')),
        ];
    }

    /**
     * Un tope de espera en minutos; sin valor o 0 = sin tope (null).
     */
    private function tope_espera($valor): ?int
    {
        $minutos = filter_var($valor, FILTER_VALIDATE_INT);

        if ($minutos === false || $minutos <= 0) {
            return null;
        }

        return min(DBTiemposPromedio::ESPERA_MAXIMO, $minutos);
    }

    /**
     * Topes de espera escritos por ruta: `topes_fuera[ruta_id] = minutos`.
     * Se descarta lo que no sea un entero positivo.
     *
     * @return array<string, int>
     */
    private function topes_filas($entrada): array
    {
        $salida = [];

        foreach ((array) $entrada as $ruta_id => $minutos) {
            $ruta_id = trim((string) $ruta_id);
            $minutos = is_numeric($minutos) ? (int) $minutos : 0;

            if ($ruta_id === '' || strlen($ruta_id) > 100) {
                continue;
            }
            if ($minutos < 1 || $minutos > DBTiemposPromedio::ESPERA_MAXIMO) {
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
        // el horario solo ofrece tope global o p82 por ruta: sin eleccion
        // todavia abre en el recomendado, porque el tope viene en 0
        $modo = $this->modo_horario($request);
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
            'tope' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'modo' => ['nullable', 'in:global,ruta'],
            'ruta_id' => ['nullable', 'string', 'max:100'],
            'alcance' => ['nullable', 'in:todas,ruta'],
            'min_viajes' => ['nullable', 'integer'],
        ]);

        $tope = $this->tope($request);
        $modo = $this->modo_horario($request, $tope);
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
            'Se compilaron %d celda(s) día/hora de %d ruta(s) %s. Los promedios de ruta no se tocaron.',
            $resultado->celdas,
            $resultado->rutas,
            $resultado->tope > 0
                ? 'con tope de ' . $resultado->tope . ' min'
                : 'con el p82 de cada ruta (tope global en 0)'
        );

        if ($resultado->celdas === 0) {
            return back()->with('error', 'No se compiló ninguna celda: con este tope no queda ningún viaje medible. ' . $mensaje);
        }

        return redirect()
            ->route('lupita.tiempos.promedio.horario', [
                'tope' => $tope,
                'modo' => $resultado->modo,
                'ruta_id' => $ruta_id,
                'min_viajes' => $validado['min_viajes'] ?? 1,
            ])
            ->with('success', $mensaje);
    }

    /**
     * Tope a aplicar de la pantalla de horario: global o el p82 de cada ruta.
     * Sin parametro (o con basura) abre en el recomendado, que es el que tiene
     * sentido cuando el tope de arriba esta en 0.
     */
    private function modo_horario(Request $request): string
    {
        $modo = (string) $request->get('modo', DBTiemposPromedio::MODO_DEFECTO);

        return in_array($modo, ['global', 'ruta'], true) ? $modo : DBTiemposPromedio::MODO_DEFECTO;
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
            'min_viajes' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $ruta_id = trim((string) $validado['ruta_id']);
        $tope = max(DBTiemposPromedio::TOPE_MINIMO, (int) $validado['tope']);
        // sin topes de espera: esta ruta solo compila el recorrido
        $espera = [];

        try {
            $resultado = DBTiemposPromedio::compilar($tope, 'propio', $ruta_id, false, [], null, null, [], 0, $espera);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'No se pudo compilar ' . $ruta_id . ': ' . $e->getMessage());
        }

        if ($resultado->rutas === 0) {
            return back()->with('error', 'Con un tope de ' . $tope . ' min no queda ningún viaje medible en ' . $ruta_id . ': el promedio anterior no se tocó.');
        }

        // la fila recien compilada desaparece si el filtro esta activo, asi que
        // el flag viaja para no perderlo al volver a la pantalla
        $query = [
            'tope' => $tope,
            'modo' => 'global',
            'ruta_id' => $ruta_id,
            'min_viajes' => $this->minimo_viajes($request),
        ];
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

    //------------------- promedios por planta -------------------

    /**
     * Pestaña de plantas: la espera fuera y dentro de cada planta, fusionando
     * su papel de carga (E2/E3) y de descarga (E5/E6) en una sola fila.
     *
     * El tope de espera por defecto es el recomendado de cada planta, calibrado
     * para descartar como maximo 18 % de sus lados; tambien se puede fijar uno
     * global o uno por planta.
     */
    public function plantas(Request $request)
    {
        $modo = $this->modo_planta($request);
        $espera = $this->espera($request);
        $planta_id = trim((string) $request->get('planta_id', '')) ?: null;
        $departamento = $this->departamento($request->get('departamento'));
        $orden = $this->orden_planta($request);
        $min_viajes = $this->minimo_viajes_planta($request);
        $ocultar_compiladas = $request->boolean('ocultar_compiladas');

        $tabla = DBTiemposPlanta::por_planta(
            $modo,
            $espera['fuera'],
            $espera['dentro'],
            $planta_id,
            $departamento,
            $orden,
            $espera['fuera_rutas'],
            $espera['dentro_rutas'],
            $min_viajes,
            $ocultar_compiladas
        );

        $datos = (object) [
            'modo' => $modo,
            'modos' => DBTiemposPlanta::MODOS,
            'tope_fuera' => $espera['fuera'],
            'tope_dentro' => $espera['dentro'],
            'topes_fuera' => $espera['fuera_rutas'],
            'topes_dentro' => $espera['dentro_rutas'],
            'planta_id' => $planta_id,
            'departamento' => $departamento,
            'orden' => $orden,
            'ordenes' => DBTiemposPlanta::ORDENES,
            'min_viajes' => $min_viajes,
            'ocultar_compiladas' => $ocultar_compiladas,
            'filas' => $tabla->filas,
            'resumen' => $tabla->resumen,
            'ocultas' => $tabla->ocultas,
            'ocultas_min' => $tabla->ocultas_min,
            'estado' => DBTiemposPlanta::estado(),
            'plantas' => DBTiemposPlanta::plantas(),
            'departamentos' => DBTiemposPromedio::departamentos(),
        ];

        return view('lupita.tiempos_promedio_plantas', compact('datos'));
    }

    /**
     * Compila la tabla de plantas. No toca los promedios de ruta: son tablas
     * distintas, asi que se puede recompilar una sin perder la otra.
     *
     * Alcances: todas las plantas, una sola o solo las marcadas en la tabla.
     * Las marcadas usan el tope escrito en su fila, como en las rutas.
     */
    public function compilar_plantas(Request $request)
    {
        $validado = $request->validate([
            'modo' => ['nullable', 'in:recomendado,global,propio'],
            'tope_fuera' => ['nullable', 'integer', 'min:1', 'max:' . DBTiemposPromedio::ESPERA_MAXIMO],
            'tope_dentro' => ['nullable', 'integer', 'min:1', 'max:' . DBTiemposPromedio::ESPERA_MAXIMO],
            'planta_id' => ['nullable', 'string', 'max:50'],
            'alcance' => ['nullable', 'in:todas,planta,seleccion'],
            'plantas' => ['nullable', 'array'],
            'plantas.*' => ['string', 'max:50'],
            'topes_fuera' => ['nullable', 'array'],
            'topes_dentro' => ['nullable', 'array'],
            'departamento' => ['nullable', 'string', 'max:6'],
            'orden' => ['nullable', 'string', 'max:20'],
            'min_viajes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'ocultar_compiladas' => ['nullable'],
        ]);

        $modo = $this->modo_planta($request);
        $espera = $this->espera($request);
        $alcance = $validado['alcance'] ?? 'todas';
        $planta_id = trim((string) ($validado['planta_id'] ?? '')) ?: null;

        if ($alcance === 'planta' && $planta_id === null) {
            return back()->with('error', 'Elige una planta en el selector para compilar solo esa.');
        }

        $seleccion = [];
        if ($alcance === 'seleccion') {
            $seleccion = array_values(array_filter(
                array_map(fn ($planta) => trim((string) $planta), (array) ($validado['plantas'] ?? [])),
                fn ($planta) => $planta !== ''
            ));

            if ($seleccion === []) {
                return back()->with('error', 'Marca al menos una planta en la tabla para compilar solo las seleccionadas.');
            }

            // las marcadas se compilan con el tope escrito en su fila
            $modo = 'propio';
        }

        try {
            $resultado = DBTiemposPlanta::compilar(
                $modo,
                $espera['fuera'],
                $espera['dentro'],
                $espera['fuera_rutas'],
                $espera['dentro_rutas'],
                $alcance === 'planta' ? $planta_id : null,
                $seleccion
            );
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'No se pudieron compilar las plantas: ' . $e->getMessage());
        }

        $etiqueta_tope = $alcance === 'seleccion'
            ? 'el tope escrito en la fila de cada planta marcada'
            : [
                'recomendado' => 'el recomendado de cada planta (≤18 % descartado)',
                'global' => 'global de ' . ($espera['fuera'] ?? 'sin tope') . ' / ' . ($espera['dentro'] ?? 'sin tope') . ' min',
                'propio' => 'el tope de cada planta',
            ][$modo];

        $total = $resultado->viajes + $resultado->descartados;
        $pct_descartado = $total > 0 ? round(100 * $resultado->descartados / $total, 1) : null;

        $mensaje = sprintf(
            'Se compilaron %d planta(s) con tope %s: %d lado(s) usados y %d descartado(s)%s.',
            $resultado->plantas,
            $etiqueta_tope,
            $resultado->viajes,
            $resultado->descartados,
            $pct_descartado !== null ? ' (' . $pct_descartado . ' %)' : ''
        );

        if ($resultado->plantas === 0) {
            return back()->with('error', 'No se compiló ninguna planta: con estos topes no queda ningún lado medible. ' . $mensaje);
        }

        $query = [
            'modo' => $modo,
            'orden' => $this->orden_planta($request),
            'min_viajes' => $this->minimo_viajes_planta($request),
        ];

        if ($planta_id !== null) {
            $query['planta_id'] = $planta_id;
        }
        $departamento = $this->departamento($validado['departamento'] ?? null);
        if ($departamento !== null) {
            $query['departamento'] = $departamento;
        }
        if ($espera['fuera'] !== null) {
            $query['tope_fuera'] = $espera['fuera'];
        }
        if ($espera['dentro'] !== null) {
            $query['tope_dentro'] = $espera['dentro'];
        }
        if ($request->boolean('ocultar_compiladas')) {
            $query['ocultar_compiladas'] = 1;
        }

        return redirect()
            ->route('lupita.tiempos.promedio.plantas', $query)
            ->with('success', $mensaje);
    }

    /**
     * Borra el promedio guardado de las plantas marcadas en la tabla. Solo
     * toca `tiempos_planta_promedio`: los promedios de ruta no se tocan.
     */
    public function eliminar_plantas(Request $request)
    {
        $validado = $request->validate([
            'plantas' => ['required', 'array', 'min:1'],
            'plantas.*' => ['string', 'max:50'],
        ], [
            'plantas.required' => 'Marca al menos una planta en la tabla para eliminar su promedio.',
        ]);

        $seleccion = array_values(array_filter(
            array_map(fn ($planta) => trim((string) $planta), (array) $validado['plantas']),
            fn ($planta) => $planta !== ''
        ));

        if ($seleccion === []) {
            return back()->with('error', 'Marca al menos una planta en la tabla para eliminar su promedio.');
        }

        try {
            $borradas = DBTiemposPlanta::eliminar($seleccion);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'No se pudieron eliminar los promedios: ' . $e->getMessage());
        }

        $mensaje = sprintf(
            'Se eliminó el promedio de %d planta(s) de las %d marcada(s).',
            $borradas,
            count($seleccion)
        );

        if ($borradas === 0) {
            return back()->with('error', 'Las plantas marcadas no tenían promedio guardado. ' . $mensaje);
        }

        return redirect()
            ->route('lupita.tiempos.promedio.plantas', $this->filtros_plantas($request))
            ->with('success', $mensaje);
    }

    /**
     * Filtros de la pantalla de plantas, para volver a ella igual que estaba.
     *
     * @return array<string, mixed>
     */
    private function filtros_plantas(Request $request): array
    {
        $espera = $this->espera($request);

        $query = [
            'modo' => $this->modo_planta($request),
            'orden' => $this->orden_planta($request),
            'min_viajes' => $this->minimo_viajes_planta($request),
        ];

        $planta_id = trim((string) $request->get('planta_id', '')) ?: null;
        if ($planta_id !== null) {
            $query['planta_id'] = $planta_id;
        }

        $departamento = $this->departamento($request->get('departamento'));
        if ($departamento !== null) {
            $query['departamento'] = $departamento;
        }
        if ($espera['fuera'] !== null) {
            $query['tope_fuera'] = $espera['fuera'];
        }
        if ($espera['dentro'] !== null) {
            $query['tope_dentro'] = $espera['dentro'];
        }
        if ($request->boolean('ocultar_compiladas')) {
            $query['ocultar_compiladas'] = 1;
        }

        return $query;
    }

    /**
     * Modo del tope de espera por planta; cualquier valor raro cae al recomendado.
     */
    private function modo_planta(Request $request): string
    {
        $modo = (string) $request->get('modo', 'recomendado');

        return array_key_exists($modo, DBTiemposPlanta::MODOS) ? $modo : 'recomendado';
    }

    /**
     * Orden de la tabla por planta; cualquier valor raro cae al de por defecto.
     */
    private function orden_planta(Request $request): string
    {
        $orden = (string) $request->get('orden', 'viajes');

        return array_key_exists($orden, DBTiemposPlanta::ORDENES) ? $orden : 'viajes';
    }

    /**
     * Minimo de lados medibles por planta que filtra la tabla. Por defecto no
     * filtra: hay plantas con pocos viajes que igual interesa ver.
     */
    private function minimo_viajes_planta(Request $request): int
    {
        $minimo = filter_var($request->get('min_viajes', 0), FILTER_VALIDATE_INT);

        if ($minimo === false) {
            return 0;
        }

        return max(0, min(100000, $minimo));
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

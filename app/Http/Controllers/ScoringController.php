<?php

namespace App\Http\Controllers;

use App\Database\DBPalabrasLlamadas as Palabras;
use App\Database\DBScoringLlamadas as Scoring;
use App\Database\DBTiemposRuta;
use App\Tools\ExcelTool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pantallas del scoring por palabras: la de configuracion (pesos por tipo de
 * llamada) y la de evaluacion (puntaje del modelo vs lo que clasifico el humano).
 *
 * Metodos estaticos, como PalabrasController: la capa de datos (`DBScoringLlamadas`)
 * tambien es estatica y guarda el filtro en una propiedad de clase, asi que el
 * controlador no necesita estado.
 */
class ScoringController extends Controller
{
    /**
     * Filtros comunes de las dos paginas. `fuente`, `max_gram` y `min_llamadas`
     * no estan aqui: las reglas de texto las decide la pagina de palabras.
     */
    private static function validar_filtros(Request $request): void
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'llamada_tipo_id' => 'nullable|numeric',
            'conductor' => 'nullable|string|max:100',
            'trt' => 'nullable|string|max:100',
            'ruta_id' => 'nullable|numeric',
            'universo' => 'nullable|string|max:20',
            'umbral' => 'nullable|numeric|between:1,99',
            'vista' => 'nullable|string|max:20',
            'offset' => 'nullable|integer|min:0',
            'max_gram' => 'nullable|integer|between:1,5',
            'incluir_sin_texto' => 'nullable|boolean',
            'palabra' => 'nullable|string|max:80',
        ]);
    }

    //=================== evaluacion ===================

    /**
     * Modelos vs humano. Lee los puntajes ya guardados: no tokeniza nada, asi que
     * abrir la pagina es barato aunque el rango tenga 24 mil llamadas.
     */
    public static function index(Request $request)
    {
        self::validar_filtros($request);

        $scoring = new Scoring();
        $scoring::set_filtro($request);

        @ini_set('memory_limit', '512M');
        DB::statement('SET SESSION wait_timeout = 600');

        $datos = $scoring::comparar();

        return view('lupita.scoring', [
            'scoring' => $scoring,
            'datos' => $datos,
            'auditoria' => $scoring::auditar_pesos($datos->filas),
            'rutas' => DBTiemposRuta::rutas_lista(),
            'tipos' => Palabras::tipos_lista(),
        ]);
    }

    /**
     * Unica pasada que tokeniza y escribe `scoring_llamadas`. Se corre a pedido
     * desde el boton "Calcular rango", no en cada lectura de la pagina.
     */
    public static function calcular(Request $request)
    {
        self::validar_filtros($request);

        $scoring = new Scoring();
        $scoring::set_filtro($request);

        $res = $scoring::calcular();

        $mensaje = 'Puntadas ' . number_format($res->guardadas) . ' llamadas en '
            . $res->segundos . ' s · con texto ' . number_format($res->por_motivo['ok'])
            . ' · sin texto ' . number_format($res->por_motivo['sin_texto'])
            . ' · buzón ' . number_format($res->por_motivo['buzon'])
            . ' · análisis sugerido ' . number_format($res->sugerencias)
            . ($res->obsoletas > 0
                ? ' · quedan ' . number_format($res->obsoletas) . ' de otros rangos por recalcular'
                : '');

        // el contador se guio por los filtros de la request: se devuelven tales
        // cuales para volver a ver el mismo rango
        return redirect()->route('lupita.scoring', $request->query())
            ->with('exito', $mensaje);
    }

    /**
     * Desacuerdos del rango en .xlsx, con los pesos que dispararon cada llamada.
     */
    public static function exportar(Request $request)
    {
        self::validar_filtros($request);

        $scoring = new Scoring();
        $scoring::set_filtro($request);

        $filas = [];

        foreach ($scoring::desacuerdos(null, Scoring::MAX_FILAS * 5) as $f) {
            $filas[] = [
                (string) $f->created_at,
                (string) ($f->tipo_nombre ?? ''),
                (string) $f->conductor,
                (string) $f->trt,
                $f->puntaje === null ? '' : round((float) $f->puntaje, 1),
                (int) $f->humano_exitosa === 1 ? 'éxito' : 'fallida',
                round(Scoring::error($f), 1),
                (string) ($f->disparos_texto ?? ''),
                Palabras::porcentaje((float) $f->base_pct, 1),
                (string) $f->motivo,
                (string) $f->audio_link,
                (string) $f->vapi_id,
            ];
        }

        $xlsx = ExcelTool::xlsx([
            'Fecha', 'Tipo', 'Conductor', 'Transportista', 'Puntaje modelo',
            'Dijo el humano', 'Error', 'Palabras que dispararon', 'Base del tipo',
            'Motivo', 'Audio', 'vapi_id',
        ], $filas, 'Desacuerdos');

        $nombre = 'scoring_desacuerdos_' . $scoring::rango_etiqueta() . '.xlsx';

        return response($xlsx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $nombre . '"',
        ]);
    }

    //=================== configuracion ===================

    /**
     * Tabla de pesos editable, con el efecto medido de cada entrada en el rango.
     */
    public static function pesos(Request $request)
    {
        self::validar_filtros($request);

        $scoring = new Scoring();
        $scoring::set_filtro($request);

        DB::statement('SET SESSION wait_timeout = 600');

        // una sola lectura de las filas guardadas: de ahi salen el efecto medido de
        // cada peso y la concordancia de la caja inferior
        $filas = $scoring::filas_rango();

        return view('lupita.scoring_pesos', [
            'scoring' => $scoring,
            'pesos' => $scoring::listar_pesos(),
            'tipos' => $scoring::tipos_con_volumen(),
            'efectos' => $scoring::auditar_pesos($filas),
            'concordancia' => $scoring::concordancia($filas),
            'bases' => $scoring::base_por_tipo(),
            'params' => $scoring::parametros_sugerencia(),
            'version' => $scoring::pesos_version(),
            'obsoletas' => $scoring::llamadas_obsoletas(),
            'rutas' => DBTiemposRuta::rutas_lista(),
        ]);
    }

    /**
     * Documentación en línea del modelo: explica la fórmula, cómo se lee un peso y
     * cómo se evalúa contra el humano. Solo lee (base, versión, obsoletas) para
     * mostrar números reales del rango, nunca tokeniza ni escribe.
     */
    public static function ayuda(Request $request)
    {
        self::validar_filtros($request);

        $scoring = new Scoring();
        $scoring::set_filtro($request);

        return view('lupita.scoring_ayuda', [
            'scoring' => $scoring,
            'bases' => $scoring::base_por_tipo(),
            'tipos' => $scoring::tipos_con_volumen(),
            'activos' => $scoring::pesos_activos(),
            'version' => $scoring::pesos_version(),
            'obsoletas' => $scoring::llamadas_obsoletas(),
        ]);
    }

    /**
     * Listado del rango en su propia pantalla: todas las llamadas puntuadas,
     * filtrables y paginadas en SQL (sin cargar el rango entero como `index()`).
     */
    public static function llamadas(Request $request)
    {
        self::validar_filtros($request);

        $request->validate([
            'puntaje_min' => 'nullable|numeric|between:0,100',
            'puntaje_max' => 'nullable|numeric|between:0,100',
            'motivo' => 'nullable|string|max:16',
            'cruce' => 'nullable|string|max:16',
            'audio_codigo' => 'nullable|string|max:60',
            'transcripcion_codigo' => 'nullable|string|max:60',
            'palabra' => 'nullable|string|max:80',
            'orden' => 'nullable|string|max:16',
            'omitir_buzon' => 'nullable|boolean',
        ]);

        $scoring = new Scoring();
        $scoring::set_filtro($request);

        $extra = [
            'puntaje_min' => $request->puntaje_min,
            'puntaje_max' => $request->puntaje_max,
            'motivo' => $request->motivo,
            'cruce' => $request->cruce,
            'audio_codigo' => $request->audio_codigo,
            'transcripcion_codigo' => $request->transcripcion_codigo,
            'palabra' => $request->palabra,
            'orden' => $request->orden,
            'omitir_buzon' => (string) $request->omitir_buzon === '1',
        ];

        $datos = $scoring::listar_llamadas_rango($extra, $scoring::offset(), Scoring::LISTA_POR_PAGINA);

        return view('lupita.scoring_llamadas', [
            'scoring' => $scoring,
            'datos' => $datos,
            'tipos' => Palabras::tipos_lista(),
            'codigos' => Palabras::codigos_revisor(),
        ]);
    }

    /**
     * PATCH con el lote de cambios de la tabla en línea.
     *
     * El cuerpo es JSON (como `lupita.audio.guardar`): la tabla guarda ediciones
     * locales en memoria y manda solo lo que cambio.
     */
    public static function guardar_pesos(Request $request): Response
    {
        $request->validate([
            'cambios' => 'present|array',
            'cambios.*.palabra' => 'nullable|string|min:2|max:80',
            'cambios.*.gram' => 'nullable|integer|between:1,5',
            'cambios.*.llamada_tipo_id' => 'nullable|integer|min:0',
            'cambios.*.peso' => 'required|numeric|between:-4,4',
            'cambios.*.activa' => 'nullable|boolean',
            'cambios.*.nota' => 'nullable|string|max:190',
            'borrar' => 'nullable|array',
            'borrar.*' => 'integer|min:1',
        ]);

        $scoring = new Scoring();

        $res = $scoring::guardar_pesos(
            (array) $request->input('cambios', []),
            (array) $request->input('borrar', []),
            (int) $request->user()->id
        );

        return response()->json((array) $res);
    }

    /**
     * Preview de candidatas salidas del analisis de palabras. No escribe.
     */
    public static function sugerir_pesos(Request $request): Response
    {
        self::validar_filtros($request);

        $request->validate([
            'max_gram' => 'nullable|integer|between:1,5',
            'limite' => 'nullable|integer|between:5,100',
        ]);

        $scoring = new Scoring();
        $scoring::set_filtro($request);

        $max_gram = max(1, min(5, (int) $request->input('max_gram', 2)));
        $limite = max(5, min(100, (int) $request->input('limite', 25)));

        // las frases solo se ofrecen si se piden hasta 2 palabras o mas: el objeto
        // de filtro es el mismo que recibe la clase hermana, y ahi se lee `max_gram`
        $scoring::filtro()->max_gram = $max_gram;

        $res = $scoring::sugerir_desde_palabras($limite);
        $res['max_gram'] = $max_gram;

        $nombres = [];
        foreach ($scoring::tipos_con_volumen() as $t) {
            $nombres[$t->id === null ? 'G' : $t->id] = $t->nombre;
        }
        $res['nombres'] = $nombres;

        return response()->json($res);
    }

    /**
     * Inserta solo las casillas marcadas en el modal de preview.
     */
    public static function aplicar_sugeridos(Request $request): Response
    {
        $request->validate([
            'lista' => 'required|array',
            'lista.*.palabra' => 'required|string|min:2|max:80',
            'lista.*.gram' => 'nullable|integer|between:1,5',
            'lista.*.llamada_tipo_id' => 'nullable|integer|min:0',
            'lista.*.peso' => 'required|numeric|between:-4,4',
        ]);

        $scoring = new Scoring();

        $res = $scoring::aplicar_sugeridos(
            (array) $request->input('lista', []),
            (int) $request->user()->id
        );

        return response()->json((array) $res);
    }

    /**
     * Copia el set activo de un tipo a otro sin pisar lo que ya existe.
     */
    public static function copiar_pesos(Request $request): Response
    {
        $request->validate([
            'desde' => 'nullable|integer|min:0',
            'hacia' => 'nullable|integer|min:0',
        ]);

        $desde = $request->input('desde') === null || $request->input('desde') === ''
            ? null : (int) $request->input('desde');
        $hacia = $request->input('hacia') === null || $request->input('hacia') === ''
            ? null : (int) $request->input('hacia');

        $scoring = new Scoring();

        return response()->json([
            'copiadas' => $scoring::copiar_pesos($desde, $hacia, (int) $request->user()->id),
            'version' => $scoring::pesos_version(),
        ]);
    }

    /**
     * Detalle de una llamada para el modal: texto del conductor y pesos aplicados.
     */
    public static function detalle(Request $request): Response
    {
        $request->validate(['vapi_id' => 'required|string|max:40']);

        $scoring = new Scoring();

        return response()->json($scoring::detalle($request->input('vapi_id')));
    }

    /**
     * PATCH de los dos parametros de la sugerencia de analisis (tarjeta propia de
     * la pantalla de Pesos). No mueve el puntaje: solo cambia que se sugiere en la
     * proxima pasada de `calcular()`, asi que el aviso empuja a recalcular.
     */
    public static function guardar_sugerencia(Request $request)
    {
        $request->validate([
            'nota_min' => 'required|integer|between:1,50',
            'nota_top' => 'required|integer|between:20,1000',
        ]);

        $scoring = new Scoring();

        $res = $scoring::guardar_parametros_sugerencia(
            (int) $request->input('nota_min'),
            (int) $request->input('nota_top')
        );

        return redirect()->route('lupita.scoring.pesos', $request->query())
            ->with('exito', 'Parámetros de la sugerencia guardados (fuerza mínima ' . $res['nota_min']
                . ', notas top ' . $res['nota_top'] . ') · corra «Calcular rango» para aplicarlos a las filas guardadas');
    }
}

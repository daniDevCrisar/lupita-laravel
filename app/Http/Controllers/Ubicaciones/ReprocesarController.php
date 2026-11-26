<?php

namespace App\Http\Controllers\Ubicaciones;

use App\Database\DBReprocesar;
use App\Database\DBUbicaciones;
use App\Http\Controllers\Controller;
use App\Ubicaciones\AnalisisTitulos;
use Illuminate\Http\Request;

/**
 * Ubicaciones\ReprocesarController
 *
 * El boton "Encontrar ruta": llama `sp_actualizar_ruta_id` por cada ref elegida.
 *
 * Esta es la unica parte del modulo que ESCRIBE en `rutas` y `referencias` (a
 * traves del SP). Dos guardias:
 *  - si el procedimiento no esta instalado, no se intenta nada y se explica como
 *    instalarlo (nunca un 500);
 *  - las refs que ya tienen `ruta_id` se omiten, porque el SP incrementa
 *    `veces_usada` en cada llamada aunque el resultado sea el mismo.
 *
 * REGLA DE ACCESO: `role:admin` en la ruta.
 */
class ReprocesarController extends Controller
{
    /** Refs por request: un lote tiene que entrar comodo en el timeout de cualquier proxy. */
    public const POR_LOTE = 50;

    /**
     * GET /lupita/ubicaciones/reprocesar/estado
     */
    public function estado()
    {
        $refs = DBUbicaciones::refsSinRuta();
        $calculo = AnalisisTitulos::desdeBase()->calcular($refs);

        return response()->json([
            'disponible'     => DBReprocesar::spDisponible(),
            'pendientes'     => $calculo['refs'],
            'ya_resolverian' => $calculo['ya_resolverian'],
        ]);
    }

    /**
     * POST /lupita/ubicaciones/reprocesar
     *
     * Procesa en LOTES de POR_LOTE refs por request. El motivo no es la velocidad (77
     * ms por lote de 10): es que un solo request con cientos de CALL puede pasarse del
     * timeout del proxy (nginx corta a los 30-60 s) y entonces el usuario no ve nada y
     * cree que el boton no funciona. Con lotes, cada request es corto y el reporte
     * encadena el siguiente (`continuar=1`), acumulando el resultado en la sesion.
     */
    public function procesar(Request $request)
    {
        if (!DBReprocesar::spDisponible()) {
            return redirect()
                ->route('lupita.ubicaciones.titulos')
                ->with('error', 'El procedimiento sp_actualizar_ruta_id no está instalado en esta base. '
                    . 'Ejecutá el bloque activo (V3) de sql/rutas.sql, líneas 243-400, y volvé a intentar.');
        }

        $continuar = $request->boolean('continuar');

        if ($continuar) {
            $acumulado = session('reproceso');

            if (!is_array($acumulado) || ($acumulado['pendientes'] ?? []) === []) {
                return redirect()
                    ->route('lupita.ubicaciones.titulos')
                    ->with('error', 'Se perdió el estado del reproceso en curso (la sesión expiró). '
                        . 'Volvé a apretar el botón desde el listado.');
            }
        } else {
            // `desde=ubigeo`: las refs que la pantalla "Rutas con ubigeo" detecto como
            // mejorables. Se resuelven aca para no mandar 1.600 inputs ocultos.
            $desde = (string) $request->input('desde', '');

            if ($desde === 'ubigeo') {
                $refs = array_keys(AnalisisTitulos::desdeBase()->refsConAmbosLadosLocal(
                    DBUbicaciones::refsDeRutas(array_map(
                        fn (array $r) => $r['id'],
                        DBUbicaciones::rutasConUbigeo()
                    ))
                ));

                if ($refs === []) {
                    return redirect()
                        ->route('lupita.ubicaciones.rutas_ubigeo')
                        ->with('error', 'No hay refs de rutas con ubigeo que hoy resuelvan a un local. '
                            . 'Al dar de alta más locales van a aparecer.');
                }

                $datos = ['refs' => $refs];
            } else {
                $datos = $request->validate([
                    'refs'   => ['required', 'array', 'max:2000'],
                    'refs.*' => ['string', 'regex:/^[A-Z0-9\-]{3,10}$/'],
                ], [
                    'refs.required' => 'No se eligió ninguna referencia.',
                    'refs.max'      => 'Como máximo se procesan 2000 referencias por vez.',
                    'refs.*.regex'  => 'La referencia tiene caracteres que no corresponden.',
                ]);
            }

            // Las refs van DERECHO al procedimiento: el SP es el que decide la ruta.
            // Aca no se filtra ni se bloquea nada por el contenido del titulo (el aviso
            // de palabras clave raras vive en el catalogo, no en este boton).

            $acumulado = [
                'total'       => count($datos['refs']),
                'pendientes'  => array_values($datos['refs']),
                'ok'          => 0,
                'error'       => 0,
                'omitidas'    => [],
                'omitir_con_ruta' => $desde !== 'ubigeo',
                'filas'       => [],
            ];
        }

        // la base puede estar lenta: el lote es chico, pero no se corta por el limite global
        set_time_limit(120);

        $lote = array_splice($acumulado['pendientes'], 0, self::POR_LOTE);
        $reporte = DBReprocesar::procesar($lote, null, $acumulado['omitir_con_ruta'] ?? true);

        $acumulado['ok'] += $reporte['ok'];
        $acumulado['error'] += $reporte['error'];
        $acumulado['omitidas'] = array_merge($acumulado['omitidas'], $reporte['omitidas']);
        $acumulado['filas'] = array_merge($acumulado['filas'], $reporte['filas']);

        $quedan = count($acumulado['pendientes']);

        session($quedan > 0 ? ['reproceso' => $acumulado] : ['reproceso' => null]);

        return view('lupita.ubicaciones.reporte', [
            'reporte' => [
                'total'    => $acumulado['total'],
                'ok'       => $acumulado['ok'],
                'error'    => $acumulado['error'],
                'omitidas' => $acumulado['omitidas'],
                'filas'    => $acumulado['filas'],
            ],
            'quedan'        => $quedan,
            'sp_disponible' => true,
        ]);
    }
}

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
     */
    public function procesar(Request $request)
    {
        $datos = $request->validate([
            'refs'   => ['required', 'array', 'max:700'],
            'refs.*' => ['string', 'regex:/^[A-Z0-9\-]{3,10}$/'],
        ], [
            'refs.required' => 'No se eligió ninguna referencia.',
            'refs.max'      => 'Como máximo se procesan 700 referencias por vez.',
            'refs.*.regex'  => 'La referencia tiene caracteres que no corresponden.',
        ]);

        if (!DBReprocesar::spDisponible()) {
            return redirect()
                ->route('lupita.ubicaciones.titulos')
                ->with('error', 'El procedimiento sp_actualizar_ruta_id no está instalado en esta base. '
                    . 'Ejecutá el bloque activo (V3) de sql/rutas.sql, líneas 243-400, y volvé a intentar.');
        }

        // GUARDIA DEL (ACEITE): LOC_AJEPER_GRUPO_PACIFIC_OIL declara la palabra clave
        // "(ACEITE)", que es un producto y no un cliente. Cuando se corrio el reproceso
        // de verdad, 23 refs de "RECOJO ACEITE GRUPO PATRONA / ALDEBSA / SUPERALIMENTOS"
        // quedaron con la ruta equivocada. Hasta que se limpie esa palabra clave, esas
        // refs no se procesan salvo confirmacion explicita.
        $aceite = $this->refsConAceite($datos['refs']);
        if ($aceite !== [] && (string) $request->input('confirmar_aceite') !== '1') {
            return redirect()
                ->route('lupita.ubicaciones.titulos')
                ->with('error', 'No se procesó nada: ' . count($aceite) . ' refs mencionan ACEITE '
                    . '(' . implode(', ', array_slice($aceite, 0, 8)) . (count($aceite) > 8 ? '…' : '') . '). '
                    . 'La palabra clave "(ACEITE)" de LOC_AJEPER_GRUPO_PACIFIC_OIL las manda a la ruta '
                    . 'equivocada. Limpiá esa palabra clave en el local, o volvé a enviar con la '
                    . 'confirmación explícita.');
        }

        // 691 llamadas a ~7 ms son ~5 s: no hace falta trocear, pero el limite
        // por defecto de PHP (30 s) es corto si la base esta lenta
        set_time_limit(300);

        $reporte = DBReprocesar::procesar($datos['refs']);

        return view('lupita.ubicaciones.reporte', [
            'reporte'       => $reporte,
            'sp_disponible' => true,
        ]);
    }

    /**
     * Refs cuya ref o titulo mencionan ACEITE (el caso que manda a la ruta equivocada).
     *
     * @param array<int, string> $refs
     *
     * @return array<int, string>
     */
    private function refsConAceite(array $refs): array
    {
        $salida = [];

        foreach (array_chunk($refs, 500) as $lote) {
            foreach (\Illuminate\Support\Facades\DB::table('referencias')
                ->whereIn('ref', $lote)
                ->where('titulo_viaje', 'like', '%ACEITE%')
                ->pluck('ref') as $ref) {
                $salida[] = (string) $ref;
            }
        }

        return $salida;
    }
}

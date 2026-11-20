<?php

namespace App\Http\Controllers\Ubicaciones;

use App\Database\DBFusionLocales;
use App\Database\DBLocales;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ubicaciones\FusionLocalesController
 *
 * Pantalla "Fusionar locales" (/lupita/ubicaciones/fusion). Detecta los pares que
 * comparten palabra clave (los que hacen ambiguo el match del SP), deja elegir dos
 * locales a mano, y **exige simular antes de aplicar**.
 *
 * La simulacion es un GET con los dos ids: se puede mirar, compartir por link y
 * repetir. Recien el POST escribe, y lo hace dentro de una transaccion
 * (ver DBFusionLocales::aplicar()).
 *
 * REGLA DE ACCESO: `role:admin` en la ruta.
 */
class FusionLocalesController extends Controller
{
    public function index(Request $request)
    {
        $sobrevive = mb_substr(trim((string) $request->query('sobrevive', '')), 0, 50);
        $se_va = mb_substr(trim((string) $request->query('se_va', '')), 0, 50);

        $plan = null;
        if ($sobrevive !== '' && $se_va !== '') {
            $plan = DBFusionLocales::plan($sobrevive, $se_va);
        }

        return view('lupita.ubicaciones.fusion', [
            'pares'     => DBFusionLocales::pares(),
            'locales'   => DBLocales::listar(),
            'plan'      => $plan,
            'sobrevive' => $sobrevive,
            'se_va'     => $se_va,
        ]);
    }

    public function aplicar(Request $request)
    {
        $datos = $request->validate([
            'sobrevive' => ['required', 'string', 'max:50', Rule::exists('locales', 'id')],
            'se_va'     => ['required', 'string', 'max:50', Rule::exists('locales', 'id')],
        ], [
            'sobrevive.exists' => 'El local que sobrevive no existe.',
            'se_va.exists'     => 'El local que se fusiona no existe.',
            'sobrevive.required' => 'Falta el local que sobrevive.',
            'se_va.required'     => 'Falta el local que se va.',
        ]);

        if ($datos['sobrevive'] === $datos['se_va']) {
            return redirect()
                ->route('lupita.ubicaciones.fusion')
                ->with('error', 'No se puede fusionar un local consigo mismo.');
        }

        $resultado = DBFusionLocales::aplicar($datos['sobrevive'], $datos['se_va']);

        if (!$resultado['ok']) {
            return redirect()
                ->route('lupita.ubicaciones.fusion')
                ->with('error', 'No se fusionó nada: ' . $resultado['error']);
        }

        $plan = $resultado['plan'];

        return redirect()
            ->route('lupita.ubicaciones.fusion')
            ->with('exito', sprintf(
                'Fusionado: %s absorbió a %s. %d ruta(s) repuntadas, %d colapsada(s), %d referencia(s) movidas. '
                . '%s quedó inactivo (no se borra).',
                $plan['sobrevive']['id'],
                $plan['se_va']['id'],
                $plan['rutas_repuntadas'],
                count($plan['rutas_colapsadas']),
                $plan['referencias'],
                $plan['se_va']['id']
            ));
    }
}

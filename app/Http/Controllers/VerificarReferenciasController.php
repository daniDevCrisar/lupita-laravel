<?php

namespace App\Http\Controllers;

use App\Database\DBVerificarReferencias as Verificador;
use App\Tools\ExcelTool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Verificacion masiva de referencias: se pega una lista (hasta 20k refs) y la
 * pantalla devuelve SOLO las refs que no existen y las que existen sin fin de
 * descargue; las cerradas van unicamente como total.
 *
 * La lista vive en sesion: el POST solo la carga y redirige a un GET. Una URL
 * con 5k refs se pasa del limite del servidor, por eso no viajan en el query
 * string.
 */
class VerificarReferenciasController extends Controller
{
    private const CLAVE_REFS = 'verificar_refs';
    private const CLAVE_META = 'verificar_meta';

    /** Pantalla: formulario si no hay lista cargada, resultados si la hay. */
    public function index(Request $request)
    {
        // "Limpiar": se descarta la lista cargada y vuelve el formulario vacio
        if ($request->boolean('limpiar')) {
            $request->session()->forget([self::CLAVE_REFS, self::CLAVE_META]);
            return redirect()->route('lupita.referencias.verificar');
        }

        $refs = (array) $request->session()->get(self::CLAVE_REFS, []);
        $meta = (array) $request->session()->get(self::CLAVE_META, []);

        $datos = null;

        if (!empty($refs)) {
            @ini_set('memory_limit', '512M');
            // el join referencias x rutas x locales puede ser pesado con 5k refs
            DB::statement('SET SESSION wait_timeout = 600');

            $datos = Verificador::verificar($refs);
        }

        return view('lupita.verificar_referencias', [
            'datos' => $datos,
            'meta' => $meta,
            'maxRefs' => Verificador::MAX_REFS,
        ]);
    }

    /** Carga la lista pegada (o leida de un archivo en el navegador) en sesion. */
    public function cargar(Request $request)
    {
        $request->validate([
            'refs' => 'required|string|max:2000000',
        ], [
            'refs.required' => 'Pegue la lista de refs a verificar.',
        ]);

        $parseo = Verificador::parsear(
            (string) $request->input('refs'),
            $request->boolean('primera_columna')
        );

        if (empty($parseo->refs)) {
            return back()->withInput()
                ->with('error', 'No se encontró ninguna ref válida en la lista pegada.');
        }

        $request->session()->put(self::CLAVE_REFS, $parseo->refs);
        $request->session()->put(self::CLAVE_META, [
            // el texto se guarda para poder retocar la lista sin volver a pegarla
            'texto' => mb_substr((string) $request->input('refs'), 0, 200000),
            'primera_columna' => $request->boolean('primera_columna'),
            'duplicadas' => $parseo->duplicadas,
            'ignorados' => $parseo->ignorados,
            'ignorados_total' => $parseo->ignorados_total,
            'truncadas' => $parseo->truncadas,
        ]);

        return redirect()->route('lupita.referencias.verificar');
    }

    /** Solo las refs que fallan (no existen + sin fin) en .xlsx. */
    public function exportar(Request $request)
    {
        $refs = (array) $request->session()->get(self::CLAVE_REFS, []);
        if (empty($refs)) {
            return redirect()->route('lupita.referencias.verificar')
                ->with('error', 'Primero verifique una lista de refs.');
        }

        @ini_set('memory_limit', '512M');
        DB::statement('SET SESSION wait_timeout = 600');

        $datos = Verificador::verificar($refs);
        // las cerradas no se exportan: de ellas solo interesa el total
        $filas = Verificador::solo_fallas($datos['filas']);

        $salida = [];
        foreach ($filas as $f) {
            $salida[] = [
                (string) $f->ref,
                Verificador::ESTADOS_LABELS[$f->estado] ?? $f->estado,
            ];
        }

        $xlsx = ExcelTool::xlsx(['Ref', 'Estado'], $salida, 'Fallas');

        return response($xlsx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="verificacion_refs_' . date('Ymd_His') . '.xlsx"',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Ubicaciones;

use App\Database\DBUbicaciones;
use App\Http\Controllers\Controller;
use App\Ubicaciones\AnalisisTitulos;
use Illuminate\Http\Request;

/**
 * Ubicaciones\PalabrasController
 *
 * Pantalla "Palabras repetidas" (/lupita/ubicaciones/palabras): el ranking de
 * frases candidatas a `palabra_clave` que hoy no resuelven, clasificadas contra
 * el catalogo, con las acciones de crear local, agregar variante, ver titulos y
 * descartar.
 *
 * Lo unico que se persiste son los descartes. Todo lo demas se recalcula en cada
 * request: si se guardara el veredicto, un cambio en `locales` lo dejaria mintiendo.
 *
 * REGLA DE ACCESO: `role:admin` en la ruta.
 */
class PalabrasController extends Controller
{
    public function index()
    {
        $calculo = AnalisisTitulos::desdeBase()->calcular(DBUbicaciones::refsSinRuta());
        $descartes = DBUbicaciones::descartes('palabra');

        $candidatos = [];
        foreach ($calculo['candidatos'] as $fila) {
            $fila['descartado'] = isset($descartes[$fila['clave']]);
            $candidatos[] = $fila;
        }

        return view('lupita.ubicaciones.palabras', [
            'candidatos'   => $candidatos,
            'fragmentos'   => $calculo['detalle_fragmentos'],
            'refs'         => $calculo['refs'],
            'titulos'      => $calculo['titulos'],
            'total_frags'  => $calculo['fragmentos'],
            'ya_resolverian' => $calculo['ya_resolverian'],
            'descartados'  => count($descartes),
            'sp_disponible' => \App\Database\DBReprocesar::spDisponible(),
        ]);
    }

    /**
     * POST /lupita/ubicaciones/palabras/descartar
     */
    public function descartar(Request $request)
    {
        $datos = $request->validate([
            'clave'  => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'texto'  => ['required', 'string', 'max:500'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ], [
            'clave.regex' => 'La clave del candidato no es válida.',
            'texto.required' => 'Falta el texto del candidato.',
        ]);

        $ok = DBUbicaciones::descartar(
            'palabra',
            $datos['clave'],
            $datos['texto'],
            $datos['motivo'] ?? null,
            auth()->id() !== null ? (int) auth()->id() : null
        );

        if (!$ok) {
            return redirect()
                ->route('lupita.ubicaciones.palabras')
                ->with('error', 'No se pudo descartar: falta la tabla ubicaciones_descartes. '
                    . 'Creala con el SQL de sql/ubicaciones.sql.');
        }

        return redirect()
            ->route('lupita.ubicaciones.palabras')
            ->with('exito', 'Candidato descartado: ' . $datos['texto']);
    }

    /**
     * POST /lupita/ubicaciones/palabras/restaurar
     */
    public function restaurar(Request $request)
    {
        $datos = $request->validate([
            'clave' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
        ], [
            'clave.regex' => 'La clave del candidato no es válida.',
        ]);

        $ok = DBUbicaciones::restaurar('palabra', $datos['clave']);

        return redirect()
            ->route('lupita.ubicaciones.palabras')
            ->with($ok ? 'exito' : 'error', $ok
                ? 'Candidato restaurado.'
                : 'No se pudo restaurar: falta la tabla ubicaciones_descartes.');
    }

    /**
     * GET /lupita/ubicaciones/palabras/exportar : CSV de los candidatos.
     */
    public function exportar()
    {
        $calculo = AnalisisTitulos::desdeBase()->calcular(DBUbicaciones::refsSinRuta());

        return response()->streamDownload(function () use ($calculo) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");   // BOM: Excel abre los acentos bien
            fputcsv($salida, ['frase', 'refs', 'titulos', 'veredicto', 'local_sugerido', 'detalle', 'ejemplo_titulo']);

            foreach ($calculo['candidatos'] as $fila) {
                fputcsv($salida, [
                    $fila['frase'],
                    $fila['refs'],
                    $fila['titulos'],
                    $fila['veredicto'],
                    $fila['local_id'] ?? '',
                    $fila['detalle'] ?? '',
                    $fila['ejemplo_titulo'],
                ]);
            }

            fclose($salida);
        }, 'ubicaciones-palabras-' . date('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

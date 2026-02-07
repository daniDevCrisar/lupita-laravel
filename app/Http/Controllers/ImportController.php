<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;


use App\Tools\ExcelTool;
use App\Database\DBCore;
use App\Database\DBColumns;
use App\Database\Tmp\DBTmpLotes;


class ImportController extends Controller
{
    //
    public function index()
    {
        return view('index', [
            'titulo' => '$titulo',
            'usuario' => '$usuario'
        ]);
    }

    public function procesar_excel(\Illuminate\Http\Request $request)
    {

        //$analisar= TextAnalyzer::esBuzonDeVoz(ExcelTool::normalizarTexto(" // user : Después del tono, grabe su mensaje. // bot : Gracias. // bot : Hola, está ahí. // tool_calls :  // bot : Voy a finalizar la llamada. Muchas gracias. // tool_call_result : undefined // bot : Goodbye."));
        //--------procesar el archivo excel --------
        if (!$request->hasFile('excel')) {
            return "No se subió archivo";
        }
        $file = $request->file('excel');
        $data = ExcelTool::leer($file->getRealPath());
        $data = ExcelTool::limpiarExcelHojas($data);
        //---------------- seleccionar la hoja filtrada----------------
        $filas = $data['FILTRADO'] ?? []; //seleccionar la hoja
        if (empty($filas)) {
            return "No existe hoja FILTRADO";
        }
        array_shift($filas);
        //---------------Insertar en tabla temporal--------------------
        $lote_id = ExcelTool::generarLoteId();
        $columnas = DBColumns::tmpLotesDet();
        foreach ($filas as &$row) {
            array_unshift($row, $lote_id);
        }
        unset($row);

        //DD($filas);

        DBCore::insertBatch('tmp_lotes_det', $columnas, $filas);
        $nombre_archivo = $file->getClientOriginalName();
        DBTmpLotes::crear(
            $lote_id,
            $nombre_archivo,''
        );

        //dd(DB::select('SELECT 1'));
        //dd($data);
        //return count($data);

        //return "Archivo recibido: " . $file->getClientOriginalName();
        return redirect()->route('import.paso1', [
            'lote_id' => $lote_id
        ]);

    }

    public function paso_1($lote_id)
    {
        $cabecera = DBTmpLotes::obtenerCabecera($lote_id);
        //dd($cabecera);
        $conductores = DBTmpLotes::obtenerConductoresDuplicados($lote_id);
        $trts = DBTmpLotes::obtenerTransportistasDuplicados($lote_id);
        $llamadas = DBTmpLotes::obtenerDetalle($lote_id);

        $l_exitosas = 0;
        $total = 0;
        foreach ($llamadas as $row) {
            $fila = (array) $row;
            $ultimoValor = end($fila);
            $l_exitosas += (int) $ultimoValor;
            $total++;
        }
        
        $llamadas = [
            'total' => $total,
            'exitosas' => $l_exitosas,
            'fallidas' => $total - $l_exitosas
        ];
        

        return view('import.import_excel_paso_1', [
            'conductores' => $conductores,
            'lote_id' => $lote_id,
            'cabecera' => $cabecera,
            'trts' => $trts,
            'llamadas' => $llamadas
        ]);

    }

}

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

    public function procesar_json()
    {
        return view('import.importar_json');
    }

    public function cargar_excel()
    {
        return view('import.importar_excel');
    }

    public function procesar_excel_llamadas(\Illuminate\Http\Request $request)
    {
        //--------procesar el archivo excel --------
        if (!$request->hasFile('excel')) {
            return "No se subió archivo";
        }
        $file = $request->file('excel');
        $data = ExcelTool::leer($file->getRealPath());
        $data = ExcelTool::limpiarExcelHojas($data);

        //-------procesar la hoja de llamadas--------------
        $llamadas = $data[$request->txt_llamadas] ?? []; //seleccionar la hoja

        $columnas_tmpLotesDet = DBColumns::tmpLotesDet();
        $columnas_excel_llamadas = $llamadas[0] ?? [];
        $col_excel_ordenadas = [];
        //--------buscar columnas requeridas en el excel----------------
        foreach ($columnas_tmpLotesDet as $columna) {
            $columna_n=ExcelTool::normalizarTexto($columna);
            $buscar = array_search($columna_n, $columnas_excel_llamadas);
            if ($buscar) {
                $col_excel_ordenadas[$columna] = $buscar;
                echo "Columna encontrada: " . $columna . " en posición " . $buscar . "<br>";
            } else {
                $col_excel_ordenadas[$columna] = '';
                echo "Columna NO encontrada: " . $columna . "<br>" . $buscar;
            }
        }
        $col_excel_ordenadas['vapi_id']=0;
        //------ generar el array ordenado de llamadas con las columnas requeridas----------------
        $lote_id = ExcelTool::generarLoteId();//generar id lote
        array_shift($llamadas);//eliminar la fila de encabezados
        $llamadas_excel_ord=[];
        foreach ($llamadas as $fila) {
            $fila_ordenada[0] = $lote_id; //agregar el id lote al inicio de cada fila
            $count=0;
            foreach ($col_excel_ordenadas as $columna) {
                if ($count){
                    //echo "Procesando columna: " . $columna . " con índice " . $count. "<br>";
                    if (is_string($columna)) $fila_ordenada[$count] ='';
                    else $fila_ordenada[$count] = $fila[$columna] ?? '';
                }
                $count++;
            }
            $llamadas_excel_ord[] = $fila_ordenada;// llamadas
        }
        //---------------------------------------------------

        dd($llamadas_excel_ord,$col_excel_ordenadas);
    }


}

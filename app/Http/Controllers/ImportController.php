<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;


use App\Tools\ExcelTool;
use App\Database\DBCore;
use App\Database\DBColumns;
use App\Database\Tmp\DBTmpLotes;

use App\Database\DBConductores;

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

        $lote_id = ExcelTool::generarLoteId();
        //-------procesar la hoja de llamadas--------------
        $llamadas = $data[$request->txt_llamadas] ?? []; //seleccionar la hoja
        if ( $llamadas[0][0]=='ID') $llamadas[0][0]='VAPI_ID'; //reemplazar id por vapi_id en la primera columna
        $columnas_tmpLotesDet = DBColumns::tmpLotesDet();
        $llamadas_excel_ord=ExcelTool::ordenarColumnasExcel($columnas_tmpLotesDet, $llamadas,$lote_id);
        //procesar hoja trt
        $referencias = $data[$request->txt_ref] ?? [];
        $cols_tpm_lotes_ref = DBColumns::tmp_lotes_ref();
        $referencias_excel_ord = ExcelTool::ordenarColumnasExcel($cols_tpm_lotes_ref, $referencias,$lote_id);
        //-----------------------------------------------
        //procesar hoja trt_COMPROMISO
        $referencias_compromiso = $data[$request->txt_ref_1] ?? [];
        $cols_tpm_lotes_ref_compromiso = DBColumns::tmp_lotes_ref_compromiso();
        $referencias_c_excel_ord = ExcelTool::ordenarColumnasExcel($cols_tpm_lotes_ref_compromiso, $referencias_compromiso,$lote_id);
        //---------------------------------------------------

        //dd($referencias_c_excel_ord,$referencias_excel_ord,$llamadas_excel_ord);
        //dd($llamadas_excel_ord);
        DBCore::insertBatch('tmp_lotes_det',$columnas_tmpLotesDet, $llamadas_excel_ord);
        DBCore::insertBatch('tmp_lotes_ref',$cols_tpm_lotes_ref, $referencias_excel_ord);
        DBCore::insertBatch('tmp_lotes_ref_compromiso',$cols_tpm_lotes_ref_compromiso, $referencias_c_excel_ord);
        //cabezera del lote
        $nombre_archivo = $file->getClientOriginalName();
        DBTmpLotes::crear(
            $lote_id,
            $nombre_archivo,''
        );

        return redirect()->route('importar.excel.lote', [
            'lote_id' => $lote_id
        ]);


    }

    public function mostrar_lote_importado($lote_id)
    {
        $cabecera = DBTmpLotes::obtenerCabecera($lote_id);
        //dd($cabecera);
        $conductores = DBTmpLotes::obtenerConductoresDuplicados($lote_id);
        $trts = DBTmpLotes::obtenerTransportistasDuplicados($lote_id);
        $llamadas_detalle = DBTmpLotes::obtenerDetalle($lote_id);
        $l_exitosas = 0;
        $total = 0;
        foreach ($llamadas_detalle as $row) {
            $fila = (array) $row;
            $ultimoValor = end($fila);
            $l_exitosas += (int) $ultimoValor;
            $total++;
        }
        
        $llamadas = [
            'total' => $total,
            'exitosas' => $l_exitosas,
            'fallidas' => $total - $l_exitosas,
            'detalle'=> $llamadas_detalle
        ];

        //-----------GENERAR TABLAS---------------------------
        $personas=DBTmpLotes::compararNombres($conductores,'telefono','conductor');//comparar datos parecidos

        $count=0;

        foreach ($personas as $item){
            $accion= DBConductores::buscar_duplicados($item);

            echo $accion['accion'] . ': ' . $item->conductor . ' '. $item->telefono.' - '. $accion['row']->conductor.' ('. $accion['comparar'] .'%)<br>';
            if ($accion['accion']=='nuevo') $id_conductor = DBConductores::crear($item);
            else {
                $id_conductor = $accion['id'];
                if( $accion['accion']== 'actualizar'){
                    if( DBConductores::actualizar($accion['row'])) 
                        echo $accion['id'] . ' actualizado correrctamente <br>';
                    else echo $accion['id'] .' hubo un error al actualizar <br>';
                }
            }
            $personas[$count]->id=$id_conductor;
            
            $count++;
        }
        
        dd($personas);
        //------------------------------------------------------

        //dd($llamadas);

        return view('import.procesar_lote', [
            'conductores' => $conductores,
            'lote_id' => $lote_id,
            'cabecera' => $cabecera,
            'trts' => $trts,
            'llamadas' => $llamadas
        ]);
    }




}

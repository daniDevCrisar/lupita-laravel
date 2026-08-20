<?php

namespace App\Http\Controllers;

use App\Database\DBLlamadas;
use Illuminate\Http\Request;


use App\Tools\ExcelTool;
use App\Tools\BuscarEnArray;
use App\Database\DBCore;
use App\Database\DBColumns;
use App\Database\Tmp\DBTmpLotes;
use App\Database\Tmp\DBTmpLlamadas;

use App\Database\DBConductores;
use App\Database\DBTrts;
use App\Database\DBReferencias;
use Illuminate\Support\Facades\DB;

class ImportController extends Controller
{
    //

    public function procesar_json()
    {
        return view('import.importar_json');
    }

    public function cargar_excel()
    {
        return view('import.importar_excel');
    }

    public function procesar_excel_llamadas(Request $request)
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

        $filas_procesadas=DBCore::insertBatch('tmp_lotes_det',$columnas_tmpLotesDet, $llamadas_excel_ord);
        //procesar hoja trt
        $referencias = $data[$request->txt_ref] ?? [];
        if ( $referencias){
            $cols_tpm_lotes_ref = DBColumns::tmp_lotes_ref();
            $referencias_excel_ord = ExcelTool::ordenarColumnasExcel($cols_tpm_lotes_ref, $referencias,$lote_id);
            DBCore::insertBatch('tmp_lotes_ref',$cols_tpm_lotes_ref, $referencias_excel_ord);
        }

        //-----------------------------------------------
        //procesar hoja trt_COMPROMISO
        $referencias_compromiso = $data[$request->txt_ref_1] ?? [];
        if ( $referencias_compromiso){
            $cols_tpm_lotes_ref_compromiso = DBColumns::tmp_lotes_ref_compromiso();
            $referencias_c_excel_ord = ExcelTool::ordenarColumnasExcel($cols_tpm_lotes_ref_compromiso, $referencias_compromiso,$lote_id);
            DBCore::insertBatch('tmp_lotes_ref_compromiso',$cols_tpm_lotes_ref_compromiso, $referencias_c_excel_ord);
        }

        //---------------------------------------------------

        //dd($referencias_c_excel_ord,$referencias_excel_ord,$llamadas_excel_ord);
        //dd($llamadas_excel_ord);
        //cabezera del lote
        $nombre_archivo = $file->getClientOriginalName();
        DBTmpLotes::crear(
            $lote_id,
            $nombre_archivo . ' ('.$filas_procesadas.'/'. count($llamadas_excel_ord).")" ,''
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

        return view('import.procesar_lote', [
            'conductores' => $conductores,
            'lote_id' => $lote_id,
            'cabecera' => $cabecera,
            'trts' => $trts,
            'llamadas' => $llamadas
        ]);
    }

    public function procesar_importacion_de_lote($lote_id){
        $log='';
        $conductores = DBTmpLotes::obtenerConductoresDuplicados($lote_id);
        $trts = DBTmpLotes::obtenerTransportistasDuplicados($lote_id);
        $llamadas_detalle = DBTmpLotes::obtenerDetalle($lote_id);
        //-----------GENERAR TABLAS---------------------------
        //----------------INSERTAR PRIMERO CONDUCTORES---------------------
        $personas=DBTmpLotes::compararNombres($conductores,'telefono','conductor');//comparar datos parecidos
        $count=0;
        foreach ($personas as $item){
            $accion= DBConductores::buscar_duplicados($item);

            //$log.= $accion['accion'] . ': <br> Buscado ' . $item->conductor . ' '. $item->telefono.' - Encontrado '. $accion['row']->conductor.' ('. $accion['comparar'] .'%)<br>';
            if ($accion['accion']=='nuevo') {
                $id_conductor = DBConductores::crear($item);
                $log.= 'NUEVO: <br>' . $item->conductor . ' '. $item->telefono;
            }
            else {
                $id_conductor = $accion['id'];
                if( $accion['accion']== 'actualizar'){
                    if( DBConductores::actualizar($accion['row'])){
                        $log.= 'ACTUALIZAR: <br> Buscado ' . $item->conductor . ' '. $item->telefono.' - Encontrado '. $accion['row']->conductor.' ('. $accion['comparar'] .'%)<br>';
                        $log.= $accion['id'] . ' actualizado correrctamente <br>';
                    }
                    else $log.= $accion['id'] .' hubo un error al actualizar <br>';
                } else
                    $log.= 'Identico: <br> Encontrado '. $accion['row']->conductor.' ('. $accion['comparar'] .'%)<br>';
            }

            $personas[$count]->id=$id_conductor;
            $count++;
        }
        //insertar TRTS--------------------------
        $count=0;

        foreach ($trts as $item){
            $id_trt=null; //si el nombre esta en blanco
            if ($item->transportista !='') {
                $trt_accion= DBTrts::sp_insertar_o_obtener_trts($item);

                $id_trt=$trt_accion->id;
                $log.= $trt_accion->es_nuevo ? 'trt nuevo': 'trt duplicado';
                $log.= ' <b>' .$trt_accion->id .'</b><br>';
            }
            else $log.= 'trt vacio<br>';

            $trts[$count]->id=$id_trt;
            $count++;
        }

        $refs=DBTmpLotes::obtenerRefsDuplicadas($lote_id); //obtener ref combinadas de compromiso y otras etapas
        //-----------------------insertar llamadas------------------
        $db_llamadas= new DBTmpLlamadas();
        foreach ($llamadas_detalle as $item){
            $id_trt = BuscarEnArray::en_trt($item->transportista, $trts);
            $id_conductor= BuscarEnArray::en_conductor($item->conductor, $personas);
            if (!$id_conductor) $id_conductor=BuscarEnArray::en_conductor($item->conductor, $personas,true);

            //if ($item->vapi_id=='019D689A-1DA4-7778-B4D7-6E7C5E3A73E5') dd($personas,$item,$id_conductor);
            $log.= 'trt id:'.$id_trt.' **** conductor id:'.$id_conductor.'<br>';
            $log.= DBConductores::crear_telefono([ 'id'=> $id_conductor, 'telefono'=>$item->telefono ]);
            $log.=BuscarEnArray::ref_para_agregar_ids($item->ref,$id_trt, $id_conductor, $refs);
            $db_llamadas::importar_llamadas_de_tmp_al_sistema($id_trt,$id_conductor,$lote_id,$item);
        }
        //insertar referencias
        foreach ($refs as $item){
            $ref_procesada=DBReferencias::sp_insertar_o_nueva_referencia($item);
            $log.= $item->ref;
            if ($ref_procesada)
                $log.= $ref_procesada->es_nuevo ? ' ref nueva <br>': ' ref duplicada<br>';
            else $log.= 'ref sin conductor<br>';
        }

        $log.= DBReferencias::actualizar_rutas($lote_id) . '<br>';

        $log.= '<h2>llamadas:'.$db_llamadas::$log->total_llamadas.' ,duplicadas:'. $db_llamadas::$log->total_duplicados .'</h2><br>';
        $log.= 'Sin conductor: ' . $db_llamadas::$log->sin_conductor . '<br>';

        DBTmpLotes::actualizar_procesado($lote_id,"procesados :" . $db_llamadas::$log->total_llamadas . " duplicados: ".$db_llamadas::$log->total_duplicados,1);
        //echo $log;
        //dd($refs);

        //-------MOSTRAR LOG-----------------------------------

        return view('import.procesar_lote_log' , ['log'=>$log]);
        //------------------------------------------------------

    }

    public function lista_lotes(Request $request){

        //ARREGLA EL PROBLEMA DE CONDUCTORES DUPLICADOS Q SE GENERA EN WEB NO EN LOCAL
        $prub='';
        $nuevos = DB::select("SELECT id, nombres ,concat(nombres,'') as 'conductor' FROM conductores WHERE id > 1541 AND activo = 1");
        $count = 0;
        $log='';

//
        $update= "UPDATE llamadas SET conductor_id = ? WHERE conductor_id = ?;";
        $update_2= "UPDATE conductores SET activo = 0 WHERE id = ?;";
        foreach ($nuevos as $nuevo) {
//                echo "pruebaas 1: $nuevo->id <br>";
                $accion= DBConductores::buscar_duplicados($nuevo);
//                echo "pruebaas 2: $nuevo->id <br>";

                $eliminar = ($nuevo->id != $accion['id'] and $accion['accion']!='nuevo') ? 1 : 0;

                $log_data= [$accion['accion'],$accion['buscado'], $accion['comparado'] ,$accion['comparar']  ];

//                if ($log_data[1]!==$log_data[2])  // encontrar solo los q son duplicado de duplicado
                    $log.= "
            <tr>
                <td>$log_data[0]</td>
                <td>{$nuevo->id} - $log_data[1]</td>
                <td>{$accion['row']->id } - $log_data[2]</td>
                <td>$log_data[3]</td>
                <td>nuevo: {$nuevo->id} comparado: {$accion['id']}</td>
                <td>$eliminar</td>
            </tr>";






//                $prub.= "{$nuevo->id} - {$nuevo->nombres} → {$match->id} - {$match->nombres} ({$accion['comparar']}) <br>";
                $count+=1;

                //desactivar conductor
//            if ($eliminar){
//                DB::update($update_2, [$nuevo->id]);
//                DB::update($update, [$accion['id'],$nuevo->id]);
//            }

//            }
        }
        echo "$prub <br> $count" ;

        echo "<table border='1'><thead><th>accion</th><th>buscar</th><th>encontrado</th><th>asercion</th><th>Identico</th></thead>
           <tbody>$log</tbody></table>";



        $lotes = DBTmpLotes::lista(30);
        $llamadas = new DBLlamadas(false);
        return view('import.lista_lotes', [
            'lotes' => $lotes,
            'llamadas' => $llamadas
        ]);
    }

    public function eliminar_lote(Request $request , $id_lote)
    {
        $result=DBTmpLotes::eliminarLote($id_lote);
        return back()->withInput();
    }




}

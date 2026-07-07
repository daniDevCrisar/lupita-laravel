<?php

namespace App\Livewire\LogConductores;

use App\Database\DBConductores;
use App\Database\DBConductoresLog;
use App\Database\DBCore;
use App\Database\DBLlamadas;
use App\Database\DBRutas;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use function PHPUnit\Framework\isEmpty;

class Nuevo extends Component
{
    public $showModal = false;
    public $logData = null;
    protected $listeners = ['nuevoLog' => 'openModal'];

    //------FORM PARA EL POST----------
    public $log_tlfs=[];
    public $log_analisis;
    public $log_accion;
    public $log_respuesta;
    public $log_conclusion;
    public $log_ubicacion;
    public $log_status;
    public $db_core;
    //------------campos a validar
    public $validate_rules = [
    'log_tlfs'              => 'nullable|array',
    'log_tlfs.*'            => 'nullable|string',

    'log_analisis'          => 'nullable|string|max:1000',
    'log_accion'            => 'nullable|string|max:1000',
    'log_respuesta'         => 'nullable|string|max:1000',
    'log_conclusion'        => 'nullable|string|max:255|in:positiva,sin_comunicacion,no_colabora,no_es_su_numero,neutral',
    'log_status'            => 'required|in:EN CURSO,CERRADO,CANCELADO',
    'log_ubicacion'         => 'required|in:LIMA,PROVINCIA',
    ];
    //-----------------------------------



    public function openModal($logData = null)
    {
        $this->logData = $logData;
        //----si es nuevo darle al id null

        if ($logData['id_log_conductor'] ===0){
            $logData['id_log_conductor']=null;
            //------------------------------------------------
            $logData['etiquetas']['data'] =$this->getArrayEtiquetas();
            //-------------CONSULTA DE RUTAS--------------------
            $logData['rutas'] = $this->get_rutas();

            $telefonos = new DBConductores();
            $telefonos = $telefonos::obtenerTelefonos($logData['conductor']['id']);
            $logData['telefonos'] = (array) $telefonos;
            $this->log_status = 'EN CURSO';
            $this->log_ubicacion = 'LIMA';
        }
        else {
            //modificar--------
            $id_log=$logData['id_log_conductor'];
            $logData = DBConductoresLog::get($id_log);
            $logData = json_decode(json_encode($logData),true);
            $logData['fecha_rango'] = [ 0=>$logData['fecha_inicio'], 1=>$logData['fecha_fin']];
            $logData['metricas']    = json_decode($logData['metricas'], true);

            $logData['etiquetas']['data'][1] =json_decode($logData['etiquetas_1'], true);
            $logData['etiquetas']['data'][0] =json_decode($logData['etiquetas_0'], true);

//            $logData['etiquetas_1'] = json_decode($logData['etiquetas_1'], true);
//            $logData['etiquetas_0'] = json_decode($logData['etiquetas_0'], true);
            $logData['telefonos']   = json_decode($logData['telefonos']);
            $logData['rutas']       = json_decode($logData['rutas'],true);

            $logData['conductor'] = ['nombres' =>$logData['conductor_nombres'] , 'id' =>$logData['id_conductor']];
            $logData['trt'] = ['nombres' =>$logData['trt_nombres'] , 'id' =>$logData['last_id_trt']];
            $logData['id_log_conductor']=$id_log;

            $this->log_status = $logData['status'];
            $this->log_ubicacion = $logData['ubicacion'];

            $this->logData = $logData;
//            dd($logData);
        }

        //-------Obtener telefonos de conductor-------------
        $this->log_tlfs=[];
        foreach ($logData['telefonos'] as $item) {
            if (isset($item->activo) && $item->activo == 1)
                $this->log_tlfs[] = $item->telefono;
        }


        $this->log_analisis=$logData['analisis']??'';
        $this->log_accion=$logData['accion']??'';
        $this->log_respuesta=$logData['respuesta']??'';
        $this->log_conclusion=$logData['id_conclusion']??'';


        //-------calcular dif de dias -------
        $logData['fecha_rango'][2] = DBCore::date_diff_dias($logData['fecha_rango'][0],$logData['fecha_rango'][1]);
        $this->db_core = DBCore::class; //si se quiere enviar ala vista
        //-----------------------------------
        $this->logData = (object) $logData;
        $this->showModal = true;
    }

    function getArrayEtiquetas(): array
    {
        //----SEPARAR LAS ETIQUETAS POSITIVAS Y NEGATIVAS PROMERO SOLO PÒR EL PARENTESIS
        $etiq_txt = [];
        $logData = $this->logData;
        foreach ($logData['etiquetas'] as $item) {
            $etiq_txt[] = explode(')',$item);
        }

        //----OBTENER EL ARRAY POR CANT Y NOMBRE DE LAS ETIQUETAS----

        $etiq_list=[];
        foreach ($etiq_txt as $etiq) {
            $etiq_list_item = [];
            foreach ($etiq as $item) {
                $item = trim($item);
                if ($item){
                    $item = explode('(',$item);
                    $etiq_list_item[]=[
                        'nombre' => $item[0],
                        'cantidad' => $item[1],
                    ];
                }
            }
            $etiq_list[]=$etiq_list_item;
        }
        return $etiq_list;
    }

    function get_rutas()
    {
        $logData = $this->logData;
        $llamadas = new DBLlamadas();
        $filtro= $llamadas::request_limpio();
        $filtro->fecha_inicio= $logData['fecha_rango'][0];
        $filtro->fecha_fin= $logData['fecha_rango'][1];
        $llamadas::set_filtro($filtro);

        $rutas = new DBRutas();
        $rutas::$filtro= $llamadas::aplicar_filtro_sqltext('a');
        $rutas_conductor=$rutas::lista_rutas_por_conductor($logData['conductor']['id']);
        $rutas_nombres=[];
        $rutas_total=0;
        foreach ($rutas_conductor as $item) {
            $nombres = $rutas::obtenerNombreRuta($item);
            $rutas_total+=$item->veces_usada;
            $rutas_nombres[]=[
                'nombre' => $nombres[0] . ' - ' . $nombres[1],
                'cantidad' => $item->veces_usada,
            ];
        }
        return ['lista'=>$rutas_nombres , 'total'=>$rutas_total];
    }

    public function closeModal()
    {
        $this->showModal = false;
    }

    public function render()
    {
        return view('livewire.log-conductores.nuevo');
    }

    public function save(){
        $logData = $this->logData;

        // Validar que las fechas no estén vacías
        if (empty($logData->fecha_rango[0]) || empty($logData->fecha_rango[1])) {
            session()->flash('error', 'El rango de fechas (Inicio y Fin) es obligatorio.');
            return;
        }

        // Procesar teléfonos para incluir el estado 'activo'
        // solo a los telefonos seleccionados
        $telefonos_procesados = [];
        if (!empty($logData->telefonos)) {
            foreach ($logData->telefonos as $key => $item) {
                $telefono = $item->telefono;
                $activo = in_array($telefono, $this->log_tlfs) ? 1 : 0; //buscar en array original de la bd
                $telefonos_procesados[] = [
                    'telefono' => $telefono,
                    'activo'   => $activo
                ];
                $this->logData->telefonos[$key]->activo = $activo;
            }
        }
        //-------------------------------------------------
        $data=
            [
                'id_log_conductor' => $logData->id_log_conductor,
                'id_conductor' => $logData->conductor['id'],
                'last_id_trt' => $logData->trt['id']?:null,
                'fecha_inicio' => $logData->fecha_rango[0] ?: null,
                'fecha_fin' => $logData->fecha_rango[1] ?: null,
                'metricas' => $logData->metricas ? json_encode($logData->metricas) : null,
                'etiquetas_1' => $logData->etiquetas['data'][1] ? json_encode($logData->etiquetas['data'][1]) : null,
                'etiquetas_0' => $logData->etiquetas['data'][0] ? json_encode($logData->etiquetas['data'][0]) : null,
                'analisis' => $this->log_analisis ?: null,
                'accion' => $this->log_accion ?: null,
                'respuesta' => $this->log_respuesta ?: null,
                'status' => $this->log_status ?: 'EN CURSO',
                'ubicacion' => $this->log_ubicacion ?: 'LIMA',
                'id_conclusion' => $this->log_conclusion ?: null,
                'telefonos' => !empty($telefonos_procesados) ? json_encode($telefonos_procesados) : null,
                'rutas' => $logData->rutas ? json_encode($logData->rutas) : null,
            ];

        $this->log_status = $data['status'];
        $this->log_ubicacion = $data['ubicacion'];

        //-----si se esta intentando agregar un log
        // ----nuevo cuando ya existe mostrar el error
        // ------en la vista
        try {
            $res = DBConductoresLog::upsert($data);
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
            return;
        }
        //--------------------------

        $this->logData->id_log_conductor = $res->id_log_conductor;
        $this->logData->created_at = $res->created_at;
        DBConductores::actualizarLogActivo($logData->conductor['id'], $res->id_log_conductor);

        // Actualizar la tabla de conductores
        if ($data['status'] === 'CERRADO') {
            DBConductores::actualizarLogActivo($logData->conductor['id'], 0);
            $this->closeModal();
        }

        $this->dispatch('updateRenderLogs');
        $this->dispatch('updateRender');
    }



}

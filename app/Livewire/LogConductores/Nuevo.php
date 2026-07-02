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

    //------------campos a validar
    public $validate_rules = [
    'log_tlfs'              => 'nullable|array',
    'log_tlfs.*'            => 'nullable|string',

    'log_analisis'          => 'nullable|string|max:1000',
    'log_accion'            => 'nullable|string|max:1000',
    'log_respuesta'         => 'nullable|string|max:1000',
    'log_conclusion'        => 'nullable|string|max:255|in:positiva,sin_comunicacion,no_colabora,no_es_su_numero,neutral',
    'log_status'            => 'required|in:EN CURSO,CERRADO,CANCELADO',
    'log_ubicacion'         => 'required|in:LIMA,PROVINCIAS',
    ];
    //-----------------------------------



    public function openModal($logData = null)
    {
        $this->logData = $logData;
        //------------------------------------------------
        $logData['etiquetas']['data'] =$this->getArrayEtiquetas();
        //-------------CONSULTA DE RUTAS--------------------
        $logData['rutas'] = $this->get_rutas();
        //-------Obtener telefonos de conductor-------------
        $this->log_tlfs=[];
        $telefonos = new DBConductores();
        $telefonos = $telefonos::obtenerTelefonos($logData['conductor']['id']);
        $logData['telefonos'] = (array) $telefonos;
        foreach ($logData['telefonos'] as $item) {
            $this->log_tlfs[]=$item->telefono; //guardar los datos predeterminados
        }

        //-------calcular dif de dias -------
        $core= new DBCore();
        $logData['fecha_rango'][2] = $core::date_diff_dias($logData['fecha_rango'][0],$logData['fecha_rango'][1]);
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
        if (isEmpty( $logData->etiquetas['data'][1]))
            $logData->etiquetas['data'][1]=null;

        if (isEmpty( $logData->etiquetas['data'][0]))
            $logData->etiquetas['data'][0]=null;

        if (isEmpty( $logData->rutas['lista']))
            $logData->rutas=null;
        else $logData->rutas= json_encode($logData->rutas);

        if (isEmpty( $this->log_tlfs)) $this->log_tlfs=null;

        if (!$logData->fecha_rango[0])$logData->fecha_rango[0]=null;
        if (!$logData->fecha_rango[1])$logData->fecha_rango[1]=null;
        $data=
            [
                'id_log_conductor' => null,
                'id_conductor' => $logData->conductor['id'],
                'last_id_trt' => $logData->trt['id'],
                'fecha_inicio' => $logData->fecha_rango[0] ?? null,
                'fecha_fin' => $logData->fecha_rango[1] ?? null,
                'metricas' => json_encode($logData->metricas),
                'etiquetas_1' => json_encode($logData->etiquetas['data'][1]),
                'etiquetas_0' => json_encode($logData->etiquetas['data'][0]),
                'analisis' => $this->log_analisis ?? null,
                'accion' => $this->log_accion ?? null,
                'respuesta' => $this->log_respuesta ?? null,
                'status' => $this->log_status ?? 'EN CURSO',
                'ubicacion' => $this->log_ubicacion ?? 'LIMA',
                'id_conclusion' => $this->log_conclusion ?? null,
                'telefonos' => json_encode($this->log_tlfs),
                'rutas' => $logData->rutas,
            ];
        return DBConductoresLog::upsert($data);
    }



}

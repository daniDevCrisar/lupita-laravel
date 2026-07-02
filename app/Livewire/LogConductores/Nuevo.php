<?php

namespace App\Livewire\LogConductores;

use App\Database\DBConductores;
use App\Database\DBCore;
use App\Database\DBLlamadas;
use App\Database\DBRutas;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

class Nuevo extends Component
{
    public $showModal = false;
    public $logData = null;

    protected $listeners = ['nuevoLog' => 'openModal'];

    //------FORM PARA EL POST----------
    public $log_tlfs;
    public $log_analisis;
    public $log_accion;
    public $log_respuesta;
    public $log_conclusion;
    public $log_status;
    public $log_ubicacion;
    public $log_fecha_rango;
    public $log_conductor;
    public $log_trt;
    public $log_metricas;
    public $log_rutas;
    public $log_telefonos;
    public $log_etiquetas_0;
    public $log_etiquetas_1;

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

    'log_fecha_rango'       => 'nullable|json',
    'log_conductor'         => 'nullable|json',
    'log_trt'               => 'nullable|json',
    'log_metricas'          => 'nullable|json',
    'log_rutas'             => 'nullable|json',
    'log_telefonos'         => 'nullable|json',
    'log_etiquetas_0'       => 'nullable|json',
    'log_etiquetas_1'       => 'nullable|json',
    ];
    //-----------------------------------



    public function openModal($logData = null)
    {



//
//        array:5 [▼ // app\Livewire\LogConductores\Nuevo.php:17
//          "conductor" => array:2 [▼
//            "id" => 188
//            "nombres" => "MATIAS LEDESMA"
//          ]
//          "trt" => array:2 [▼
//            "id" => "83"
//            "nombres" => "LEDESMA QUISPE MATIAS"
//          ]
//          "fecha_rango" => array:2 [▼
//            0 => null
//            1 => null
//          ]
//          "metricas" => array:3 [▼
//            "exitosas" => "63"
//            "fallidas" => "28"
//            "errores" => "3"
//          ]
//          "etiquetas" => array:2 [▼
//            0 => "Buzon de voz(19) No habla(3) Mala señal(1) Cuelga(8) Confirmacion parcial(2) "
//            1 => "Confirma(62) Da motivos(20) Conversacion Fluida(1) "
//          ]
//        ]
//        $logData['metricas']['total'] = $logData['metricas']['total'];

        //----SEPARAR LAS ETIQUETAS POSITIVAS Y NEGATIVAS PROMERO SOLO PÒR EL PARENTESIS
        $etiq_txt = [];
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
        //------------------------------------------------
        $logData['etiquetas']['data'] =$etiq_list;
        //-------------CONSULTA DE RUTAS--------------------
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
        $logData['rutas'] = ['lista'=>$rutas_nombres , 'total'=>$rutas_total];
        //-----------FIN RUTAS -------------------------------

        //        array:2 [▼ // app\Livewire\LogConductores\Nuevo.php:86
        //  0 => {#1324 ▼
        //        +"ruta_id": "RUT_CARAL-BABEL_CENTRO"
        //        +"veces_usada": 3
        //        +"loc_origen_nombre": "PLANTA CARAL"
        //        +"loc_destino_nombre": "BABEL CENTRO"
        //        +"ubg_origen_nombre": "HUARAL"
        //        +"ubg_destino_nombre": "SAN JUAN DE LURIGANCHO"
        //  }

        //-------Obtener telefonos de conductor-------------
        $telefonos = new DBConductores();
        $telefonos = $telefonos::obtenerTelefonos($logData['conductor']['id']);
        $logData['telefonos'] = (array) $telefonos;

        //-------calcular dif de dias -------
        $core= new DBCore();
        $logData['fecha_rango'][2] = $core::date_diff_dias($logData['fecha_rango'][0],$logData['fecha_rango'][1]);
        //-----------------------------------

//        dd($logData['rutas'], $filtro,$rutas::$filtro);
        $this->logData = (object) $logData;
        $this->showModal = true;
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
        dd(get_object_vars($this));
        dd($this->log_etiquetas_0);
        $validator = Validator::make(request()->all(),$this->validate_rules );

    }

}

<?php

namespace App\Livewire\LogConductores;

use App\Database\DBLlamadas;
use App\Database\DBRutas;
use Livewire\Component;

class Nuevo extends Component
{
    public $showModal = false;
    public $logData = null;

    protected $listeners = ['nuevoLog' => 'openModal'];


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
        //-------------CONSULTA DE RUTAS


        $llamadas = new DBLlamadas();

        $filtro= $llamadas::request_limpio();
        $filtro->fecha_inicio= $logData['fecha_rango'][0];
        $filtro->fecha_fin= $logData['fecha_rango'][1];

        $llamadas::set_filtro($filtro);

        $rutas = new DBRutas();
        $rutas::$filtro= $llamadas::aplicar_filtro_sqltext('a');
        $rutas_conductor=$rutas::lista_rutas_por_conductor($logData['conductor']['id']);
        $logData['rutas'] = (array) $rutas_conductor;

        dd($logData['rutas'], $filtro,$rutas::$filtro);
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
}

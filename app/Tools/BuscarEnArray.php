<?php

namespace App\Tools;

use App\Database\Tmp\DBTmpLotes;

class BuscarEnArray {
    public static function cualquiera_std($buscar,$campo,$array){
        foreach ($array as $item){
            if ($item->$campo== trim($buscar)) return $item;
        }
        return false;
    }

    public static function en_trt($buscar,$array){
        if ($buscar == "") return null;
        foreach ($array as $item){
            //$comparar=DBTmpLotes::similitud($buscar,$item->transportista);
            //if ($comparar==100) return $item->id;
            if ($item->transportista == $buscar) return $item->id;
        }
        echo 'trt '.$buscar .' no encontrado en array!!!!!!!! <br>';
    }

    public static function en_conductor($buscar,$array,$omitir_penalizacion = false){
        if ($buscar == "") return null;
        foreach ($array as $item){
            $comparar=DBTmpLotes::similitud($buscar,$item->conductor,true,$omitir_penalizacion);
            if ($comparar==100) return $item->id;
        }
        echo 'conductor '.$buscar .' no encontrado en array!!!!!! <br>';

    }

    public static function ref_para_agregar_ids($buscar,$id_trt, $id_conduc ,$array){
        if ($buscar == "") return false;
        $count=0;
        foreach ($array as $item){
            if ($item->ref == $buscar) {
                $array[$count]->id_conductor = $id_conduc;
                $array[$count]->id_trt = $id_trt;
                echo "se encontro la ref: " . $buscar .'<br>';
                return true;
            }
            $count++;
        }
        echo "no existe la ref: " . $buscar .'!!<br>';
        return false;
    }

}

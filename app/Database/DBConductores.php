<?php

namespace App\Database;
use Illuminate\Support\Facades\DB;
use App\Database\Tmp\DBTmpLotes;

class DBConductores
{

    public static function crear($row)
    {
        return DB::table('conductores')->insertGetId( [
            'sis_id' => $row->sis_id,
            'vapi_id' => $row->vapi_id,
            'nombres' => DBTmpLotes::normalizar ($row->conductor??$row->nombres,false),
        ]);
    }

    public static function crear_telefono($row){
        try {
            return DB::table('tlf_conductores')->insertGetId( [ 
                'conductor_id' => $row->conductor_id,
                'telefono'=> $row->telefono
            ]);
        } catch (\Exception $e) {
            echo 'error al crear nuevo telefono para id:' . $row->conductor_id . '-' . $row->telefono;
            return false;
        }
    }

    public static function actualizar($row){
        return DB::table('conductores')
            ->where('id', $row->id)
            ->update([
                'sis_id'  => $row->sis_id,
                'vapi_id' => $row->vapi_id,
                'nombres' =>  DBTmpLotes::normalizar ($row->conductor??$row->nombres, false),
            ]);
    }


    public static function buscar_duplicados($row){
        $nom=DBTmpLotes::normalizar( $row->conductor,false);
        $buscar = DB::select("SELECT *
        FROM conductores 
        WHERE activo=1 and
        MATCH(nombres) AGAINST(?)
        ORDER BY (nombres = ?) DESC
        limit 1;",
        [
            $nom,$nom
        ]);

        if (!empty($buscar)) {
            $comparar=DBTmpLotes::similitud($row->conductor, $buscar[0]->nombres);
           //if ($row->conductor=='JORGE DIAZ') dd($buscar);
            // Sí encontró
            if ($comparar == 100) {
                $nuevo_nombre = $buscar[0]->nombres;
                $accion = 'duplicado';
                $row->id=$buscar[0]->id;

                if (strlen($nom) > strlen($buscar[0]->nombres)) {
                    $accion = 'actualizar';
                    $nuevo_nombre = $row->conductor;
                    $row->vapi_id = $buscar[0]->vapi_id;
                    $row->sis_id= $buscar[0]->sis_id;
                    }
                //else $accion = 'duplicado';

                //echo '2- casi iguales ++'. $row->conductor . ' - ' . $buscar[0]->nombres . '++ similitud: ' . $comparar . '%<br>';
                // es un duplicado pero con nombre similar , el string mas pequeño dentro
                //comparar el string mas grande para actualizar el nombre
                //$nuevo_nombre = strlen($row->conductor) > strlen($buscar[0]->nombres) ? $row->conductor : $buscar[0]->nombres;
                $row->conductor = $nuevo_nombre;
                return ['accion' => $accion, 'id' => $buscar[0]->id , 'row' => $row, 'comparar' => $comparar];
            }
            else {
                //echo '2- casi iguales ++'. $row->conductor . ' - ' . $buscar[0]->nombres . '++ similitud: ' . $comparar . '%<br>';
                return ['accion' => 'nuevo', 'id' => null , 'row' => $row, 'comparar' => 0];
                }
        } else {
            // No es un duplicado, insertar nuevo registro
            return ['accion' => 'nuevo', 'id' => null , 'row' => $row, 'comparar' => 0];
        }
    }
            

}

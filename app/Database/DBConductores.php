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
                'conductor_id' => $row['id'],
                'telefono'=> $row['telefono']
            ]);
        } catch (\Exception $e) {
            echo 'error telefono duplicado:' . $row['id'] . '-' . $row['telefono'] . '<br>';
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


    public static function buscar_duplicados($row){ //solo para import
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

    public static function lista_principal($limit=30)
    {
        $query_error = DB::table('llamadas as c')
        ->selectRaw('
        conductor_id,
        SUM(error_origen = -1) as error_desconocido,
        SUM(error_origen = 1) as error_ia,
        SUM(error_origen = 2) as error_red,
        SUM(error_origen = 3) as error_sistema
        ')
        ->where('error_origen','!=',0)
        ->groupBy('conductor_id');


        $query_lista = DB::table('llamadas as a')
        ->join('conductores as b','b.id','=','a.conductor_id')
        ->leftJoinSub($query_error,'c',function($join){
                $join->on('c.conductor_id','=','a.conductor_id');
        })
        ->selectRaw('
        a.conductor_id,
        b.nombres AS conductor,
        COUNT(*) AS total,
        SUM(a.llamada_exitosa=1) AS exitosas,
        SUM(a.llamada_exitosa=0) AS fallidas,
        ROUND(SUM(a.llamada_exitosa=1)/COUNT(*)*100,1) AS tasa_exito,
        SUM(a.llamada_exitosa=1) - SUM(a.llamada_exitosa=0) AS diferencia,

        error_desconocido,
        error_ia,
        error_red,
        error_sistema,

        SUM(a.buzon_de_voz * (a.llamada_exitosa = 0)) AS buzon_de_voz,
        SUM(a.conductor_contesta_pero_no_habla * (a.llamada_exitosa = 0)) AS conductor_contesta_pero_no_habla,
        SUM(a.conductor_no_escucha * (a.llamada_exitosa = 0)) AS conductor_no_escucha,
        SUM(a.conductor_mala_senal * (a.llamada_exitosa = 0)) AS conductor_mala_senal,
        SUM(a.confusion_en_llamada * (a.llamada_exitosa = 0)) AS confusion_en_llamada,
        SUM(a.contesta_otra_persona * (a.llamada_exitosa = 0)) AS contesta_otra_persona,
        SUM(a.numero_equivocado * (a.llamada_exitosa = 0)) AS numero_equivocado,
        SUM(a.conductor_cuelga * (a.llamada_exitosa = 0)) AS conductor_cuelga,
        SUM(a.conductor_no_contesta * (a.llamada_exitosa = 0)) AS conductor_no_contesta,
        SUM(a.conductor_confirma * (a.llamada_exitosa = 0)) AS confirmacion_parcial,
        SUM(a.conductor_conducta_inapropiada * (a.llamada_exitosa = 0)) AS conductor_conducta_inapropiada,

        SUM(razon_finalizacion_id = 5) AS conductor_ocupado,
        SUM(ia_se_confunde * (a.llamada_exitosa = 0)) AS ia_se_confunde,
        SUM(ia_no_escucha * (a.llamada_exitosa = 0)) AS ia_no_escucha,
        SUM(ia_cambio_de_datos * (a.llamada_exitosa = 0)) AS ia_cambio_de_datos,
        SUM(ia_error_interpretacion * (a.llamada_exitosa = 0)) AS ia_error_interpretacion,
        SUM(ia_dice_variable * (a.llamada_exitosa = 0)) AS ia_dice_variable,
        SUM(ia_mala_pronunciacion * (a.llamada_exitosa = 0)) AS ia_mala_pronunciacion,

        SUM(a.conductor_confirma * (a.llamada_exitosa = 1)) AS conductor_confirma,
        SUM(a.conductor_da_motivos * (a.llamada_exitosa = 1)) AS conductor_da_motivos,
        SUM(a.conversacion_fluida * (a.llamada_exitosa = 1)) AS conversacion_fluida,
        SUM(a.llamada_interesante * (a.llamada_exitosa = 1)) AS llamada_interesante
        ')
        ->where('a.error_origen',0)
        ->groupBy('a.conductor_id')
        ->orderByDesc('diferencia')
        ->orderBy('exitosas')
        ->paginate($limit);
        return $query_lista;
    }
}

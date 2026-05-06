<?php

namespace App\Database;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Database\Tmp\DBTmpLotes;
use stdClass;

class DBConductores
{
    public static $filtro;

    public function __construct() {
        return true;
    }

    public static function set_filtro($request): void
    {
        self::$filtro= new stdClass();
        self::$filtro->fecha_inicio=$request->fecha_inicio??'';
        self::$filtro->fecha_fin=$request->fecha_fin??'';
        self::$filtro->llamada_tipo_id=$request->llamada_tipo_id??'';
        self::$filtro->conductor= $request->conductor??'';
        self::$filtro->ordenar_por= $request->ordenar_por??'';
        self::$filtro->orden= $request->orden??'';
        self::$filtro->trt = $request->trt??'';
    }

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
            return 'error telefono duplicado:' . $row['id'] . '-' . $row['telefono'] . '<br>';
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
        $fecha_i =self::$filtro->fecha_inicio;
        $fecha_f= self::$filtro->fecha_fin;
        $tipo_id= self::$filtro->llamada_tipo_id;
        $conductor= strtoupper(self::$filtro->conductor);
        $ordenar_por= self::$filtro->ordenar_por;
        $trt= self::$filtro->trt??'';
        $orden= self::$filtro->orden;
        $orden_txt= $orden ? 'asc':'desc';

        $query_lista = DB::table('llamadas as a')
        ->join('conductores as b','b.id','=','a.conductor_id')
        ->leftJoin(DB::raw("(
        SELECT
            llamadas.conductor_id,
            SUBSTRING_INDEX(GROUP_CONCAT(trts.nombres ORDER BY llamadas.created_at DESC), ',', 1) as nom_trt
        FROM trts
        INNER JOIN llamadas ON llamadas.trt_id = trts.id
        GROUP BY llamadas.conductor_id
        ) as c"),'c.conductor_id','=','a.conductor_id')
        ->selectRaw("
        a.conductor_id,
        b.nombres AS conductor,
        COUNT(*) AS total,
        SUM(a.llamada_exitosa=1) AS exitosas,
        SUM(a.llamada_exitosa=0) AS fallidas,
        ROUND(SUM(a.llamada_exitosa=1)/COUNT(*)*100,1) AS tasa_exito,
        SUM(a.llamada_exitosa=1) - SUM(a.llamada_exitosa=0) + (  ROUND(SUM(a.llamada_exitosa=1)/COUNT(*)*20,0) ) AS diferencia,

        SUBSTRING_INDEX(GROUP_CONCAT(a.telefono ORDER BY a.created_at DESC), ',', 1) as ultimo_tlf,

        c.nom_trt as ultimo_trt,

        SUM(a.error_origen = -1) as error_desconocido,
        SUM(a.error_origen = 1) as error_ia,
        SUM(a.error_origen = 2) as error_red,
        SUM(a.error_origen = 3) as error_sistema,
        SUM(a.error_origen!= 0) AS total_error,

        SUM(a.buzon_de_voz * (a.llamada_exitosa = 0 and error_origen=0)) AS buzon_de_voz,
        SUM(a.conductor_contesta_pero_no_habla * (a.llamada_exitosa = 0  and error_origen=0)) AS conductor_contesta_pero_no_habla,
        SUM(a.conductor_no_escucha * (a.llamada_exitosa = 0 and error_origen=0)) AS conductor_no_escucha,
        SUM(a.conductor_mala_senal * (a.llamada_exitosa = 0 and error_origen=0)) AS conductor_mala_senal,
        SUM(a.confusion_en_llamada * (a.llamada_exitosa = 0 and error_origen=0)) AS confusion_en_llamada,
        SUM(a.contesta_otra_persona * (a.llamada_exitosa = 0 and error_origen=0)) AS contesta_otra_persona,
        SUM(a.numero_equivocado * (a.llamada_exitosa = 0 and error_origen=0)) AS numero_equivocado,
        SUM(a.conductor_cuelga * (a.llamada_exitosa = 0 and error_origen=0)) AS conductor_cuelga,
        SUM(a.conductor_no_contesta * (a.llamada_exitosa = 0 and error_origen=0)) AS conductor_no_contesta,
        SUM(a.conductor_confirma * (a.llamada_exitosa = 0 and error_origen=0)) AS confirmacion_parcial,
        SUM(a.conductor_conducta_inapropiada * (a.llamada_exitosa = 0 and error_origen=0)) AS conductor_conducta_inapropiada,

        SUM(razon_finalizacion_id = 5) AS conductor_ocupado,
        SUM(ia_se_confunde * (!a.llamada_exitosa  and error_origen=0)) AS ia_se_confunde,
        SUM(ia_no_escucha * (!a.llamada_exitosa and error_origen=0)) AS ia_no_escucha,
        SUM(ia_error_interpretacion * (!a.llamada_exitosa  and error_origen=0)) AS ia_error_interpretacion,
        SUM(ia_dice_variable * (!a.llamada_exitosa and error_origen=0)) AS ia_dice_variable,
        SUM(ia_mala_pronunciacion * (!a.llamada_exitosa and error_origen=0)) AS ia_mala_pronunciacion,

        SUM(a.conductor_confirma * a.llamada_exitosa) AS conductor_confirma,
        SUM(a.conductor_da_motivos * a.llamada_exitosa) AS conductor_da_motivos,
        SUM(a.conversacion_fluida * a.llamada_exitosa) AS conversacion_fluida,
        SUM(a.llamada_interesante * a.llamada_exitosa) AS llamada_interesante,

        sum(a.audio_duracion) as audio_duracion
        ")
        ->when($fecha_i or $fecha_f, function ($query) use ($fecha_i, $fecha_f) {
            if ($fecha_i and !$fecha_f)
                $query->whereBetween('a.created_at', [
                    Carbon::parse($fecha_i)->startOfDay(),
                    Carbon::parse($fecha_i)->endOfDay()
                ]);
            elseif ($fecha_i and $fecha_f)
                $query->whereBetween('a.created_at', [
                    Carbon::parse($fecha_i)->startOfDay(),
                    Carbon::parse($fecha_f)->endOfDay()
                ]);
        })
        ->when((string) $tipo_id !='', function ($query) use($tipo_id) {
            $query->where('a.llamada_tipo_id', '=', $tipo_id);
        })
        ->when($conductor !='', function ($query) use($conductor) {
            if ( is_numeric($conductor) )
                $query->where('b.id', '=',$conductor);
            else
                $query->where('b.nombres', 'like','%'. $conductor. '%');
        })
        ->when($trt !='', function ($query) use($trt) {
            $query->whereRaw("COALESCE(a.trt_id, 0) = ?",[$trt]);
        })
        ->groupBy('a.conductor_id')
        ->when($ordenar_por, function ($query) use($ordenar_por,$orden_txt) {
            if ($ordenar_por  == 'llamadas') $query->orderBy('total' , $orden_txt);
            elseif ($ordenar_por == 'exitosas') $query->orderBy('exitosas', $orden_txt);
            elseif ($ordenar_por == 'fallidas') $query->orderBy('fallidas', $orden_txt);
        })
        ->when($ordenar_por=='', function ($query) use($ordenar_por ,$orden_txt) {
            $query->orderBy('diferencia', $orden_txt)->orderBy('exitosas',$orden_txt);
        })
        ->orderBy('b.id', 'desc')
        ->paginate($limit)
        ->withQueryString();
        return $query_lista;
    }
}

<?php

namespace App\Database;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

class DBConductoresLog {
    public static $filtro;

    public static function upsert($data){
        $fields = [
            'id_conductor' => $data['id_conductor'] ?? null,
            'last_id_trt' => $data['last_id_trt'] ?? null,
            'fecha_inicio' => $data['fecha_inicio'] ?? null,
            'fecha_fin' => $data['fecha_fin'] ?? null,
            'metricas' => $data['metricas'] ?? null,
            'etiquetas_1' => $data['etiquetas_1'] ?? null,
            'etiquetas_0' => $data['etiquetas_0'] ?? null,
            'analisis' => $data['analisis'] ?? null,
            'accion' => $data['accion'] ?? null,
            'respuesta' => $data['respuesta'] ?? null,
            'status' => $data['status'] ?? 'EN CURSO',
            'ubicacion' => $data['ubicacion'] ?? 'LIMA',
            'id_conclusion' => $data['id_conclusion'] ?? null,
            'telefonos' => $data['telefonos'] ?? null,
            'rutas' => $data['rutas'] ?? null,
        ];

        if (!empty($data['id_log_conductor'])) {
            // Caso UPDATE: Si el ID existe y no es nulo/vacío
            DB::table('log_conductores')
                ->where('id_log_conductor', $data['id_log_conductor'])
                ->update($fields);

            return self::get($data['id_log_conductor']);
        } else {
            // Caso INSERT: Si el ID es nulo o vacío
            // Comprobación si existen logs activos con el id del conductor
            $existeActivo = DB::table('log_conductores')
                ->where('id_conductor', $data['id_conductor'])
                ->where('status', 'EN CURSO')
                ->exists();

            if ($existeActivo) {
                throw new \Exception("Ya existe un log activo para este conductor.");
            }

            $id = DB::table('log_conductores')->insertGetId($fields);
            return self::get($id);
        }
    }

    public static function get($id_log_conductor){
        return DB::table('log_conductores as l')
            ->join('conductores as c', 'l.id_conductor', '=', 'c.id')
            ->join('trts as t', 'l.last_id_trt', '=', 't.id')
            ->select('l.*', 'c.nombres as conductor_nombres', 't.nombres as trt_nombres')
            ->where('l.id_log_conductor', $id_log_conductor)
            ->first();
    }

    public static function allLogs(){
        $fecha_i =self::$filtro->fecha_inicio;
        $fecha_f= self::$filtro->fecha_fin;
        $tipo_id= self::$filtro->llamada_tipo_id;
        $conductor= strtoupper(self::$filtro->conductor);
        $ordenar_por= self::$filtro->ordenar_por;
        $trt= self::$filtro->trt??'';
        $orden= self::$filtro->orden;
        $orden_txt= $orden ? 'asc':'desc';

        return DB::table('log_conductores as l')
            ->join('conductores as c', 'l.id_conductor', '=', 'c.id')
            ->join('trts as t', 'l.last_id_trt', '=', 't.id')
            ->select('l.*', 'c.nombres as conductor_nombres', 't.nombres as trt_nombres')
            ->when($fecha_i and $fecha_f, function ($query) use ($fecha_i, $fecha_f) {
                $query->whereBetween('l.fecha_inicio', [
                    Carbon::parse($fecha_i)->startOfDay(),
                    Carbon::parse($fecha_f)->endOfDay()]);
                $query->whereBetween('l.fecha_fin', [
                    Carbon::parse($fecha_i)->startOfDay(),
                    Carbon::parse($fecha_f)->endOfDay()]);
            })
            ->get();
//        +"id_log_conductor": 20
//        +"id_conductor": 62
//        +"last_id_trt": 6
//        +"fecha_inicio": "2026-06-01 00:00:00"
//        +"fecha_fin": "2026-06-30 00:00:00"
//        +"metricas": "{"total": 31, "errores": "0", "exitosas": "18", "fallidas": 13}"
//        +"etiquetas_1": "[{"nombre": "Confirma", "cantidad": "18"}, {"nombre": "Da motivos", "cantidad": "2"}]"
//        +"etiquetas_0": "[{"nombre": "Buzon de voz", "cantidad": "12"}, {"nombre": "No contesta", "cantidad": "1"}]"
//        +"analisis": "aaa"
//        +"accion": "aa"
//        +"respuesta": "a"
//        +"status": "EN CURSO"
//        +"ubicacion": "LIMA"
//        +"id_conclusion": 2
//        +"telefonos": "[{"activo": 0, "telefono": "51963103616"}]"
//        +"rutas": "{"lista": [{"nombre": "PLANTA CARAL - CODISAL CALLAO", "cantidad": 11}, {"nombre": "PLANTA CARAL - PLANTA HUACHIPA", "cantidad": 9}, {"nombre": "PLANTA CARAL -  ▶"
//        +"created_at": "2026-07-06 12:16:14"
//        +"updated_at": "2026-07-06 12:16:14"
//        +"conductor_nombres": "LAZARO VARGAS"
//        +"trt_nombres": "JP LOGISTICA"

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

    public static function getColorStatus($status){
        if ($status == 'EN CURSO') return 'warning';
        if ($status == 'FINALIZADO') return 'success';
        if ($status == 'CANCELADO') return 'danger';
    }
}

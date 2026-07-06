<?php

namespace App\Database;
use Illuminate\Support\Facades\DB;

class DBConductoresLog {
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
}

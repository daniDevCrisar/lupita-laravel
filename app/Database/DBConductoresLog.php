<?php

namespace App\Database;
use Illuminate\Support\Facades\DB;

class DBConductoresLog {
    public static function upsert($data){
        return DB::table('log_conductores')->updateOrInsert(
            ['id_log_conductor' => $data['id_log_conductor'] ?? null],
            [
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
            ]
        );

    }
}

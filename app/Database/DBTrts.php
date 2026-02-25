<?php

namespace App\Database;
use Illuminate\Support\Facades\DB;

class DBTrts
{

    public static function crear($row)
    {
        return DB::table('trts')->insertGetId( [
            'sis_id' => $row->sis_id,
            'vapi_id' => $row->vapi_id,
            'nombres' => $row->conductor,
            'ruc' => $row->ruc
        ]);
    }

    public static function sp_insertar_o_obtener_trts($row){
        $accion= DB::select('CALL sp_insertar_o_obtener_trts(?, ?, ?, ?)',
        [
            $row->sis_id,
            $row->vapi_id,
            $row->nombres??$row->transportista,
            $row->ruc
        ]);
        return $accion[0];
    }

}

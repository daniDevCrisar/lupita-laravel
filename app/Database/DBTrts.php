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
        $resultado = DB::select('CALL sp_insertar_o_obtener_trts(?, ?, ?, ?)',
        [
            $sis_id,
            $vapi_id,
            $nombres,
            $ruc
        ]);
    }

}

<?php

namespace App\Database\Tmp;

use Illuminate\Support\Facades\DB;

class DBTmpLotes
{
    public static function crear($lote_id, $nombre, $comentario, $tipo = 1, $usuario_id = 1)
    {
        $sql = "
            INSERT INTO tmp_lotes (
                lote_id,
                usuario_id,
                tipo,
                nombre,
                comentario
            ) VALUES (?, ?, ?, ?, ?)
        ";

        return DB::insert($sql, [
            $lote_id,
            $usuario_id,
            $tipo,
            $nombre,
            $comentario
        ]);
    }

    public static function obtenerCabecera($lote_id)
    {
        $sql = "
        SELECT u.nombres as 'user_nombres' ,a.* FROM `tmp_lotes` a 
        inner join users u
        on u.id = a.usuario_id
        WHERE lote_id=?;
        ";
        $res = DB::select($sql, [$lote_id]);
        return $res ? $res[0] : false;
    }

    public static function obtenerConductoresDuplicados($lote_id){
        $sql="SELECT DISTINCT 
            NULL as 'sis_id',
            NULL as 'vapi_id',
            conductor,
            telefono,
            1 as 'activo',
            CURRENT_TIMESTAMP as created_at
        FROM tmp_lotes_det
        WHERE lote_id=?";

        return DB::select($sql, [$lote_id]);
    }

    public static function obtenerTransportistasDuplicados($lote_id){
        $sql="SELECT DISTINCT 
            NULL as 'sis_id',
            NULL as 'vapi_id',
            transportista,
            1 as 'activo',
            CURRENT_TIMESTAMP as created_at
        FROM tmp_lotes_det
        WHERE lote_id=?";

        return DB::select($sql, [$lote_id]);
    }

    public static function obtenerDetalle($lote_id)
    {
        $sql = "
        SELECT *
        FROM tmp_lotes_det
        WHERE lote_id = ?
        ";
        return DB::select($sql, [$lote_id]);
    }

    public static function existe($lote_id)
    {
        $sql = "
            SELECT 1
            FROM tmp_lotes
            WHERE lote_id = ?
            LIMIT 1
        ";

        return DB::select($sql, [$lote_id]) ? true : false;
    }


}

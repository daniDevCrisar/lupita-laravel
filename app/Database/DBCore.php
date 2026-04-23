<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

class DBCore
{
    public static function query($sql, $params = [])
    {
        return DB::select($sql, $params);
    }

    public static function execute($sql, $params = [])
    {
        return DB::statement($sql, $params);
    }

    /* =============================
       INSERT MASIVO
    ============================= */
    public static function insertBatch($tabla, array $columnas, array $filas)
    {
        if (empty($filas)) return 0;
        $insertData = [];

        foreach ($filas as $fila) {
            if (empty($fila[0])) continue;
            $rowAssoc = [];
            foreach ($columnas as $i => $col) {
                $rowAssoc[$col] = $fila[$i] ?? "";
            }
            $insertData[] = $rowAssoc;
        }

        if (empty($insertData)) return 0;

        return DB::table($tabla)->insertOrIgnore($insertData);
    }

}

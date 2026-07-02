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

    public static function date_diff_dias($start, $end){ //retorna siempre valor absoluto
        $date_1 = strtotime($start);
        $date_2 = strtotime($end);

        $diff = $date_2 - $date_1;
        return abs(floor($diff/(60*60*24))) +1;
        // se suma mas 1 por la diferencia de:
        // ?? 00:00  a ?? 23:59
    }

}

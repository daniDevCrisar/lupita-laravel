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
            $comentario,
        ]);
    }

    public static function actualizar_procesado($lote_id, $comentario,$procesado )
    {
        $sql = "UPDATE tmp_lotes SET comentario=?, procesado=? WHERE lote_id=?";
        return DB::update($sql, [
            $comentario,
            $procesado,
            $lote_id
        ]);
    }

    public static function lista($pagina)
    {
        $sql="
        SELECT u.nombres as 'user_nombres' ,a.* FROM `tmp_lotes` a
        inner join users u
        on u.id = a.usuario_id
        ";
        $lista=DB::table("tmp_lotes as a")
        ->join("users as u", "u.id", "=", "a.usuario_id")
        ->select("u.nombres as user_nombres",'a.*')
        ->orderBy("a.created_at", "desc")
        ->paginate($pagina)
        ->withQueryString();
        return $lista;
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
            CURRENT_TIMESTAMP as created_at,
            CURRENT_TIMESTAMP AS updated_at
        FROM tmp_lotes_det
        WHERE lote_id=?
        ORDER BY conductor";

        return DB::select($sql, [$lote_id]);
    }

    public static function obtenerTransportistasDuplicados($lote_id){
        $sql="SELECT DISTINCT
            NULL as 'sis_id',
            NULL as 'vapi_id',
            '' as ruc,
            transportista,
            1 as 'activo',
            CURRENT_TIMESTAMP as created_at
        FROM tmp_lotes_det
        WHERE lote_id=?";

        return DB::select($sql, [$lote_id]);
    }

    public static function obtenerRefsDuplicadas($lote_id){
        $sql= "
        SELECT
            ref,
            ANY_VALUE(lote_id) AS lote_id,
            ANY_VALUE(trt) AS trt,
            ANY_VALUE(tlf_conductor) AS tlf_conductor,
            ANY_VALUE(titulo_viaje) AS titulo_viaje,
            ANY_VALUE(placa) AS placa,
            ANY_VALUE(fin_descargue) AS fin_descargue,
            ANY_VALUE(inicio_descargue) AS inicio_descargue,
            ANY_VALUE(qr_llegada_destino) AS qr_llegada_destino,
            ANY_VALUE(fin_de_carga) AS fin_de_carga,
            ANY_VALUE(inicio_de_carga) AS inicio_de_carga,
            ANY_VALUE(presenta_para_carga) AS presenta_para_carga,
            MAX(fecha_despachador) AS fecha_despachador
        FROM (
            -- Primer SELECT: tmp_lotes_ref (con NULLs al final)
            SELECT
                lote_id, ref, trt, tlf_conductor, titulo_viaje, placa,
                fin_descargue, inicio_descargue, qr_llegada_destino,
                fin_de_carga, inicio_de_carga, presenta_para_carga,
                NULL AS fecha_despachador
            FROM tmp_lotes_ref

            UNION ALL

            -- Segundo SELECT: tmp_lotes_ref_compromiso (MISMO orden que el primero)
            SELECT
                lote_id, ref, trt, tlf_conductor, titulo_viaje, placa,
                fin_descargue, inicio_descargue, qr_llegada_destino,
                fin_de_carga, inicio_de_carga, presenta_para_carga,
                fecha_despachador
            FROM tmp_lotes_ref_compromiso
        ) AS a
        WHERE lote_id = ? and ref !=''
        GROUP BY a.ref;
        ";
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

    public static function compararNombres($tabla,$campo_1,$campo_2,$omitir_penalizacion = false){
        $cont=1;
        //echo self::similitud( 'sinche roca victor', 'victor daniel sinche roca');
        foreach ($tabla as $row){
            $row_tabla=  $tabla[$cont]??false;
            if ($row_tabla){
                if($row_tabla->$campo_1 == $row->$campo_1){
                    $comparar=self::similitud( $row_tabla->{$campo_2}, $row->$campo_2,true,$omitir_penalizacion);
                    if ($comparar >= 80)
                        //echo 'comp:' . $comparar . ' :' . $row_tabla->{$campo_2} . ' * '.  $row->{$campo_2}.  '<br>';
                        if ( strlen($row_tabla->$campo_1) > strlen($row->$campo_1))
                            unset($tabla[$cont-1]);
                        else
                            unset($tabla[$cont]);
                }
            }
            $cont++;
        }
        return array_merge($tabla);
    }


    public static function normalizar($t , $nombres=true) {
        $t = strtoupper($t);
        $t = iconv('UTF-8', 'ASCII//TRANSLIT', $t); // quita tildes

        // Eliminar palabras completas con límites de palabra (\b)
        if ($nombres){
        $t = preg_replace('/[^A-Z0-9 ]/', '', $t); // quita símbolos
        $patron = '/\b(DE|LAS|LOS|LA|EL|Y|E|DEL|AL)\b/';
        $t = preg_replace($patron, '', $t);
        }
        //--------------------------------

        $t = preg_replace('/\s+/', ' ', $t);       // espacios dobles
        return trim($t);
    }

    public static function similitud($a, $b , $nombres = true , $omitir_penalizacion = false) {

        if ($a==$b)return 100; // si son iguales devolver directamente
        $a = self::normalizar($a , $nombres);
        $b = self::normalizar($b , $nombres);
        if ($a==$b)return 100; // si son iguales devolver directamente

        $palabrasA = explode(' ', $a);
        $palabrasB = explode(' ', $b);

        $count_palabrasA = count($palabrasA);
        $count_palabrasB = count($palabrasB);

        // Determinar cuál es el string más largo
        $textoLargo = $count_palabrasA >= $count_palabrasB ? $a : $b;
        $textoCorto = $count_palabrasA < $count_palabrasB ? $a : $b;
        $palabrasCorto = explode(' ', $textoCorto);

        $puntaje = 0;
        foreach ($palabrasCorto as $palabra) {
            if (strpos(' '.$textoLargo.' ', ' '.$palabra.' ') !== false) { // para palabras totalmente exactas ejm adrian y adriana
                $puntaje++;
            }
        }

        //no ejecutar si la diferia del nombre corto con el nombre largo es mas del doble
        if ($nombres and $count_palabrasA != $count_palabrasB and !$omitir_penalizacion) {
            if ($count_palabrasA + $count_palabrasB != 7 ){ //si l suma es 7 es el unico caso q quede existir de diferencia entre nombres y apellidos sin penalizar
                if ($count_palabrasA > $count_palabrasB){

                    if ($palabrasA[$count_palabrasA-2]!= $palabrasB[$count_palabrasB-1]) {
                        //echo 'penalizando por apellido: ' . $palabrasA[$count_palabrasA-2] . ' vs ' . $palabrasB[$count_palabrasB-1] . '<br>';
                        $puntaje -= 0.65; // penalizar si el apellido no coincide
                    }
                }
                else{
                    if ($palabrasB[$count_palabrasB-2]!= $palabrasA[$count_palabrasA-1]) {
                        //echo 'penalizando por apellido: ' . $palabrasB[$count_palabrasB-2] . ' vs ' . $palabrasA[$count_palabrasA-1] . '<br>';
                        $puntaje -= 0.65; // penalizar si el apellido no coincide
                    }
                }
            }
            else{
                if ($palabrasA[$count_palabrasA-2]!= $palabrasB[$count_palabrasB-2]) {
                    //echo 'nuevo penalizando por apellido: ' . $palabrasA[$count_palabrasA-2] . ' vs ' . $palabrasB[$count_palabrasB-2] . '<br>';
                    $puntaje -= 0.65; // penalizar si el apellido no coincide
                }
            }
        }

        return count($palabrasCorto) > 0
            ? round(($puntaje / count($palabrasCorto)) * 100, 2)
            : 100;
    }


}

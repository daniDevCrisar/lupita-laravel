<?php

namespace App\Database;

use App\Tools\BuscarEnArray;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use stdClass;

class DBRutas
{
    public static $filtro;
    public static $depas = [

        '24' => [ // Tumbes
            'nombre' => 'Tumbes',
            'x' => 8.67,
            'y' => 24.67
        ],

        '20' => [ // Piura
            'nombre' => 'Piura',
            'x' => 11.17,
            'y' => 30.89
        ],

        '14' => [ // Lambayeque
            'nombre' => 'Lambayeque',
            'x' => 15.33,
            'y' => 36.33
        ],

        '06' => [ // Cajamarca
            'nombre' => 'Cajamarca',
            'x' => 23.17,
            'y' => 37.89
        ],

        '01' => [ // Amazonas
            'nombre' => 'Amazonas',
            'x' => 27.17,
            'y' => 31.78
        ],

        '22' => [ // San Martín
            'nombre' => 'San Martín',
            'x' => 37.33,
            'y' => 40.44
        ],

        '13' => [ // La Libertad
            'nombre' => 'La Libertad',
            'x' => 24.17,
            'y' => 45.22
        ],

        '02' => [ // Áncash
            'nombre' => 'Áncash',
            'x' => 30.33,
            'y' => 51.33
        ],

        '10' => [ // Huánuco
            'nombre' => 'Huánuco',
            'x' => 41.50,
            'y' => 52.11
        ],

        '25' => [ // Ucayali
            'nombre' => 'Ucayali',
            'x' => 59.33,
            'y' => 52.67
        ],

        '19' => [ // Pasco
            'nombre' => 'Pasco',
            'x' => 47.17,
            'y' => 56.22
        ],

        '12' => [ // Junín
            'nombre' => 'Junín',
            'x' => 49.17,
            'y' => 62.00
        ],

        '15' => [ // Lima
            'nombre' => 'Lima',
            'x' => 40.83,
            'y' => 64.22
        ],

        '07' => [ // Callao
            'nombre' => 'Callao',
            'x' => 36.67,
            'y' => 62.78
        ],

        '09' => [ // Huancavelica
            'nombre' => 'Huancavelica',
            'x' => 50.33,
            'y' => 68.78
        ],

        '11' => [ // Ica
            'nombre' => 'Ica',
            'x' => 46.67,
            'y' => 75.44
        ],

        '05' => [ // Ayacucho
            'nombre' => 'Ayacucho',
            'x' => 56.50,
            'y' => 75.44
        ],

        '03' => [ // Apurímac
            'nombre' => 'Apurímac',
            'x' => 65.00,
            'y' => 73.56
        ],

        '08' => [ // Cusco
            'nombre' => 'Cusco',
            'x' => 69.67,
            'y' => 69.00
        ],

        '17' => [ // Madre de Dios
            'nombre' => 'Madre de Dios',
            'x' => 80.33,
            'y' => 63.78
        ],

        '04' => [ // Arequipa
            'nombre' => 'Arequipa',
            'x' => 70.00,
            'y' => 82.89
        ],

        '21' => [ // Puno
            'nombre' => 'Puno',
            'x' => 87.00,
            'y' => 81.33
        ],

        '18' => [ // Moquegua
            'nombre' => 'Moquegua',
            'x' => 80.33,
            'y' => 87.44
        ],

        '23' => [ // Tacna
            'nombre' => 'Tacna',
            'x' => 83.83,
            'y' => 91.33
        ],

        '16' => [ // Loreto
            'nombre' => 'Loreto',
            'x' => 51.33,
            'y' => 26.56
        ],

    ];
    public static function lista_principal(){
        $sql ="
        SELECT
            ubigeo,
            SUM(CASE WHEN tipo = 'origen' THEN ruta_count ELSE 0 END) AS origen_ruta_count,
            SUM(CASE WHEN tipo = 'origen' THEN veces_usada ELSE 0 END) AS origen_veces_usada,

            SUM(CASE WHEN tipo = 'destino' THEN ruta_count ELSE 0 END) AS destino_ruta_count,
            SUM(CASE WHEN tipo = 'destino' THEN veces_usada ELSE 0 END) AS destino_veces_usada,


            SUM(exito_1+exito_2+exito_3) as origen_exito,
            SUM(exito_5 + exito_6) as destino_exito

        FROM (
            SELECT
                LEFT(a.ubigeo_origen,2) as ubigeo,
                'origen' as tipo,
                COUNT(DISTINCT a.id) as ruta_count,           -- Rutas únicas
                COUNT(DISTINCT c.ref) as veces_usada,           -- Referencias únicas
                SUM(IF(d.llamada_tipo_id=1 AND d.llamada_exitosa,1,0)) as exito_1,
                SUM(IF(d.llamada_tipo_id=2 AND d.llamada_exitosa,1,0)) as exito_2,
                SUM(IF(d.llamada_tipo_id=3 AND d.llamada_exitosa,1,0)) as exito_3,
                0 as exito_5,
                0 as exito_6
            FROM rutas a
            INNER JOIN referencias c ON c.ruta_id = a.id
            INNER JOIN llamadas d ON d.ref = c.ref
            where 1=1 ";
        $sql_2="

            group by LEFT(a.ubigeo_origen, 2)

            UNION ALL

            SELECT
                LEFT(a.ubigeo_destino,2) as ubigeo,
                'destino' as tipo,
                COUNT(DISTINCT a.id) as ruta_count,           -- Rutas únicas
                COUNT(DISTINCT c.ref) as veces_usada,           -- Referencias únicas
                0 as exito_1,
                0 as exito_2,
                0 as exito_3,
                SUM(IF(d.llamada_tipo_id=5 AND d.llamada_exitosa,1,0)) as exito_5,
                SUM(IF(d.llamada_tipo_id=6 AND d.llamada_exitosa,1,0)) as exito_6
            FROM rutas a
            INNER JOIN referencias c ON c.ruta_id = a.id
            INNER JOIN llamadas d ON d.ref = c.ref
            where 1=1 ";
        $sql_3="
            group by LEFT(a.ubigeo_destino, 2)
        ) AS combinado
        GROUP BY ubigeo;
       ";

        return DB::select($sql.self::$filtro[0].$sql_2. self::$filtro[0] . $sql_3 , array_merge(self::$filtro[1], self::$filtro[1]) );
    }

    public static function lista_rutas_recorridas(){
        $sql_estadisticas ="
        select
            SUM(IF(ref IS NOT NULL,1,0)) as ref_total,
            SUM(IF(ref IS NOT NULL and ruta_id IS NOT NULL,1,0)) as ref_total_ruta ,
            SUM(IF(ref IS NOT NULL and ruta_id IS NOT NULL and etapas != 1 ,1,0)) as ref_sin_conf_ruta ,
            COUNT(DISTINCT CASE WHEN ruta_id IS NOT NULL THEN ruta_id END) as ruta_distintas
        from (select
            b.ref as ref,
            b.ruta_id as ruta_id,
            COUNT(b.ref) as ref_total,
            GROUP_CONCAT(DISTINCT a.llamada_tipo_id) as etapas
        from referencias b
        inner join llamadas a
        on b.ref = a.ref
        where a.created_at >= '2026-05-20 00:00:00' AND a.created_at < '2026-05-21 00:00:00'
        GROUP by a.ref) a;
        ";

        $sql_rutas="
        select
            b.ruta_id as ruta_id , COUNT(b.ruta_id) as veces_usada,
            (select nombre from locales where locales.id = c.origen_id ) as loc_origen_nombre,
            (select nombre from locales where locales.id = c.destino_id ) as loc_destino_nombre,
            (select distrito from ubigeo_distritos where ubigeo_distritos.ubigeo = c.ubigeo_origen ) as ubg_origen_nombre,
            (select distrito from ubigeo_distritos where ubigeo_distritos.ubigeo = c.ubigeo_destino ) as ubg_destino_nombre,
            SUM(a.llamada_exitosa) as total_exito,

            SUM(if (b.compromiso_carga is not null and b.presenta_para_carga is not null , 1,0 ) ) as etapa_1_completadas,
            SUM(ABS(TIMESTAMPDIFF(MINUTE,b.compromiso_carga ,b.presenta_para_carga))) as etapa_1_minutos,
            AVG(ABS(TIMESTAMPDIFF(MINUTE, b.compromiso_carga ,b.presenta_para_carga))) as etapa_1_promedio,

            SUM(if (b.presenta_para_carga is not null and b.inicio_de_carga is not null , 1,0 ) ) as etapa_2_completadas,
            SUM(TIMESTAMPDIFF(MINUTE, b.presenta_para_carga ,b.inicio_de_carga )) as etapa_2_minutos,
            AVG(TIMESTAMPDIFF(MINUTE, b.presenta_para_carga ,b.inicio_de_carga)) as etapa_2_promedio,

                        SUM(if (b.inicio_de_carga is not null and b.inicio_ruta is not null , 1,0 ) ) as etapa_3_completadas,
            SUM(TIMESTAMPDIFF(MINUTE,b.inicio_de_carga,b.inicio_ruta )) as etapa_3_minutos,
            AVG(TIMESTAMPDIFF(MINUTE, b.inicio_de_carga,b.inicio_ruta)) as etapa_3_promedio,

            SUM(if (b.inicio_ruta is not null and b.qr_llegada_destino is not null , 1,0 ) ) as etapa_4_completadas,
            SUM(TIMESTAMPDIFF(MINUTE,b.inicio_ruta, b.qr_llegada_destino )) as etapa_4_minutos,
            AVG(TIMESTAMPDIFF(MINUTE, b.inicio_ruta ,b.qr_llegada_destino)) as etapa_4_promedio,

            SUM(if (b.qr_llegada_destino  is not null and b.inicio_descargue is not null , 1,0 ) ) as etapa_5_completadas,
            SUM(ABS(TIMESTAMPDIFF(MINUTE,b.qr_llegada_destino , b.inicio_descargue )) ) as etapa_5_minutos,
            AVG(ABS(TIMESTAMPDIFF(MINUTE, b.qr_llegada_destino ,b.inicio_descargue )) ) as etapa_5_promedio,

            SUM(if (b.inicio_descargue is not null and b.fin_descargue is not null , 1,0 ) ) as etapa_6_completadas,
            SUM(TIMESTAMPDIFF(MINUTE,b.inicio_descargue ,b.fin_descargue )) as etapa_6_minutos,
            AVG(TIMESTAMPDIFF(MINUTE, b.inicio_descargue ,b.fin_descargue )) as etapa_6_promedio

        from llamadas a
        inner join referencias b
        on b.ref = a.ref
        inner join rutas c
        on c.id=b.ruta_id
        where 1=1";
        $sql_rutas_2="
        group by b.ruta_id
        ORDER BY `veces_usada` DESC
        limit 5;
        ";

        return DB::select($sql_rutas . self::$filtro[0]. $sql_rutas_2 , self::$filtro[1] );
    }

    public static function rutas_resumen(){
        $sql_1 ="
        select
            SUM(IF(ref IS NOT NULL,1,0)) as ref_total,
            SUM(IF(ref IS NOT NULL and ruta_id IS NOT NULL,1,0)) as ref_total_ruta ,
            SUM(IF(ref IS NOT NULL and ruta_id IS NOT NULL and etapas = 1 ,1,0)) as ref_conf_ruta ,
            COUNT(DISTINCT CASE WHEN ruta_id IS NOT NULL THEN ruta_id END) as ruta_distintas
        from (select
            b.ref as ref,
            b.ruta_id as ruta_id,
            GROUP_CONCAT(DISTINCT a.llamada_tipo_id) as etapas
        from referencias b
        inner join llamadas a
        on b.ref = a.ref
        where 1=1 ";
        $sql_2="
        GROUP by a.ref) a;
        ";

        $resumen=DB::select($sql_1 . self::$filtro[0]. $sql_2 , self::$filtro[1] );

        return $resumen[0];
    }

    public static function conductor_mas_rapido()
    {
        $lista_mas_rapido= [];
        $rutas_principales = [
            'Caral a Huachipa' => "'RUT_CARAL-BABEL_MAXO', 'RUT_CARAL-HUACHIPA', 'RUT_CARAL-BABEL_AMAZON'",
            'Huachipa a Caral' => "'RUT_HUACHIPA-CARAL','RUT_BABEL_AMAZON-CARAL','RUT_BABEL_MAXO-CARAL'",
            'Caral a Ves' => "'RUT_CARAL-SALEM_VES'",
            'Caral a Callao' => "'RUT_CARAL-CODISAL_CALLAO','RUT_CARAL-BABEL_CALLAO'",
            'Caral a Puente Piedra' => "'RUT_CARAL-CODISAL_PTE_PIEDRA','RUT_CARAL-BABEL_PUENTE_PIEDRA'",
            'Caral a Huancayo' => "'RUT_CARAL-CODISAL_HUANCAYO'",
            'Caral a Chincha' => "'RUT_CARAL-CODISAL_CHINCHA'",
            'Caral a Ica' => "'RUT_CARAL-CODISAL_ICA'",
            'Caral a la Selva' => "'RUT_CARAL-ECO_SUAREZ'"
        ];

        foreach ($rutas_principales as $key => $value) {
            $sql="
            SELECT conductor_id, nombres, round(promedio,0) as promedio, total_viajes,
                   ROUND(promedio - (promedio * 0.15 * LOG10(total_viajes)), 2) AS puntaje_ajustado
            FROM (
                SELECT conductor_id, b.nombres,
                       AVG(etapa_4_minutos) AS promedio,
                       COUNT(etapa_4_minutos) AS total_viajes
                FROM (
                    SELECT a.ref, MAX(b.conductor_id) AS conductor_id,
                           SUM(TIMESTAMPDIFF(MINUTE, a.inicio_ruta, a.qr_llegada_destino)) AS etapa_4_minutos
                    FROM referencias a
                    INNER JOIN llamadas b ON b.ref = a.ref
                    WHERE a.ruta_id IN ($value)
                    GROUP BY a.ref
                ) a
                INNER JOIN conductores b ON b.id = a.conductor_id
                GROUP BY a.conductor_id, b.nombres
            ) t
            WHERE total_viajes >= 5
            ORDER BY puntaje_ajustado ASC
            LIMIT 3;
            ";
            $lista_mas_rapido[$key]=DB::select($sql);
        }
        return $lista_mas_rapido;

    }

    public static function lista_rutas_por_conductor($conductor_id){

        $sql_rutas="
        select
            b.ruta_id as ruta_id , COUNT(b.ruta_id) as veces_usada,
            (select nombre from locales where locales.id = c.origen_id ) as loc_origen_nombre,
            (select nombre from locales where locales.id = c.destino_id ) as loc_destino_nombre,
            (select distrito from ubigeo_distritos where ubigeo_distritos.ubigeo = c.ubigeo_origen ) as ubg_origen_nombre,
            (select distrito from ubigeo_distritos where ubigeo_distritos.ubigeo = c.ubigeo_destino ) as ubg_destino_nombre
        from llamadas a
        inner join referencias b
        on b.ref = a.ref
        inner join rutas c
        on c.id=b.ruta_id
        where a.conductor_id=? ";
        $sql_rutas_2="
        group by b.ruta_id
        ORDER BY `veces_usada` DESC;
        ";

        array_unshift(self::$filtro[1],$conductor_id); // combinar el array pero el primer indice tiene q ser conductor
        return DB::select($sql_rutas . self::$filtro[0]. $sql_rutas_2 , self::$filtro[1]);
    }

    public static function obtenerNombreRuta($item)
    {
        $loc_destino_nombre= $item->loc_destino_nombre??$item->ubg_destino_nombre;
        $loc_origen_nombre= $item->loc_origen_nombre??$item->ubg_origen_nombre;
        return [$loc_origen_nombre,$loc_destino_nombre];
    }

}

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
            SUM(CASE WHEN tipo = 'destino' THEN veces_usada ELSE 0 END) AS destino_veces_usada
        FROM (
            SELECT
                LEFT(b.distrito_ubigeo, 2) as ubigeo,
                'origen' as tipo,
                COUNT(DISTINCT a.id) as ruta_count,           -- Rutas únicas
                COUNT(DISTINCT c.ref) as veces_usada           -- Referencias únicas
            FROM rutas a
            INNER JOIN locales b ON b.id = a.origen_id
            INNER JOIN referencias c ON c.ruta_id = a.id
            INNER JOIN llamadas d ON d.ref = c.ref
            where 1=1 ";
        $sql_2="

            GROUP BY LEFT(b.distrito_ubigeo, 2)

            UNION ALL

            SELECT
                LEFT(b.distrito_ubigeo, 2) as ubigeo,
                'destino' as tipo,
                COUNT(DISTINCT a.id) as ruta_count,           -- Rutas únicas
                COUNT(DISTINCT c.ref) as veces_usada           -- Referencias únicas
            FROM rutas a
            INNER JOIN locales b ON b.id = a.destino_id
            INNER JOIN referencias c ON c.ruta_id = a.id
            INNER JOIN llamadas d ON d.ref = c.ref
            where 1=1 ";
        $sql_3="
            GROUP BY LEFT(b.distrito_ubigeo, 2)
        ) AS combinado
        GROUP BY ubigeo;
       ";

        return DB::select($sql.self::$filtro[0].$sql_2. self::$filtro[0] . $sql_3 , array_merge(self::$filtro[1], self::$filtro[1]) );
    }

    public static function lista_datos_viaje(){
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

        $sql_viajes="
        select
            b.ruta_id , COUNT(b.ruta_id) as veces_usada,
            (select nombre from locales where locales.id = c.origen_id ) as loc_origen_nombre,
            (select nombre from locales where locales.id = c.destino_id ) as loc_destino_nombre,
            (select distrito from ubigeo_distritos where ubigeo_distritos.ubigeo = c.ubigeo_origen ) as ubg_origen_nombre,
            (select distrito from ubigeo_distritos where ubigeo_distritos.ubigeo = c.ubigeo_destino ) as ubg_destino_nombre,
            SUM(a.llamada_exitosa) as total_exito,


            SUM(if (b.inicio_de_carga is not null and b.inicio_ruta is not null , 1,0 ) ) as fuera_carga_completa,
            SUM(TIMESTAMPDIFF(MINUTE,b.inicio_de_carga,b.inicio_ruta )) as fuera_carga_minutos,
            AVG(TIMESTAMPDIFF(MINUTE, b.inicio_de_carga,b.inicio_ruta)) as fuera_carga_promedio

        from llamadas a
        inner join referencias b
        on b.ref = a.ref
        inner join rutas c
        on c.id=b.ruta_id
        where a.created_at >= '2026-05-20 00:00:00' AND a.created_at < '2026-05-21 00:00:00' and b.ruta_id is not null
        group by b.ruta_id
        ORDER BY `veces_usada` DESC;
        ";

    }

    public static function lista_referencias_con_etapas_de_llamadas(){
        $sql="
        select
			b.inicio_de_carga,
			b.fin_de_carga,
			TIMESTAMPDIFF(MINUTE, b.inicio_de_carga, b.fin_de_carga) as carga,
            a.ref,
            b.ruta_id,
            GROUP_CONCAT(DISTINCT a.llamada_tipo_id) as etapas
        from llamadas a
        inner join referencias b
        on b.ref = a.ref
        where a.created_at >= '2026-05-20 00:00:00' AND a.created_at < '2026-05-21 00:00:00'
        GROUP BY a.ref
        ORDER BY `etapas` ASC;
        ";
    }
}

SELECT
    b.ref,
    b.placa,
    b.conductor_id,
    b.conductor,
    b.ruta_id,
    b.presenta_para_carga,
    b.inicio_de_carga,
    b.inicio_ruta,
    b.qr_llegada_destino,
    b.inicio_descargue,
    b.fin_descargue
FROM (
         SELECT
             r.ref                                   AS ref,
             r.placa                                 AS placa,
             COALESCE(r.conductor_id, lc.conductor_id) AS conductor_id,

             -- clave de agrupamiento: 0 = viaje sin conductor en la referencia ni en sus llamadas
             COALESCE(r.conductor_id, lc.conductor_id, 0) AS conductor_clave,
             r.presenta_para_carga,
             r.inicio_de_carga,
             r.qr_llegada_destino,
             co.nombres                              AS conductor,
             COALESCE(r.trt_id, lc.trt_id)           AS trt_id,
             tr.nombres                              AS trt,
             r.ruta_id                               AS ruta_id,
             r.inicio_ruta                           AS inicio_ruta,
             HOUR(r.inicio_ruta)                     AS hora_ruta,
             HOUR(r.presenta_para_carga)             AS hora_presenta,
             HOUR(r.qr_llegada_destino)              AS hora_llegada,
             WEEKDAY(r.inicio_ruta)                  AS dia_semana,

             -- dia de la semana de la presentacion en carga: es la marca del mapa de calor
             WEEKDAY(r.presenta_para_carga)          AS dia_presenta,

             (r.fin_descargue IS NOT NULL)           AS cerrado,
             (r.conductor_id IS NULL AND lc.conductor_id IS NULL) AS sin_conductor,
             (r.compromiso_carga IS NOT NULL AND r.presenta_para_carga IS NULL) AS sin_presenta,

             -- actividad: solo marcas con sentido (hay hitos con anio 1998 en la base)
             CASE WHEN r.inicio_ruta >= '2000-01-01'
                      THEN r.inicio_ruta END             AS primera_marca,

             CASE WHEN r.presenta_para_carga >= '2000-01-01'
                      THEN r.presenta_para_carga END     AS marca_presenta,

             CASE WHEN r.fin_descargue >= '2000-01-01'
                      THEN r.fin_descargue
                  WHEN r.inicio_ruta >= '2000-01-01'
                      THEN r.inicio_ruta END             AS ultima_marca,

             -- desvio de puntualidad: compromiso -> presenta, solo pares confiables
             CASE WHEN TIMESTAMPDIFF(MINUTE, r.compromiso_carga, r.presenta_para_carga)
                 BETWEEN -1440 AND 1440
                      THEN TIMESTAMPDIFF(MINUTE, r.compromiso_carga, r.presenta_para_carga)
                 END                                     AS desvio,

             -- ciclo real: del presenta para carga al fin de descargue del mismo viaje
             CASE WHEN TIMESTAMPDIFF(MINUTE, r.presenta_para_carga, r.fin_descargue)
                 BETWEEN 0 AND 10080
                      THEN TIMESTAMPDIFF(MINUTE, r.presenta_para_carga, r.fin_descargue)
                 END                                     AS ciclo_real,

             TIMESTAMPDIFF(MINUTE, r.presenta_para_carga, r.inicio_de_carga)  AS etapa_2,
             TIMESTAMPDIFF(MINUTE, r.inicio_de_carga,    r.inicio_ruta)       AS etapa_3,
             TIMESTAMPDIFF(MINUTE, r.inicio_ruta,        r.qr_llegada_destino) AS etapa_4,
             TIMESTAMPDIFF(MINUTE, r.qr_llegada_destino, r.inicio_descargue)  AS etapa_5,
             TIMESTAMPDIFF(MINUTE, r.inicio_descargue,   r.fin_descargue)     AS etapa_6

         FROM referencias r
                  LEFT JOIN (
             SELECT
                 lf.ref                AS ref,
                 MAX(lf.conductor_id)  AS conductor_id,
                 MAX(lf.trt_id)        AS trt_id
             FROM llamadas lf
             WHERE lf.ref IS NOT NULL
             GROUP BY lf.ref
         ) lc ON lc.ref = r.ref
                  LEFT JOIN conductores co ON co.id = COALESCE(r.conductor_id, lc.conductor_id)
                  LEFT JOIN trts       tr ON tr.id = COALESCE(r.trt_id, lc.trt_id)
         WHERE 1=1
           AND COALESCE(r.conductor_id, lc.conductor_id, 0) = 91
     ) b
ORDER BY COALESCE(
             b.fin_descargue,
             b.inicio_descargue,
             b.qr_llegada_destino,
             b.inicio_ruta,
             b.presenta_para_carga
         ) DESC
LIMIT 10;

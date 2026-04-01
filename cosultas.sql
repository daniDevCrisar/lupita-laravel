 -- ------------------------------------------------------------
 -- CONSULTA LISTA DE CONDUCTORES--------------------------------
 -- ------------------------------------------------------------
 SELECT
     total,exitosas,fallidas,total_errores,

     DATE_FORMAT(t.fecha, '%W %d de %M de %Y') as fecha_text
 FROM (
          SELECT
              COUNT(*) AS total,
              COALESCE(SUM(a.llamada_exitosa=1),0) AS exitosas,
              COALESCE(SUM(a.llamada_exitosa=0),0) AS fallidas,
              COALESCE(SUM((a.llamada_exitosa=0) AND (a.error_origen!=0)),0) as total_errores,
              COUNT(DISTINCT a.conductor_id) AS conductores_distintos,
              COUNT(DISTINCT IF(a.llamada_exitosa = 1, a.conductor_id, 0)) as confirmados,

              DATE(a.created_at) as fecha
          FROM llamadas a
          WHERE a.created_at >= DATE('2026-03-19 00:00:00') - INTERVAL 8 DAY
            AND a.created_at < DATE('2026-03-19 00:00:00') + INTERVAL 1 DAY
          GROUP BY DATE(a.created_at)
      ) t;

 -- ----------------------------------------------------------------------------
 -- ----------------------------------------------------------------------------
 -- ----------------------------------------------------------------------------
 SELECT
     a.conductor_id,
     b.nombres AS conductor,
     a.trt_id,
     COALESCE(c.nombres, 'SIN TRT') AS trt,

     COUNT(*) AS total,
     SUM(a.llamada_exitosa=1) AS exitosas,
     SUM(a.llamada_exitosa=0) AS fallidas,
     ROUND(SUM(a.llamada_exitosa=1)/COUNT(*)*100,1) AS tasa_exito,
     SUM(a.llamada_exitosa=1) - SUM(a.llamada_exitosa=0) AS diferencia,

     SUM(a.conductor_confirma * a.llamada_exitosa)  AS conductor_confirma,
     SUM(a.conductor_da_motivos * a.llamada_exitosa ) AS conductor_da_motivos,
     SUM(a.conversacion_fluida * a.llamada_exitosa) AS conversacion_fluida,
     SUM(a.llamada_interesante * a.llamada_exitosa ) AS llamada_interesante,

     SUM(a.conductor_da_motivos * a.llamada_exitosa ) +
     SUM(a.conversacion_fluida * a.llamada_exitosa) +
     SUM(a.llamada_interesante * a.llamada_exitosa) as etiqueta_positiva,

     -- 🔥 AQUÍ EL MEJOR AUDIO
     SUBSTRING_INDEX(
         MAX(
             CASE
                 WHEN a.llamada_exitosa = 1
                     THEN CONCAT(
                     LPAD(
                         a.conductor_da_motivos +
                         a.conversacion_fluida +
                         a.llamada_interesante , 2, '0'
                     ),
                     '|',
                     a.audio_link
                          )
                 END
         ),
         '|',
         -1
     ) AS mejor_audio

 FROM llamadas a
          INNER JOIN conductores b ON b.id = a.conductor_id
          LEFT JOIN trts c ON c.id = a.trt_id

 WHERE a.error_origen = 0
   AND a.created_at >= '2026-03-20 00:00:00'
   AND a.created_at < '2026-03-21 00:00:00'

 GROUP BY a.conductor_id, a.trt_id

 ORDER BY diferencia DESC, exitosas DESC, etiqueta_positiva DESC

 LIMIT 5;


select * from error_origen

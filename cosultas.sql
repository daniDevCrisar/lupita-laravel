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
              DATE(a.created_at) as fecha
          FROM llamadas a
          WHERE a.created_at >= DATE('2026-03-19 00:00:00') - INTERVAL 8 DAY
            AND a.created_at < DATE('2026-03-19 00:00:00') + INTERVAL 1 DAY
          GROUP BY DATE(a.created_at)
      ) t;

 -- ----------------------------------------------------------------------------
 -- ----------------------------------------------------------------------------
 -- ----------------------------------------------------------------------------

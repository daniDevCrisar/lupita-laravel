-- =====================================================================
-- OPTIMIZACIÓN DE SCORING  (/lupita/scoring/pesos)
-- Error que corrige: SQLSTATE[HY000] 2006 "MySQL server has gone away"
-- Causa:  llamadas.ref (INT) vs referencias.ref (VARCHAR) rompe el uso
--         del PRIMARY de referencias -> escaneo completo por cada fila
--         de llamadas (29.573 x 21.861) -> supera wait_timeout=30 -> 2006
--
-- ⚠️  HACER RESPALDO DE `llamadas` ANTES DE EJECUTAR (phpMyAdmin > Exportar)
-- Ejecutar en la base de producción (pedrosot_calls), por bloques si se desea.
-- =====================================================================


-- ---------------------------------------------------------------------
-- 0) DIAGNÓSTICO PREVIO (solo lectura, NO modifica nada)
-- ---------------------------------------------------------------------

-- Índices actuales de llamadas (hoy solo PK vapi_id)
SHOW INDEX FROM llamadas;

-- ¿Todos los ref son numéricos? (debe devolver 0 para poder pasar a VARCHAR sin perder datos)
SELECT COUNT(*) AS refs_no_numericos
FROM referencias
WHERE ref NOT REGEXP '^[0-9]+$';

-- ¿Hay colisiones si ref se interpreta como número? (debe devolver 0 filas)
SELECT CAST(ref AS UNSIGNED) AS ref_num, COUNT(*) AS n
FROM referencias
GROUP BY CAST(ref AS UNSIGNED)
HAVING n > 1;


-- ---------------------------------------------------------------------
-- 1) ALINEAR EL TIPO DE ref  (esta es la corrección principal)
--    referencias.ref ya es VARCHAR(10) PRIMARY KEY.
--    Se pasa llamadas.ref a VARCHAR(10) para que el join use la PK.
--    (Además queda consistente con tmp_lotes_det.ref, también VARCHAR)
-- ---------------------------------------------------------------------
ALTER TABLE llamadas MODIFY ref VARCHAR(10) NULL;


-- ---------------------------------------------------------------------
-- 2) ÍNDICES EN llamadas
--    Se crean SOLO si no existen (idempotente: se puede correr repetido).
-- ---------------------------------------------------------------------
SET @db := DATABASE();

-- 2.1  idx_llamadas_ref  (joins referencias <-> llamadas en ambos sentidos)
SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = @db AND table_name = 'llamadas' AND index_name = 'idx_llamadas_ref') = 0,
    'CREATE INDEX idx_llamadas_ref ON llamadas (ref)',
    'SELECT ''idx_llamadas_ref ya existe'' AS msg'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2.2  idx_llamadas_conductor
SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = @db AND table_name = 'llamadas' AND index_name = 'idx_llamadas_conductor') = 0,
    'CREATE INDEX idx_llamadas_conductor ON llamadas (conductor_id)',
    'SELECT ''idx_llamadas_conductor ya existe'' AS msg'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2.3  idx_llamadas_trt
SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = @db AND table_name = 'llamadas' AND index_name = 'idx_llamadas_trt') = 0,
    'CREATE INDEX idx_llamadas_trt ON llamadas (trt_id)',
    'SELECT ''idx_llamadas_trt ya existe'' AS msg'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2.4  idx_llamadas_scoring  (WHERE tipo/entro/exitosa + GROUP BY)
SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = @db AND table_name = 'llamadas' AND index_name = 'idx_llamadas_scoring') = 0,
    'CREATE INDEX idx_llamadas_scoring ON llamadas (llamada_tipo_id, entro_llamada, llamada_exitosa)',
    'SELECT ''idx_llamadas_scoring ya existe'' AS msg'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2.5  idx_llamadas_created  (filtros por rango de fecha)
SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = @db AND table_name = 'llamadas' AND index_name = 'idx_llamadas_created') = 0,
    'CREATE INDEX idx_llamadas_created ON llamadas (created_at)',
    'SELECT ''idx_llamadas_created ya existe'' AS msg'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------
-- 3) VERIFICACIÓN
-- ---------------------------------------------------------------------

-- Deben aparecer los 5 índices nuevos además del PRIMARY
SHOW INDEX FROM llamadas;

-- La fila de `r` (referencias) debe pasar de 'ALL / Range checked for each record'
-- a 'eq_ref' (usa PRIMARY) o desaparecer de la lista.
EXPLAIN
SELECT COALESCE(l.llamada_tipo_id, 0) AS tipo,
       COUNT(*) AS n,
       SUM(l.llamada_exitosa = 1) AS e
FROM llamadas l
LEFT JOIN conductores co ON co.id = l.conductor_id
LEFT JOIN trts t         ON t.id = l.trt_id
LEFT JOIN referencias r  ON r.ref = l.ref
WHERE l.llamada_exitosa IN (0, 1)
  AND l.llamada_tipo_id IS NOT NULL
  AND l.llamada_tipo_id <> 0
  AND l.entro_llamada = 1
GROUP BY COALESCE(l.llamada_tipo_id, 0);

-- Prueba real (debe responder en < 1s):
SELECT COALESCE(l.llamada_tipo_id, 0) AS tipo,
       COUNT(*) AS n,
       SUM(l.llamada_exitosa = 1) AS e
FROM llamadas l
LEFT JOIN conductores co ON co.id = l.conductor_id
LEFT JOIN trts t         ON t.id = l.trt_id
LEFT JOIN referencias r  ON r.ref = l.ref
WHERE l.llamada_exitosa IN (0, 1)
  AND l.llamada_tipo_id IS NOT NULL
  AND l.llamada_tipo_id <> 0
  AND l.entro_llamada = 1
GROUP BY COALESCE(l.llamada_tipo_id, 0);


-- =====================================================================
-- REVERSIÓN (solo si fuera necesario deshacer el cambio de tipo).
-- Ejecutar únicamente si TODOS los ref actuales son numéricos.
--   ALTER TABLE llamadas MODIFY ref INT NULL;
-- Los índices creados se pueden dejar; no afectan al revertir el tipo.
-- =====================================================================

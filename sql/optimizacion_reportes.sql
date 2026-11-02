-- ============================================================
-- Indices de optimizacion para los reportes V2 (dashboard, dia, rango)
-- ============================================================
-- Todas las consultas del reporte filtran por rango de created_at y,
-- opcionalmente, por llamada_tipo_id y trt_id. Con estos indices el motor
-- deja de recorrer la tabla completa y usa range scan sobre el rango.
--
-- Ejecutar una sola vez. El procedimiento es idempotente: si el indice ya
-- existe no hace nada (MySQL 8.4 no soporta CREATE INDEX IF NOT EXISTS).
--
-- En consola:  mysql -u root lupita_bd < sql/optimizacion_reportes.sql
-- En HeidiSQL/phpMyAdmin: pegar el bloque completo (respeta DELIMITER).
-- ============================================================

DROP PROCEDURE IF EXISTS lupita_crear_indice;

DELIMITER $$

CREATE PROCEDURE lupita_crear_indice(
    IN p_tabla VARCHAR(64),
    IN p_indice VARCHAR(64),
    IN p_columnas VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_tabla
          AND INDEX_NAME = p_indice
    ) THEN
        SET @sql_indice = CONCAT('CREATE INDEX ', p_indice, ' ON ', p_tabla, ' (', p_columnas, ')');
        PREPARE stmt_indice FROM @sql_indice;
        EXECUTE stmt_indice;
        DEALLOCATE PREPARE stmt_indice;
    END IF;
END$$

DELIMITER ;

-- rango de fechas: el filtro que comparten todos los bloques del reporte
CALL lupita_crear_indice('llamadas', 'idx_llamadas_created', 'created_at');

-- rango + tipo de llamada (KPI por tipo, etapa logistica)
CALL lupita_crear_indice('llamadas', 'idx_llamadas_created_tipo', 'created_at, llamada_tipo_id');

-- tipo primero: sirve cuando el usuario fija el tipo y deja el rango abierto
CALL lupita_crear_indice('llamadas', 'idx_llamadas_tipo_created', 'llamada_tipo_id, created_at');

-- filtro por transportista dentro del rango (ranking y costo por confirmacion)
CALL lupita_crear_indice('llamadas', 'idx_llamadas_trt_created', 'trt_id, created_at');

-- ranking de conductores
CALL lupita_crear_indice('llamadas', 'idx_llamadas_conductor_created', 'conductor_id, created_at');

-- bloque de errores tecnicos (la tabla filtra error_origen = 0 en rankings)
CALL lupita_crear_indice('llamadas', 'idx_llamadas_error_origen', 'error_origen');

DROP PROCEDURE IF EXISTS lupita_crear_indice;

-- refresca las estadisticas para que el optimizador use los indices nuevos
ANALYZE TABLE llamadas;

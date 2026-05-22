-- Tabla de rutas (se genera automáticamente)
CREATE TABLE rutas (
                       id VARCHAR(100) PRIMARY KEY,
                       origen_id VARCHAR(50) NOT NULL,
                       destino_id VARCHAR(50) NOT NULL,
                       veces_usada INT DEFAULT 1,
                       ultima_vez DATETIME,
                       activo TINYINT DEFAULT 1,
                       ubigeo_origen VARCHAR(6) ,
                       ubigeo_destino VARCHAR(6)
);

ALTER TABLE `rutas` MODIFY COLUMN `id` VARCHAR(100) NOT NULL;

ALTER TABLE rutas
    ADD COLUMN ubigeo_origen VARCHAR(6) AFTER origen_id,
    ADD COLUMN ubigeo_destino VARCHAR(6) AFTER destino_id;

# DELIMITER $$
#
# DROP PROCEDURE IF EXISTS `sp_actualizar_ruta_id`$$
#
# CREATE PROCEDURE `sp_actualizar_ruta_id`(
#     IN p_ref VARCHAR(50)
# )
# BEGIN
#     DECLARE v_titulo VARCHAR(500);
#     DECLARE v_origen_raw VARCHAR(200);
#     DECLARE v_destino_raw VARCHAR(200);
#     DECLARE v_origen_limpio VARCHAR(200);
#     DECLARE v_destino_limpio VARCHAR(200);
#     DECLARE v_origen_id VARCHAR(50);
#     DECLARE v_destino_id VARCHAR(50);
#     DECLARE v_ruta_id VARCHAR(50);
#     DECLARE v_error_msg VARCHAR(255);
#
#     -- Obtener título
#     SELECT titulo_viaje INTO v_titulo FROM referencias WHERE ref = p_ref;
#
#     IF v_titulo IS NULL OR v_titulo = '' THEN
#         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro no tiene titulo de viaje';
#     END IF;
#
#     -- Extraer origen y destino
#     IF v_titulo LIKE '% - %' THEN
#         SET v_origen_raw = TRIM(SUBSTRING_INDEX(v_titulo, ' - ', 1));
#         SET v_destino_raw = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(v_titulo, ' - ', -1), '/', 1));
#     ELSEIF v_titulo LIKE '%-%' THEN
#         SET v_origen_raw = TRIM(SUBSTRING_INDEX(v_titulo, '-', 1));
#         SET v_destino_raw = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(v_titulo, '-', -1), '/', 1));
#     ELSE
#         SET v_origen_raw = '';
#         SET v_destino_raw = '';
#     END IF;
#
#     -- Limpiar origen
#     SET v_origen_limpio = v_origen_raw;
#     SET v_origen_limpio = REPLACE(v_origen_limpio, 'ENVIO PT ', '');
#     SET v_origen_limpio = REPLACE(v_origen_limpio, 'ENVIO ', '');
#     SET v_origen_limpio = REPLACE(v_origen_limpio, 'DESPACHO PT ', '');
#     SET v_origen_limpio = REPLACE(v_origen_limpio, 'DESPACHO ', '');
#     SET v_origen_limpio = REPLACE(v_origen_limpio, 'CARGA ', '');
#     SET v_origen_limpio = REPLACE(v_origen_limpio, 'RETORNO DE AG ', '');
#     SET v_origen_limpio = REPLACE(v_origen_limpio, 'RECOJO DE AG ', '');
#     SET v_origen_limpio = REPLACE(v_origen_limpio, 'PT ', '');
#     SET v_origen_limpio = REPLACE(v_origen_limpio, 'PLANTA ', '');
#     SET v_origen_limpio = TRIM(v_origen_limpio);
#
#     -- Limpiar destino
#     SET v_destino_limpio = v_destino_raw;
#     SET v_destino_limpio = SUBSTRING_INDEX(v_destino_limpio, '/', 1);
#     SET v_destino_limpio = TRIM(v_destino_limpio);
#
#     -- Buscar origen en locales
#     SELECT id INTO v_origen_id FROM locales
#     WHERE v_origen_limpio LIKE CONCAT('%', palabra_clave, '%')
#        OR palabra_clave LIKE CONCAT('%', v_origen_limpio, '%')
#     LIMIT 1;
#
#     -- Buscar destino en locales
#     SELECT id INTO v_destino_id FROM locales
#     WHERE v_destino_limpio LIKE CONCAT('%', palabra_clave, '%')
#        OR palabra_clave LIKE CONCAT('%', v_destino_limpio, '%')
#     LIMIT 1;
#
#     -- Validar
#     IF v_origen_id IS NULL THEN
#         SET v_error_msg = CONCAT('Origen no encontrado: ', v_origen_limpio);
#         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_error_msg;
#     END IF;
#
#     IF v_destino_id IS NULL THEN
#         SET v_error_msg = CONCAT('Destino no encontrado: ', v_destino_limpio);
#         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_error_msg;
#     END IF;
#
#     -- Generar ID de ruta: eliminar 'LOC_' y usar guión
#     SET v_ruta_id = CONCAT('RUT_',
#                            REPLACE(REPLACE(v_origen_id, 'LOC_', ''), '_', '-'),
#                            '-',
#                            REPLACE(REPLACE(v_destino_id, 'LOC_', ''), '_', '-'));
#
#     -- Insertar o actualizar ruta
#     INSERT INTO rutas (id, origen_id, destino_id, veces_usada, ultima_vez)
#     VALUES (v_ruta_id, v_origen_id, v_destino_id, 1, NOW())
#     ON DUPLICATE KEY UPDATE
#                          veces_usada = veces_usada + 1,
#                          ultima_vez = NOW();
#
#     -- Actualizar referencia
#     UPDATE referencias SET ruta_id = v_ruta_id WHERE ref = p_ref;
#
# END$$
#
# DELIMITER ;
#
#
#










# /////////vs2



# DELIMITER $$
#
# DROP PROCEDURE IF EXISTS `sp_actualizar_ruta_id`$$
#
# CREATE PROCEDURE `sp_actualizar_ruta_id`(
#     IN p_ref VARCHAR(50)
# )
# BEGIN
#     DECLARE v_titulo VARCHAR(500);
#     DECLARE v_sin_fecha VARCHAR(500);
#     DECLARE v_origen_raw VARCHAR(200);
#     DECLARE v_destino_raw VARCHAR(200);
#     DECLARE v_origen_limpio VARCHAR(200);
#     DECLARE v_destino_limpio VARCHAR(200);
#     DECLARE v_origen_id VARCHAR(50);
#     DECLARE v_destino_id VARCHAR(50);
#     DECLARE v_ruta_id VARCHAR(50);
#     DECLARE v_error_msg VARCHAR(255);
#
#     -- Obtener título
#     SELECT titulo_viaje INTO v_titulo FROM referencias WHERE ref = p_ref;
#
#     IF v_titulo IS NULL OR v_titulo = '' THEN
#         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro no tiene titulo de viaje';
#     END IF;
#
#     -- PASO 1: Eliminar todo después del primer '/' (fechas, detalles de carga)
#     SET v_sin_fecha = SUBSTRING_INDEX(v_titulo, '/', 1);
#     SET v_sin_fecha = TRIM(v_sin_fecha);
#
#     -- PASO 2: Separar origen y destino por ' - '
#     IF v_sin_fecha LIKE '% - %' THEN
#         SET v_origen_raw = TRIM(SUBSTRING_INDEX(v_sin_fecha, ' - ', 1));
#         SET v_destino_raw = TRIM(SUBSTRING_INDEX(v_sin_fecha, ' - ', -1));
#     ELSEIF v_sin_fecha LIKE '%-%' THEN
#         SET v_origen_raw = TRIM(SUBSTRING_INDEX(v_sin_fecha, '-', 1));
#         SET v_destino_raw = TRIM(SUBSTRING_INDEX(v_sin_fecha, '-', -1));
#     ELSE
#         SET v_origen_raw = '';
#         SET v_destino_raw = '';
#     END IF;
#
#     -- PASO 3: Limpiar origen con REGEX
#     SET v_origen_limpio = v_origen_raw;
#     -- Eliminar palabras iniciales (ENVIO, DESPACHO, CARGA, etc.)
#     SET v_origen_limpio = REGEXP_REPLACE(v_origen_limpio, '^(ENVIO|DESPACHO|CARGA|RETORNO|RECOJO)\\s+(PT|PLANTA)?\\s*', '');
#     -- Eliminar AJEPER y PT/PLANTA al final
#     SET v_origen_limpio = REGEXP_REPLACE(v_origen_limpio, '\\s*(PT|PLANTA)\\s*$', '');
#     SET v_origen_limpio = TRIM(v_origen_limpio);
#
#     -- PASO 3b: Limpiar destino con REGEX
#     SET v_destino_limpio = v_destino_raw;
#     -- Eliminar fechas sueltas al final (ej. "-18/04/2026")
#     SET v_destino_limpio = REGEXP_REPLACE(v_destino_limpio, '-\\d{2}/\\d{2}/\\d{4}$', '');
#     SET v_destino_limpio = TRIM(v_destino_limpio);
#
#     -- PASO 4: Buscar origen en locales usando REGEX
#     SELECT id INTO v_origen_id FROM locales
#     WHERE v_origen_limpio REGEXP CONCAT('\\b(', REPLACE(palabra_clave, '|', '|'), ')\\b')
#        OR palabra_clave REGEXP CONCAT('\\b(', REPLACE(v_origen_limpio, '|', '|'), ')\\b')
#     LIMIT 1;
#
#     -- PASO 4b: Buscar destino en locales usando REGEX
#     SELECT id INTO v_destino_id FROM locales
#     WHERE v_destino_limpio REGEXP CONCAT('\\b(', REPLACE(palabra_clave, '|', '|'), ')\\b')
#        OR palabra_clave REGEXP CONCAT('\\b(', REPLACE(v_destino_limpio, '|', '|'), ')\\b')
#     LIMIT 1;
#
#     -- Validar
#     IF v_origen_id IS NULL THEN
#         SET v_error_msg = CONCAT('Origen no encontrado: ', v_origen_limpio);
#         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_error_msg;
#     END IF;
#
#     IF v_destino_id IS NULL THEN
#         SET v_error_msg = CONCAT('Destino no encontrado: ', v_destino_limpio);
#         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_error_msg;
#     END IF;
#
# -- Generar ID de ruta (origen y destino ya existen)
#     SET v_ruta_id = CONCAT('RUT_',
#                            REPLACE(v_origen_id, 'LOC_', ''),
#                            '_',
#                            REPLACE(v_destino_id, 'LOC_', ''));
#
#     -- Insertar o actualizar ruta
#     INSERT INTO rutas (id, origen_id, destino_id, veces_usada, ultima_vez)
#     VALUES (v_ruta_id, v_origen_id, v_destino_id, 1, NOW())
#     ON DUPLICATE KEY UPDATE
#                          veces_usada = veces_usada + 1,
#                          ultima_vez = NOW();
#
#     -- Actualizar referencia
#     UPDATE referencias SET ruta_id = v_ruta_id WHERE ref = p_ref;
#
# END$$
#
# DELIMITER ;







# ///////////-------------------------------------V3
# /////////vs2



DELIMITER $$

DROP PROCEDURE IF EXISTS `sp_actualizar_ruta_id`$$

CREATE PROCEDURE `sp_actualizar_ruta_id`(
    IN p_ref VARCHAR(50)
)
BEGIN
    DECLARE v_titulo VARCHAR(500);
    DECLARE v_sin_fecha VARCHAR(500);
    DECLARE v_origen_raw VARCHAR(200);
    DECLARE v_destino_raw VARCHAR(200);
    DECLARE v_origen_limpio VARCHAR(200);
    DECLARE v_destino_limpio VARCHAR(200);
    DECLARE v_origen_id VARCHAR(50);
    DECLARE v_destino_id VARCHAR(50);
    DECLARE v_ubigeo_origen VARCHAR(6);
    DECLARE v_ubigeo_destino VARCHAR(6);
    DECLARE v_ruta_id VARCHAR(100);
    DECLARE v_error_msg VARCHAR(255);
    DECLARE v_pos INT;
    DECLARE v_sep_pos INT;

    -- Obtener título
    SELECT titulo_viaje INTO v_titulo FROM referencias WHERE ref = p_ref;

    IF v_titulo IS NULL OR v_titulo = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro no tiene titulo de viaje';
    END IF;

-- NO PROCESAR MULTIPARADA
    IF INSTR(v_titulo, 'MULTIPARADA') > 0 THEN
        SET v_error_msg = CONCAT('Multiparada: ', v_titulo);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_error_msg;
    END IF;



    -- PASO 1: Eliminar todo después del primer '/' (fechas, detalles de carga)
    SET v_sin_fecha = SUBSTRING_INDEX(v_titulo, '/', 1);
    SET v_sin_fecha = TRIM(v_sin_fecha);



    -- PASO 2: Separar origen y destino por ' - '
    IF INSTR(v_sin_fecha, ' - ') > 0 THEN
        SET v_sep_pos = INSTR(v_sin_fecha, ' - ');
        SET v_origen_raw = TRIM(SUBSTRING(v_sin_fecha, 1, v_sep_pos - 1));
        SET v_destino_raw = TRIM(SUBSTRING(v_sin_fecha, v_sep_pos + 3));
    ELSEIF INSTR(v_sin_fecha, '-') > 0 THEN
        SET v_sep_pos = INSTR(v_sin_fecha, '-');
        SET v_origen_raw = TRIM(SUBSTRING(v_sin_fecha, 1, v_sep_pos - 1));
        SET v_destino_raw = TRIM(SUBSTRING(v_sin_fecha, v_sep_pos + 1));
    ELSEIF INSTR(v_sin_fecha, ' A ') > 0 THEN
        SET v_sep_pos = INSTR(v_sin_fecha, ' A ');
        SET v_origen_raw = TRIM(SUBSTRING(v_sin_fecha, 1, v_sep_pos - 1));
        SET v_destino_raw = TRIM(SUBSTRING(v_sin_fecha, v_sep_pos + 3));
    ELSE
        SET v_origen_raw = '';
        SET v_destino_raw = '';
    END IF;

    -- PASO 3: Limpiar origen con REGEX
    SET v_origen_limpio = v_origen_raw;
    -- Eliminar palabras iniciales (ENVIO, DESPACHO, CARGA, etc.)

    SET v_origen_limpio = REPLACE(v_origen_limpio, '(', '');
    SET v_origen_limpio = REPLACE(v_origen_limpio, ')', '');

    SET v_origen_limpio = REGEXP_REPLACE(v_origen_limpio, '^(ENVIO|DESPACHO|CARGA|RETORNO|RECOJO)\\s+(PT|PLANTA)?\\s*', '');
    -- Eliminar AJEPER y PT/PLANTA al final
    SET v_origen_limpio = REGEXP_REPLACE(v_origen_limpio, '\\s*(PT|PLANTA)\\s*$', '');
    SET v_origen_limpio = TRIM(v_origen_limpio);

    -- PASO 3b: Limpiar destino con REGEX
    SET v_destino_limpio = v_destino_raw;
    SET v_destino_limpio = REPLACE(v_destino_limpio, '(', '');
    SET v_destino_limpio = REPLACE(v_destino_limpio, ')', '');

    -- Eliminar fechas sueltas al final (ej. "-18/04/2026")
    SET v_destino_limpio = REGEXP_REPLACE(v_destino_limpio, '-\\d{2}/\\d{2}/\\d{4}$', '');
    SET v_destino_limpio = TRIM(v_destino_limpio);

    -- PASO 4: Buscar origen en locales usando REGEX
    SELECT id, distrito_ubigeo INTO v_origen_id, v_ubigeo_origen
    FROM locales
    WHERE v_origen_limpio REGEXP CONCAT('\\b(', REPLACE(palabra_clave, '|', '|'), ')\\b')
       OR palabra_clave REGEXP CONCAT('\\b(', REPLACE(v_origen_limpio, '|', '|'), ')\\b')
    LIMIT 1;

    -- PASO 4b: Buscar destino en locales usando REGEX
    SELECT id, distrito_ubigeo INTO v_destino_id, v_ubigeo_destino
    FROM locales
    WHERE v_destino_limpio REGEXP CONCAT('\\b(', REPLACE(palabra_clave, '|', '|'), ')\\b')
       OR palabra_clave REGEXP CONCAT('\\b(', REPLACE(v_destino_limpio, '|', '|'), ')\\b')
    LIMIT 1;
    # //---------------------------
-- PASO 5: Si no se encontró local, buscar ubigeo en tabla de distritos
    IF v_origen_id IS NULL THEN
        SELECT ubigeo,ubigeo INTO v_ubigeo_origen ,v_origen_id FROM ubigeo_distritos
        WHERE UPPER(distrito) = v_origen_limpio
           OR v_origen_limpio LIKE CONCAT('%', UPPER(distrito), '%')
        LIMIT 1;
    END IF;

    IF v_destino_id IS NULL THEN
        SELECT ubigeo,ubigeo INTO v_ubigeo_destino,v_destino_id FROM ubigeo_distritos
        WHERE UPPER(distrito) = v_destino_limpio
           OR v_destino_limpio LIKE CONCAT('%', UPPER(distrito), '%')
        LIMIT 1;
    END IF;
    #     //-------------------------------------

    -- Validar
    IF v_origen_id is NULL THEN
        select v_titulo ,v_origen_raw, v_destino_raw ,v_origen_limpio ,v_destino_limpio  , v_ruta_id, v_origen_id, v_destino_id, v_ubigeo_origen, v_ubigeo_destino, 1, NOW();


        SET v_error_msg = CONCAT('Origen no encontrado: ', v_origen_limpio);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_error_msg;
    END IF;

    IF v_destino_id IS NULL THEN
        select v_titulo ,v_origen_raw, v_destino_raw ,v_origen_limpio ,v_destino_limpio  , v_ruta_id, v_origen_id, v_destino_id, v_ubigeo_origen, v_ubigeo_destino, 1, NOW();


        SET v_error_msg = CONCAT('Destino no encontrado: ', v_destino_limpio);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_error_msg;
    END IF;

    -- Generar ID de ruta (origen y destino ya existen)
    -- su hay ruta de locales generar
    IF v_origen_id IS NOT NULL and v_destino_id IS NOT NULL then
        SET v_ruta_id = CONCAT('RUT_',
                               REPLACE(v_origen_id, 'LOC_', ''),
                               '-',
                               REPLACE(v_destino_id, 'LOC_', ''));
    ELSEIF v_ubigeo_origen IS NOT NULL AND v_ubigeo_destino IS NOT NULL THEN
        SET v_ruta_id = CONCAT(v_ubigeo_origen,'-',v_ubigeo_destino);
    END IF;

#   select v_ruta_id, v_origen_id, v_destino_id, v_ubigeo_origen, v_ubigeo_destino, 1, NOW();

    -- Insertar o actualizar ruta
    INSERT INTO rutas (id, origen_id, destino_id, ubigeo_origen, ubigeo_destino, veces_usada, ultima_vez)
    VALUES (v_ruta_id, v_origen_id, v_destino_id, v_ubigeo_origen, v_ubigeo_destino, 1, NOW())
    ON DUPLICATE KEY UPDATE
                         veces_usada = veces_usada + 1,
                         ultima_vez = NOW(),
                         ubigeo_origen = IFNULL(v_ubigeo_origen, ubigeo_origen),
                         ubigeo_destino = IFNULL(v_ubigeo_destino, ubigeo_destino);

    -- Actualizar referencia
    UPDATE referencias SET ruta_id = v_ruta_id WHERE ref = p_ref;
    select v_titulo ,v_origen_raw, v_destino_raw ,v_origen_limpio ,v_destino_limpio  , v_ruta_id, v_origen_id, v_destino_id, v_ubigeo_origen, v_ubigeo_destino, 1, NOW();


END$$

DELIMITER ;


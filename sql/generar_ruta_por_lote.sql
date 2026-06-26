DELIMITER $$

DROP PROCEDURE IF EXISTS `sp_actualizar_rutas_lote`$$

CREATE PROCEDURE `sp_actualizar_rutas_lote`(
    IN p_lote VARCHAR(50)
)
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_ref VARCHAR(50);
    DECLARE v_continue BOOLEAN DEFAULT TRUE;
    DECLARE cur CURSOR FOR
        SELECT a.ref
        FROM referencias a
                 INNER JOIN llamadas b ON b.ref = a.ref
        WHERE a.ruta_id IS NULL
          AND a.titulo_viaje IS NOT NULL
          AND b.lote_id = p_lote
        GROUP BY a.ref;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION SET v_continue = FALSE;

    OPEN cur;

    read_loop: LOOP
        FETCH cur INTO v_ref;
        IF done THEN
            LEAVE read_loop;
        END IF;

        -- Intentar actualizar, si hay error continua con el siguiente
        BEGIN
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    SELECT CONCAT('Error omitido en ref: ', v_ref) AS advertencia;
                END;
            CALL sp_actualizar_ruta_id(v_ref);
        END;

    END LOOP;

    CLOSE cur;

    SELECT 'Proceso completado. Se omitieron errores.' AS mensaje;

END$$

DELIMITER ;
CALL sp_actualizar_rutas_lote('202602272136092841');

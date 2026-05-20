ALTER TABLE `referencias`
    ADD COLUMN `ruta_id` VARCHAR(50) DEFAULT NULL AFTER `titulo_viaje`,
    ADD INDEX `idx_ruta_id` (`ruta_id`);

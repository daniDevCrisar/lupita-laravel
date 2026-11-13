-- ============================================================
-- PREDICCIÓN DE CARGA — parámetros del motor
--
-- El servidor no tiene consola: este DDL se aplica A MANO antes de usar la
-- pantalla `/lupita/prediccion/presenta`. La migración equivalente es
-- `database/migrations/2026_11_13_120000_create_prediccion_config_table.php`.
--
-- Si la tabla no existe, la aplicación NO falla: usa los valores de
-- `config/prediccion.php`. Esta tabla sólo sirve para sobreescribirlos sin
-- tocar código.
-- ============================================================

CREATE TABLE IF NOT EXISTS `prediccion_config` (
    `clave` VARCHAR(32) NOT NULL,
    `valor` VARCHAR(32) NOT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `prediccion_config` (`clave`, `valor`) VALUES
    ('lookback_dias', '120'),
    ('min_pares_ruta', '3'),
    ('min_pares_entidad', '3'),
    ('min_dias_actividad', '5'),
    ('umbral_inactividad', '0.1'),
    ('granularidad_min', '30'),
    ('bins_horarios', '8'),
    ('max_desplazamiento_min', '600'),
    ('traslado_defecto_min', '240'),
    ('muerto_defecto_min', '60'),
    ('espera_carga_defecto_min', '90'),
    ('frescura_horizonte_h', '24'),
    ('edad_ancla_max_h', '72'),
    ('tope_hueco_min', '10080'),
    ('tope_etapa_min', '2880')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);

-- Verificación después de aplicarlo:
--   php artisan prediccion:diagnostico --dias=120
-- Ese comando imprime cuántas referencias traen ruta_id, cuántos pares
-- consecutivos hay por placa y cómo se distribuye el hueco real
-- fin_descargue -> presenta_para_carga. Sin esos números la tabla de aciertos
-- no se puede interpretar.

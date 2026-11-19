-- ============================================
-- UBICACIONES · tabla de descartes
-- ============================================
-- Modulo /lupita/ubicaciones. Solo se persiste lo que decide el usuario: que
-- candidato descarto. El analisis (que titulos no tienen ruta, que palabras se
-- repiten, que veredicto tiene cada una) se recalcula siempre desde
-- `referencias` + `locales`, para que no quede nunca desactualizado.
--
-- El id es md5(tipo|clave normalizada): mayusculas, sin tildes, espacios
-- colapsados. Asi "RECOJO  ACEITE" y "recojo aceite" son el mismo candidato y
-- descartar dos veces no duplica.
--
-- Va tambien como migracion (database/migrations/2026_11_19_120000_...); este
-- archivo documenta el esquema junto al resto de sql/, como locales.sql.

CREATE TABLE IF NOT EXISTS `ubicaciones_descartes` (
    `id`         CHAR(32)     NOT NULL COMMENT 'md5(tipo|clave normalizada)',
    `tipo`       VARCHAR(20)  NOT NULL COMMENT 'palabra | fragmento',
    `clave`      VARCHAR(500) NOT NULL COMMENT 'texto original, para leerlo en SQL',
    `motivo`     VARCHAR(255) DEFAULT NULL,
    `usuario_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP    NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ubicaciones_descartes_tipo_index` (`tipo`)
) COMMENT = 'candidatos que el usuario descarto en Ubicaciones';

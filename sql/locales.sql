-- ============================================
-- 1. CREAR TABLA LOCALES
-- ============================================
DROP TABLE IF EXISTS `locales`;

CREATE TABLE `locales` (
                           `id` VARCHAR(50) PRIMARY KEY COMMENT 'ID único del local (ej: LOC_BABEL_MAXO)',
                           `cliente_id` VARCHAR(50) NOT NULL COMMENT 'ID del cliente al que pertenece',
                           `nombre` VARCHAR(100) NOT NULL COMMENT 'Nombre comercial del local',
                           `palabra_clave` VARCHAR(100) NOT NULL COMMENT 'Palabra que aparece en el título para identificar este local',
                           `distrito_ubigeo` VARCHAR(6) DEFAULT NULL COMMENT 'Código ubigeo del distrito (6 dígitos)',
                           `activo` TINYINT DEFAULT 1 COMMENT '1=activo, 0=inactivo'
);
-- ============================================
-- LOCALES - AJEPER (Plantas de producción)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_CARAL', 'CLI_AJEPER', 'PLANTA CARAL', 'CARAL', '150103'),
                                                                                 ('LOC_HUACHIPA', 'CLI_AJEPER', 'PLANTA HUACHIPA', 'HUACHIPA', '150119'),
                                                                                 ('LOC_CHICLAYO', 'CLI_AJEPER', 'PLANTA CHICLAYO', 'CHICLAYO', '140101'),
                                                                                 ('LOC_TRUJILLO', 'CLI_AJEPER', 'PLANTA TRUJILLO', 'TRUJILLO', '130101'),
                                                                                 ('LOC_TARAPOTO', 'CLI_AJEPER', 'PLANTA TARAPOTO', 'TARAPOTO', '220601'),
                                                                                 ('LOC_PUCALLPA', 'CLI_AJEPER', 'PLANTA PUCALLPA', 'PUCALLPA', '250101');

-- ============================================
-- LOCALES - BABEL (múltiples locales)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_BABEL_MAXO', 'CLI_BABEL', 'BABEL MAXO', 'MAXO', '150103'),
                                                                                 ('LOC_BABEL_LIMA_SUR', 'CLI_BABEL', 'BABEL LIMA SUR', 'LIMA SUR', '150128'),
                                                                                 ('LOC_BABEL_NORTE', 'CLI_BABEL', 'BABEL NORTE', 'BABEL NORTE', NULL),
                                                                                 ('LOC_BABEL_CENTRO', 'CLI_BABEL', 'BABEL CENTRO', 'BABEL CENTRO', NULL),
                                                                                 ('LOC_BABEL_AMAZON', 'CLI_BABEL', 'BABEL AMAZON', 'AMAZON|BABEL AMAZON', NULL),
                                                                                 ('LOC_BABEL_CHICLAYO', 'CLI_BABEL', 'BABEL CHICLAYO', 'BABEL CHICLAYO', '140101'),
                                                                                 ('LOC_BABEL_CALLAO', 'CLI_BABEL', 'BABEL CALLAO', 'BABEL CALLAO', '070101'),
                                                                                 ('LOC_BABEL_PUENTE_PIEDRA', 'CLI_BABEL', 'BABEL PUENTE PIEDRA', 'BABEL PUENTE PIEDRA', '150107'),
                                                                                 ('LOC_BABEL_AREQUIPA', 'CLI_BABEL', 'BABEL AREQUIPA', 'BABEL AREQUIPA', '040101'),
                                                                                 ('LOC_BABEL_PIURA', 'CLI_BABEL', 'BABEL PIURA', 'BABEL PIURA', '200101'),
                                                                                 ('LOC_BABEL_CAMPOY', 'CLI_BABEL', 'BABEL CAMPOY', 'BABEL CAMPOY', NULL);

-- ============================================
-- LOCALES - CODISAL (múltiples locales)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_CODISAL_CALLAO', 'CLI_CODISAL', 'CODISAL CALLAO', 'CODISAL CALLAO', '070101'),
                                                                                 ('LOC_CODISAL_PTE_PIEDRA', 'CLI_CODISAL', 'CODISAL PUENTE PIEDRA', 'CODISAL PTE PIEDRA', '150107'),
                                                                                 ('LOC_CODISAL_HUANCAYO', 'CLI_CODISAL', 'CODISAL HUANCAYO', 'CODISAL HUANCAYO', '120101'),
                                                                                 ('LOC_CODISAL_AREQUIPA', 'CLI_CODISAL', 'CODISAL AREQUIPA', 'CODISAL AREQUIPA', '040101'),
                                                                                 ('LOC_CODISAL_CUSCO', 'CLI_CODISAL', 'CODISAL CUSCO', 'CODISAL CUSCO', '080101'),
                                                                                 ('LOC_CODISAL_CHIMBOTE', 'CLI_CODISAL', 'CODISAL CHIMBOTE', 'CODISAL CHIMBOTE', '021801'),
                                                                                 ('LOC_CODISAL_HUARAZ', 'CLI_CODISAL', 'CODISAL HUARAZ', 'CODISAL HUARAZ', '020101'),
                                                                                 ('LOC_CODISAL_ICA', 'CLI_CODISAL', 'CODISAL ICA', 'CODISAL ICA', '110101'),
                                                                                 ('LOC_CODISAL_JULIACA', 'CLI_CODISAL', 'CODISAL JULIACA', 'CODISAL JULIACA', '211001'),
                                                                                 ('LOC_CODISAL_TACNA', 'CLI_CODISAL', 'CODISAL TACNA', 'CODISAL TACNA', '230101'),
                                                                                 ('LOC_CODISAL_BARRANCA', 'CLI_CODISAL', 'CODISAL BARRANCA', 'CODISAL BARRANCA', '150201'),
                                                                                 ('LOC_CODISAL_CHINCHA', 'CLI_CODISAL', 'CODISAL CHINCHA', 'CODISAL CHINCHA', '110204'),
                                                                                 ('LOC_CODISAL_NAZCA', 'CLI_CODISAL', 'CODISAL NAZCA', 'CODISAL NAZCA', '110306'),
                                                                                 ('LOC_CODISAL_TARMA', 'CLI_CODISAL', 'CODISAL TARMA', 'CODISAL TARMA', '120604'),
                                                                                 ('LOC_CODISAL_AYACUCHO', 'CLI_CODISAL', 'CODISAL AYACUCHO', 'CODISAL AYACUCHO', '050101');

-- ============================================
-- LOCALES - SALEM (múltiples locales)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_SALEM_VES', 'CLI_SALEM', 'SALEM VILLA EL SALVADOR', 'SALEM VES', '150125'),
                                                                                 ('LOC_SALEM_HUACHIPA', 'CLI_SALEM', 'SALEM HUACHIPA', 'SALEM HUACHIPA', '150119'),
                                                                                 ('LOC_SALEM_TRUJILLO', 'CLI_SALEM', 'SALEM TRUJILLO', 'SALEM TRUJILLO', '130101'),
                                                                                 ('LOC_SALEM_PIURA', 'CLI_SALEM', 'SALEM PIURA', 'SALEM PIURA', '200101'),
                                                                                 ('LOC_SALEM_SULLANA', 'CLI_SALEM', 'SALEM SULLANA', 'SALEM SULLANA', '200301'),
                                                                                 ('LOC_SALEM_TUMBES', 'CLI_SALEM', 'SALEM TUMBES', 'SALEM TUMBES', '240101'),
                                                                                 ('LOC_SALEM_CHICLAYO', 'CLI_SALEM', 'SALEM CHICLAYO', 'SALEM CHICLAYO', '140101'),
                                                                                 ('LOC_SALEM_JAEN', 'CLI_SALEM', 'SALEM JAEN', 'SALEM JAEN', '060501'),
                                                                                 ('LOC_SALEM_CAJAMARCA', 'CLI_SALEM', 'SALEM CAJAMARCA', 'SALEM CAJAMARCA', '060101'),
                                                                                 ('LOC_SALEM_LIMA_SUR', 'CLI_SALEM', 'SALEM LIMA SUR', 'SALEM LIMA SUR', '150128');

-- ============================================
-- LOCALES - MAKRO (múltiples locales)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_MAKRO_TRUJILLO', 'CLI_MAKRO', 'MAKRO TRUJILLO', 'MAKRO TRUJILLO', '130101'),
                                                                                 ('LOC_MAKRO_PIURA', 'CLI_MAKRO', 'MAKRO PIURA', 'MAKRO PIURA', '200101'),
                                                                                 ('LOC_MAKRO_SULLANA', 'CLI_MAKRO', 'MAKRO SULLANA', 'MAKRO SULLANA', '200301'),
                                                                                 ('LOC_MAKRO_HUANCAYO', 'CLI_MAKRO', 'MAKRO HUANCAYO', 'MAKRO HUANCAYO', '120101');

-- ============================================
-- LOCALES - PRADERAS (múltiples locales)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_PRADERAS_TARAPOTO', 'CLI_PRADERAS', 'PRADERAS TARAPOTO', 'PRADERAS TARAPOTO', '220601'),
                                                                                 ('LOC_PRADERAS_MOYOBAMBA', 'CLI_PRADERAS', 'PRADERAS MOYOBAMBA', 'PRADERAS MOYOBAMBA', '220301'),
                                                                                 ('LOC_PRADERAS_JUANJUI', 'CLI_PRADERAS', 'PRADERAS JUANJUI', 'PRADERAS JUANJUI', '220502'),
                                                                                 ('LOC_PRADERAS_YURIMAGUAS', 'CLI_PRADERAS', 'PRADERAS YURIMAGUAS', 'PRADERAS YURIMAGUAS', '220204'),
                                                                                 ('LOC_PRADERAS_TINGO_MARIA', 'CLI_PRADERAS', 'PRADERAS TINGO MARIA', 'PRADERAS TINGO MARIA', '100507'),
                                                                                 ('LOC_PRADERAS_HUANUCO', 'CLI_PRADERAS', 'PRADERAS HUANUCO', 'PRADERAS HUANUCO', '100101'),
                                                                                 ('LOC_PRADERAS_BAGUA_GRANDE', 'CLI_PRADERAS', 'PRADERAS BAGUA GRANDE', 'PRADERAS BAGUA GRANDE', '010204'),
                                                                                 ('LOC_PRADERAS_LA_MERCED', 'CLI_PRADERAS', 'PRADERAS LA MERCED', 'PRADERAS LA MERCED', '120506');

-- ============================================
-- LOCALES - ECO (múltiples locales)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_ECO_SUAREZ', 'CLI_ECO', 'SUAREZ (ECO)', 'SUAREZ', NULL),
                                                                                 ('LOC_ECO_FQUISPE', 'CLI_ECO', 'FQUISPE (ECO)', 'FQUISPE', NULL),
                                                                                 ('LOC_ECO_VALESKA', 'CLI_ECO', 'VALESKA (ECO)', 'VALESKA', NULL),
                                                                                 ('LOC_ECO_BAMA', 'CLI_ECO', 'BAMA (ECO)', 'BAMA', NULL),
                                                                                 ('LOC_ECO_CORP_CHRISTIAN', 'CLI_ECO', 'CORP. CHRISTIAN (ECO)', 'CORP. CHRISTIAN', NULL),
                                                                                 ('LOC_ECO_ALM_BAMA', 'CLI_ECO', 'ALM. BAMA (ECO)', 'ALM. BAMA', NULL);

-- ============================================
-- LOCALES - TDP (múltiples locales - pendiente identificar)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
    ('LOC_TDP_1', 'CLI_TDP', 'TDP - LOCAL 1', 'TDP', NULL);
-- Nota: Agregar más locales de TDP cuando se identifiquen

-- ============================================
-- LOCALES - CLIENTES UNITARIOS (1 local cada uno)
-- palabra_clave = misma que la del cliente
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_SMI', 'CLI_SMI', 'SMI', 'SMI', NULL),
                                                                                 ('LOC_NAVAQUI', 'CLI_NAVAQUI', 'NAVAQUI', 'NAVAQUI', NULL),
                                                                                 ('LOC_HUK', 'CLI_HUK', 'HUK - PUCALLPA', 'HUK|HUK PUCALLPA|HUK DISTRIBUCIONES PUCALLPA', '250101'),
                                                                                 ('LOC_DISCER', 'CLI_DISCER', 'DISCER', 'DISCER', NULL),
                                                                                 ('LOC_DISTRIBUIDORA_SELVA', 'CLI_DISTRIBUIDORA_SELVA', 'DISTRIBUIDORA SELVA - PUCALLPA', 'DISTRIBUIDORA SELVA', '250101'),
                                                                                 ('LOC_FONPELL', 'CLI_FONPELL', 'FONPELL', 'FONPELL', NULL),
                                                                                 ('LOC_REPRESENTACIONES_ORIENTE', 'CLI_REPRESENTACIONES_ORIENTE', 'REPRESENTACIONES ORIENTE', 'REPRESENTACIONES ORIENTE', NULL),
                                                                                 ('LOC_JAVICHO', 'CLI_JAVICHO', 'JAVICHO', 'JAVICHO', NULL),
                                                                                 ('LOC_TULIPANES', 'CLI_TULIPANES', 'TULIPANES - IQUITOS', 'TULIPANES', '160101'),
                                                                                 ('LOC_CANNON', 'CLI_CANNON', 'CANNON', 'CANNON', NULL),
                                                                                 ('LOC_YURACK', 'CLI_YURACK', 'YURACK GROUP', 'YURACK GROUP', NULL),
                                                                                 ('LOC_CORP_CHRISTIAN', 'CLI_CORP_CHRISTIAN', 'CORP. CHRISTIAN', 'CORP. CHRISTIAN', NULL),
                                                                                 ('LOC_CAQUI_INGA', 'CLI_CAQUI_INGA', 'CAQUI/INGA', 'CAQUI|INGA', NULL);
-- ============================================
-- 9. CONSULTA DE VERIFICACIÓN
-- ============================================
SELECT
    l.id,
    l.cliente_id,
    l.nombre,
    l.palabra_clave,
    l.distrito_ubigeo,
    l.activo
FROM locales l
ORDER BY l.cliente_id, l.nombre;

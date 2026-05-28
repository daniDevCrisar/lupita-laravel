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
 ('LOC_CARAL', 'CLI_AJEPER', 'PLANTA CARAL', 'CARAL', '150601'),
 ('LOC_HUACHIPA', 'CLI_AJEPER', 'PLANTA HUACHIPA', 'HUACHIPA', '150132'),
 ('LOC_CHICLAYO', 'CLI_AJEPER', 'PLANTA CHICLAYO', 'CHICLAYO', '140101'),
 ('LOC_TRUJILLO', 'CLI_AJEPER', 'PLANTA TRUJILLO', 'TRUJILLO', '130101'),
 ('LOC_TARAPOTO', 'CLI_AJEPER', 'PLANTA TARAPOTO', 'TARAPOTO', '220901'),
 ('LOC_PUCALLPA', 'CLI_AJEPER', 'PLANTA PUCALLPA', 'PUCALLPA', '250101'),
 ('LOC_PTE PIEDRA', 'CLI_AJEPER', 'PLANTA PTE PIEDRA', 'PTE PIEDRA', '150125');

-- ============================================
-- LOCALES - BABEL (múltiples locales)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
 ('LOC_BABEL_MAXO', 'CLI_BABEL', 'BABEL MAXO', 'MAXO', '150132'),
 ('LOC_BABEL_LIMA_SUR', 'CLI_BABEL', 'BABEL LIMA SUR', 'BABEL SUR|BABEL VILLA|LIMA SUR', '150142'),
 ('LOC_BABEL_CENTRO', 'CLI_BABEL', 'BABEL CENTRO', 'BABEL CENTRO', '150132'),
 ('LOC_BABEL_AMAZON', 'CLI_BABEL', 'BABEL AMAZON', 'AMAZON|BABEL AMAZON', '150132'),
 ('LOC_BABEL_CHICLAYO', 'CLI_BABEL', 'BABEL CHICLAYO', 'BABEL CHICLAYO', '140101'),
 ('LOC_BABEL_CALLAO', 'CLI_BABEL', 'BABEL CALLAO', 'BABEL CALLAO', '070101'),
 ('LOC_BABEL_PUENTE_PIEDRA', 'CLI_BABEL', 'BABEL PUENTE PIEDRA', 'BABEL NORTE|BABEL PUENTE PIEDRA', '150125'),
 ('LOC_BABEL_AREQUIPA', 'CLI_BABEL', 'BABEL AREQUIPA', 'BABEL AREQUIPA', '040101'),
 ('LOC_BABEL_PIURA', 'CLI_BABEL', 'BABEL PIURA', 'BABEL PIURA', '200101'),
 ('LOC_BABEL_CAMPOY', 'CLI_BABEL', 'BABEL CAMPOY', 'BABEL CAMPOY|CAMPOY', '150132');

-- ============================================
-- LOCALES - CODISAL (múltiples locales)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_CODISAL_CALLAO', 'CLI_CODISAL', 'CODISAL CALLAO', 'CODISAL CALLAO', '070101'),
                                                                                 ('LOC_CODISAL_PTE_PIEDRA', 'CLI_CODISAL', 'CODISAL PUENTE PIEDRA', 'CODISAL PTE PIEDRA', '150125'),
                                                                                 ('LOC_CODISAL_HUANCAYO', 'CLI_CODISAL', 'CODISAL HUANCAYO', 'CODISAL HUANCAYO', '120101'),
                                                                                 ('LOC_CODISAL_AREQUIPA', 'CLI_CODISAL', 'CODISAL AREQUIPA', 'CODISAL AREQUIPA', '040101'),
                                                                                 ('LOC_CODISAL_CUSCO', 'CLI_CODISAL', 'CODISAL CUSCO', 'CODISAL CUSCO', '080101'),
                                                                                 ('LOC_CODISAL_CHIMBOTE', 'CLI_CODISAL', 'CODISAL CHIMBOTE', 'CODISAL CHIMBOTE', '021801'),
                                                                                 ('LOC_CODISAL_HUARAZ', 'CLI_CODISAL', 'CODISAL HUARAZ', 'CODISAL HUARAZ', '020101'),
                                                                                 ('LOC_CODISAL_ICA', 'CLI_CODISAL', 'CODISAL ICA', 'CODISAL ICA', '110101'),
                                                                                 ('LOC_CODISAL_JULIACA', 'CLI_CODISAL', 'CODISAL JULIACA', 'CODISAL JULIACA', '211101'),
                                                                                 ('LOC_CODISAL_TACNA', 'CLI_CODISAL', 'CODISAL TACNA', 'CODISAL TACNA', '230101'),
                                                                                 ('LOC_CODISAL_BARRANCA', 'CLI_CODISAL', 'CODISAL BARRANCA', 'CODISAL BARRANCA', '150201'),
                                                                                 ('LOC_CODISAL_CHINCHA', 'CLI_CODISAL', 'CODISAL CHINCHA', 'CODISAL CHINCHA|CHINCHA', '110204'),
                                                                                 ('LOC_CODISAL_NAZCA', 'CLI_CODISAL', 'CODISAL NAZCA', 'CODISAL NAZCA', '110301'),
                                                                                 ('LOC_CODISAL_TARMA', 'CLI_CODISAL', 'CODISAL TARMA', 'CODISAL TARMA', '120701'),
                                                                                 ('LOC_CODISAL_MOQUEGUA', 'CLI_CODISAL', 'CODISAL MOQUEGUA', 'CODISAL MOQUEGUA', '180101'),
                                                                                 ('LOC_CODISAL_AYACUCHO', 'CLI_CODISAL', 'CODISAL AYACUCHO', 'CODISAL AYACUCHO', '050101'),
                                                                                 ('LOC_CODISAL_PUERTO_MALDONADO', 'CLI_CODISAL', 'PUERTO MALDONADO|CODISAL PUERTO MALDONADO', 'CODISAL PUERTO MALDONADO', '170101');

-- ============================================
-- LOCALES - SALEM (múltiples locales)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_SALEM_VES', 'CLI_SALEM', 'SALEM VILLA EL SALVADOR', 'SALEM LIMA SUR|SALEM VES', '150142'),
                                                                                 ('LOC_SALEM_HUACHIPA', 'CLI_SALEM', 'SALEM HUACHIPA', 'SALEM HUACHIPA', '150132'),
                                                                                 ('LOC_SALEM_TRUJILLO', 'CLI_SALEM', 'SALEM TRUJILLO', 'SALEM TRUJILLO', '130101'),
                                                                                 ('LOC_SALEM_PIURA', 'CLI_SALEM', 'SALEM PIURA', 'SALEM PIURA', '200101'),
                                                                                 ('LOC_SALEM_SULLANA', 'CLI_SALEM', 'SALEM SULLANA', 'SALEM SULLANA', '200601'),
                                                                                 ('LOC_SALEM_TUMBES', 'CLI_SALEM', 'SALEM TUMBES', 'SALEM TUMBES', '240101'),
                                                                                 ('LOC_SALEM_CHICLAYO', 'CLI_SALEM', 'SALEM CHICLAYO', 'SALEM CHICLAYO', '140101'),
                                                                                 ('LOC_SALEM_JAEN', 'CLI_SALEM', 'SALEM JAEN', 'SALEM JAEN', '060801'),
                                                                                 ('LOC_SALEM_CAJAMARCA', 'CLI_SALEM', 'SALEM CAJAMARCA', 'SALEM CAJAMARCA', '060101');

-- ============================================
-- LOCALES - MAKRO (múltiples locales)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_MAKRO_TRUJILLO', 'CLI_MAKRO', 'MAKRO TRUJILLO', 'MAKRO TRUJILLO', '130101'),
                                                                                 ('LOC_MAKRO_PIURA', 'CLI_MAKRO', 'MAKRO PIURA', 'MAKRO PIURA', '200101'),
                                                                                 ('LOC_MAKRO_SULLANA', 'CLI_MAKRO', 'MAKRO SULLANA', 'MAKRO SULLANA', '200601'),
                                                                                 ('LOC_MAKRO_CAÑETE', 'CLI_MAKRO', 'MAKRO CAÑETE', 'MAKRO CA¥ETE', '150501'),
                                                                                 ('LOC_MAKRO_HUANCAYO', 'CLI_MAKRO', 'MAKRO HUANCAYO', 'MAKRO HUANCAYO', '120101');

-- ============================================
-- LOCALES - PRADERAS (múltiples locales)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_PRADERAS_TARAPOTO', 'CLI_PRADERAS', 'PRADERAS TARAPOTO', 'PRADERAS TARAPOTO', '220901'),
                                                                                 ('LOC_PRADERAS_MOYOBAMBA', 'CLI_PRADERAS', 'PRADERAS MOYOBAMBA', 'PRADERAS MOYOBAMBA', '220101'),
                                                                                 ('LOC_PRADERAS_JUANJUI', 'CLI_PRADERAS', 'PRADERAS JUANJUI', 'PRADERAS JUANJUI', '220601'),
                                                                                 ('LOC_PRADERAS_YURIMAGUAS', 'CLI_PRADERAS', 'PRADERAS YURIMAGUAS', 'PRADERAS YURIMAGUAS', '160201'),
                                                                                 ('LOC_PRADERAS_TINGO_MARIA', 'CLI_PRADERAS', 'PRADERAS TINGO MARIA', 'PRADERAS TINGO MARIA', '100601'),
                                                                                 ('LOC_PRADERAS_HUANUCO', 'CLI_PRADERAS', 'PRADERAS HUANUCO', 'PRADERAS HUANUCO', '100101'),
                                                                                 ('LOC_PRADERAS_BAGUA_GRANDE', 'CLI_PRADERAS', 'PRADERAS BAGUA GRANDE', 'PRADERAS BAGUA GRANDE', '010701'),
                                                                                 ('LOC_PRADERAS_LA_MERCED', 'CLI_PRADERAS', 'PRADERAS LA MERCED', 'PRADERAS LA MERCED', '020204');

-- ============================================
-- LOCALES - ECO (múltiples locales)
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                                                                                 ('LOC_ECO_SUAREZ', 'CLI_ECO', 'SUAREZ (ECO)', 'SUAREZ', '120601'),
                                                                                 ('LOC_ECO_FQUISPE', 'CLI_ECO', 'FQUISPE (ECO)', 'FQUISPE', '040202'),
                                                                                 ('LOC_ECO_VALESKA', 'CLI_ECO', 'VALESKA (ECO)', 'VALESKA', '050310'),
                                                                                 ('LOC_ECO_ALM_BAMA', 'CLI_ECO', 'ALM. BAMA (ECO)', 'ALM. BAMA', '190302'),
                                                                                 ('LOC_ECO_CORP_CHRISTIAN', 'CLI_ECO', 'CORP. CHRISTIAN (ECO)', 'CORP. CHRISTIAN', '040601'),
                                                                                 ('LOC_ECO_DISCER', 'CLI_ECO', 'DISCER (ECO)', 'DISCER', '220602'),
                                                                                 ('LOC_ECO_FONPELL', 'CLI_ECO', 'FONPELL (ECO)', 'FONPELL', '130706'),
                                                                                 ('LOC_ECO_CAQUI_INGA', 'CLI_ECO', 'CAQUI INGA (ECO)', 'CAQUI, INGA', '190101'),
                                                                                 ('LOC_ECO_VIRGEN_CARMEN', 'CLI_ECO', 'VIRGEN DEL CARMEN (ECO)', 'VIRGEN DEL CARMEN', '080101'),
                                                                                 ('LOC_ECO_YURACK', 'CLI_ECO', 'YURACK GROUP (ECO)', 'YURACK GROUP', '060401'),
                                                                                 ('LOC_ECO_HUAY_ALVA', 'CLI_ECO', 'HUAY ALVA (ECO)', 'HUAY ALVA', '040101'),
                                                                                 ('LOC_ECO_BEINMA', 'CLI_ECO', 'BEINMA (ECO)', 'BEINMA', '050801');



-- ============================================
-- LOCALES - CLIENTES UNITARIOS (1 local cada uno)
-- palabra_clave = misma que la del cliente
-- ============================================
INSERT INTO locales (id, cliente_id, nombre, palabra_clave, distrito_ubigeo) VALUES
                         ('LOC_SMI', 'CLI_SMI', 'SMI', 'SMI', '150101'),
                         ('LOC_NAVAQUI', 'CLI_NAVAQUI', 'NAVAQUI', 'NAVAQUI', '150142'),
                         ('LOC_HUK', 'CLI_HUK', 'HUK - PUCALLPA', 'HUK|HUK PUCALLPA|HUK DISTRIBUCIONES PUCALLPA', '250101'),
                         ('LOC_DISCER', 'CLI_DISCER', 'DISCER', 'DISCER', '220602'),
                         ('LOC_DISTRIBUIDORA_SELVA', 'CLI_DISTRIBUIDORA_SELVA', 'DISTRIBUIDORA SELVA - PUCALLPA', 'DISTRIBUIDORA SELVA', '250101'),
                         ('LOC_FONPELL', 'CLI_FONPELL', 'FONPELL', 'FONPELL', '130704'),
                         ('LOC_REPRESENTACIONES_ORIENTE', 'CLI_REPRESENTACIONES_ORIENTE', 'REPRESENTACIONES ORIENTE', 'REPRESENTACIONES ORIENTE', '040202'),
                         ('LOC_JAVICHO', 'CLI_JAVICHO', 'JAVICHO', 'JAVICHO', '150115'),
                         ('LOC_TULIPANES', 'CLI_TULIPANES', 'TULIPANES - IQUITOS', 'TULIPANES', '160101'),
                         ('LOC_CANNON', 'CLI_CANNON', 'CANNON', 'CANNON', '050311'),
                         ('LOC_CAQUI_INGA', 'CLI_CAQUI_INGA', 'CAQUI/INGA', 'CAQUI|INGA', '190101'),
                         ('LOC_GRUPO_LU', 'CLI_VARIOS', 'GRUPO LU DESMEDRO', 'GRUPO LU', '150125');
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



# comprobar si los viajes se asignaron bien
SELECT
    a.ref, a.titulo_viaje,GROUP_CONCAT(b.origen) as origen_llamada,GROUP_CONCAT(b.destino) as destino_llamada
FROM `referencias` a
inner join llamadas b
on a.ref = b.ref
group by a.ref;

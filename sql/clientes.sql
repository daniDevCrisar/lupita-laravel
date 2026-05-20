CREATE TABLE IF NOT EXISTS clientes (
                                        id VARCHAR(50) PRIMARY KEY,
                                        nombre VARCHAR(100) NOT NULL,
                                        tipo VARCHAR(50) NOT NULL,
                                        descripcion TEXT,
                                        palabra_clave VARCHAR(100) COMMENT 'Palabra que debe aparecer en el título del viaje'
);

-- ============================================
-- CLIENTES PRINCIPALES
-- ============================================
INSERT INTO clientes (id, nombre, tipo, descripcion, palabra_clave) VALUES
                                                        ('CLI_AJEPER', 'AJEPER', 'Principal', 'Dueña de plantas de producción', 'AJEPER'),
                                                        ('CLI_BABEL', 'BABEL', 'Distribuidor', 'Cadena de centros de distribución', 'BABEL'),
                                                        ('CLI_SALEM', 'SALEM', 'Cadena de Tiendas', 'Cadena de tiendas por delivery', 'SALEM'),
                                                        ('CLI_CODISAL', 'CODISAL', 'Mayorista', 'Distribuidor mayorista nacional', 'CODISAL');

-- ============================================
-- CLIENTES SECUNDARIOS
-- ============================================
INSERT INTO clientes (id, nombre, tipo, descripcion, palabra_clave) VALUES
                                                                        ('CLI_MAKRO', 'MAKRO', 'Mayorista', 'Cadena de hipermercados mayoristas', 'MAKRO'),
                                                                        ('CLI_PRADERAS', 'PRADERAS', 'Distribuidor', 'Distribuidor mayorista nacional', 'PRADERAS'),
                                                                        ('CLI_ECO', 'ECO', 'Distribuidor', 'Grupo de distribuidores ECO', 'ECO'),
                                                                        ('CLI_SPSA', 'SPSA', 'Cadena de Tiendas', 'Supermercados Peruanos (Plaza Vea, Vivanda)', 'SPSA');

-- ============================================
-- CLIENTES UNITARIOS
-- ============================================
INSERT INTO clientes (id, nombre, tipo, descripcion, palabra_clave) VALUES
                                                                        ('CLI_SMI', 'SMI', 'Proveedor', 'Suministro de materiales e insumos', 'SMI'),
                                                                        ('CLI_NAVAQUI', 'NAVAQUI', 'Distribuidor', 'Cliente en zona de Selva Central', 'NAVAQUI'),
                                                                        ('CLI_TDP', 'TDP', 'Cadena de Tiendas', 'Cadena de tiendas/distribuidores', 'TDP'),
                                                                        ('CLI_HUK', 'HUK', 'Distribuidor', 'HUK Distribuciones - Pucallpa', 'HUK'),
                                                                        ('CLI_DISCER', 'DISCER', 'Distribuidor', 'Distribuidor en zona de selva', 'DISCER'),
                                                                        ('CLI_DISTRIBUIDORA_SELVA', 'DISTRIBUIDORA SELVA', 'Distribuidor', 'Distribuidora Selva - Pucallpa', 'DISTRIBUIDORA SELVA'),
                                                                        ('CLI_FONPELL', 'FONPELL', 'Cliente', 'Fondo de Promoción de empleo', 'FONPELL'),
                                                                        ('CLI_REPRESENTACIONES_ORIENTE', 'REPRESENTACIONES ORIENTE', 'Distribuidor', 'Representaciones Oriente', 'REPRESENTACIONES ORIENTE'),
                                                                        ('CLI_JAVICHO', 'JAVICHO', 'Distribuidor', 'Cliente en zona de Selva Central', 'JAVICHO'),
                                                                        ('CLI_TULIPANES', 'TULIPANES', 'Distribuidor', 'Cliente en Iquitos', 'TULIPANES'),
                                                                        ('CLI_CANNON', 'CANNON', 'Distribuidor', 'Cliente en Quillabamba', 'CANNON'),
                                                                        ('CLI_YURACK', 'YURACK GROUP', 'Distribuidor', 'Cliente en Chota', 'YURACK GROUP'),
                                                                        ('CLI_CORP_CHRISTIAN', 'CORP. CHRISTIAN', 'Distribuidor', 'Cliente en Chala', 'CORP. CHRISTIAN'),
                                                                        ('CLI_CAQUI_INGA', 'CAQUI/INGA', 'Distribuidor', 'Cliente en zona de Cerro de Pasco', 'CAQUI');


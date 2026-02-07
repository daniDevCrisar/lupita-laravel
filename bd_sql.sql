CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nickname VARCHAR(30) NOT NULL UNIQUE,
    nombres VARCHAR(80) NOT NULL,
    password VARCHAR(255) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO users (nickname, nombres, password)
VALUES ('DaniXtrem','Pedro Daniel Sotelo Aguirre', '');



CREATE TABLE transportistas (
    id INT AUTO_INCREMENT PRIMARY KEY,

    sis_id VARCHAR(50) NULL,
    vapi_id VARCHAR(50) NULL,

    nombre_empresa VARCHAR(150) NOT NULL,

    telefono VARCHAR(20) NULL,
    correo VARCHAR(100) NULL,
    direccion VARCHAR(200) NULL,

    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP

    UNIQUE KEY uk_sis (sis_id),
    UNIQUE KEY uk_vapi (vapi_id)
);



CREATE TABLE conductores (
    id INT AUTO_INCREMENT PRIMARY KEY,

    sis_id VARCHAR(50) NULL,
    vapi_id VARCHAR(50) NULL,

    nombres VARCHAR(150) NOT NULL,
    telefono VARCHAR(20) NULL,

    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_sis (sis_id),
    UNIQUE KEY uk_vapi (vapi_id)
);


CREATE TABLE razones_finalizacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL UNIQUE,
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(150),
    origen VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO razones_finalizacion (codigo, name, nombre, descripcion, origen) VALUES
('IA_FINALIZA_LLAMADA', 'assistant-ended-call', 'IA corto', 'La llamada fue finalizada por la IA', 'SISTEMA'),
('CONDUCTOR_FINALIZA_LLAMADA', 'customer-ended-call', 'Conductor corto', 'El conductor finalizo la llamada', 'CONDUCTOR'),
('CONDUCTOR_NO_RESPONDE', 'customer-did-not-answer', 'No contesto', 'El conductor no respondio la llamada', 'CONDUCTOR'),
('ERROR_CONEXION_TELEFONIA', 'twilio-failed-to-connect-call', 'Error de conexion', 'Fallo al conectar la llamada', 'TELEFONIA'),
('CONDUCTOR_OCUPADO', 'customer-busy', 'Ocupado', 'La linea del conductor estaba ocupada', 'CONDUCTOR'),
('TIEMPO_MAXIMO_EXCEDIDO', 'exceeded-max-duration', 'Tiempo maximo excedido', 'La llamada supero la duracion permitida', 'SISTEMA'),
('IA_MARCO_NUMERO_INVALIDO', 'twilio-reported-customer-misdialed', 'Ia Marco numero erroneo', 'El numero fue marcado incorrectamente', 'SISTEMA'),
('SILENCIO_PROLONGADO', 'silence-timed-out', 'Silencio prolongado', 'No hubo respuesta de voz por tiempo limite', 'SISTEMA');


-- Campo: llamada_exitosa
-- Tipo sugerido: TINYINT
--
-- Clasificacion de estado de llamada evaluada por la IA
--
-- Valores:
--  1  = Llamada exitosa = EXITOSA
--  0  = Llamada no exitosa = FALLIDA
-- -1  = Desconocido / No evaluado / Vacio = NO_EVALUADA
--
-- Recomendacion:
--  NOT NULL DEFAULT -1
--
-- Ventajas:
--  - Evita uso de NULL reales
--  - Facil para reportes y conteos
--  - Permite estado triestatal (exito / fallo / pendiente)
--
-- Ejemplo de definicion:
--  llamada_exitosa TINYINT NOT NULL DEFAULT -1


CREATE TABLE tipos_llamada (
    id TINYINT PRIMARY KEY,
    codigo VARCHAR(40) NOT NULL UNIQUE,
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(150),
    activo BOOLEAN DEFAULT TRUE
);

INSERT INTO tipos_llamada (id, codigo, nombre, descripcion) VALUES
(0, 'INDETERMINADA', 'Indeterminada', 'La IA no pudo clasificar la llamada'),
(1, 'CONFIRMACION_LLEGADA', 'Confirmacion de Llegada', 'El conductor confirma arribo'),
(2, 'ESPERA_FUERA_PLANTA', 'Tiempo de Espera Fuera de Planta', 'Conductor esperando fuera de instalaciones'),
(3, 'TIEMPO_EN_PLANTA', 'Tiempo en Planta', 'Conductor dentro de planta');


-- Campo: es_entrante
-- Tipo: TINYINT(1)
-- Descripcion: Indica la direccion de la llamada
-- Valores:
--   1 = INBOUND  (el conductor llama a la IA)
--   0 = OUTBOUND (la IA llama al conductor)



-- Tabla / Seccion: etiquetas_clasificacion_llamada
-- Todos los campos son TINYINT(1) DEFAULT 0
-- 1 = aplica la etiqueta
-- 0 = no aplica

-- conductor_confirma
-- El conductor confirma llegada, accion o informacion solicitada

-- buzon_de_voz
-- La llamada llega a buzon o contestadora automatica

-- conductor_contesta_pero_no_habla
-- El conductor responde pero no emite voz o guarda silencio

-- conductor_no_escucha
-- El conductor indica que no escucha o hay audio deficiente de su lado

-- conductor_da_motivos
-- El conductor brinda excusas, razones o justificaciones

-- conductor_mala_senal
-- Mala calidad de señal del conductor (cortes, robotizacion, eco)

-- confusion_en_llamada
-- Ninguna de las partes entiende claramente el motivo

-- contesta_otra_persona
-- Atiende alguien que no es el conductor

-- numero_equivocado
-- Numero incorrecto o no pertenece al conductor

-- conversacion_fluida
-- Dialogo claro, sin interrupciones ni problemas

-- llamada_interesante
-- Llamada con informacion relevante o positiva

-- ia_se_confunde
-- La IA pierde contexto o responde incoherente

-- ia_no_escucha
-- La IA no reconoce correctamente el audio

-- ia_cambia_de_datos
-- La IA altera nombres, numeros o informacion

-- ia_error_interpretacion
-- La IA entiende mal la respuesta del conductor

-- conductor_cuelga
-- El conductor finaliza la llamada abruptamente

-- conductor_no_contesta
-- No responde la llamada

-- error_conexion_telefonica
-- Fallo de red, operador o conexion (timeout, no conecta, corte)

-- llamada_exitosa
-- La llamada cumple su objetivo principal

-- conductor_comportamiento_inadecuado
-- El conductor presenta lenguaje ofensivo, coqueteo, burlas o actitud no profesional

-- ia_comportamiento_incorrecto
-- La IA presenta errores de logica, menciona variables internas,
-- tools, datos tecnicos o responde fuera de contexto durante la llamada


-- ============================================
-- TABLA: llamadas
-- ============================================

DROP TABLE IF EXISTS llamadas;

CREATE TABLE llamadas (

    -- =========================
    -- 1. IDENTIFICACION
    -- =========================
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    call_uuid BINARY(16) NOT NULL,   -- se convertira a bynari
    conductor_id INT,

    -- =========================
    -- 2. CLASIFICACION GENERAL
    -- =========================
    tipo_llamada_id TINYINT,        -- 0 indeterminada / 1 confirmacion / 2 espera / 3 planta
    es_entrante TINYINT(1),         -- 1 inbound / 0 outbound
    razon_finalizacion_id INT,
    llamada_exitosa TINYINT,        -- 1 = exitosa / 0 = no exitosa / -1 = indeterminada

    -- =========================
    -- 3. METRICAS
    -- =========================
    duracion_segundos INT,
    tiempo_espera INT,
    tiempo_en_planta INT,

    -- =========================
    -- 4. ETIQUETAS CONDUCTOR
    -- =========================
    conductor_confirma TINYINT(1),
    conductor_no_contesta TINYINT(1),
    conductor_cuelga TINYINT(1),
    conductor_mala_senal TINYINT(1),
    conductor_no_escucha TINYINT(1),
    conductor_da_motivos TINYINT(1),
    conductor_comportamiento_inadecuado TINYINT(1),

    -- =========================
    -- 5. ETIQUETAS IA
    -- =========================
    ia_comportamiento_incorrecto TINYINT(1),
    ia_no_escucha TINYINT(1),
    ia_se_confunde TINYINT(1),
    ia_cambia_datos TINYINT(1),

    -- =========================
    -- 6. CALIDAD CONVERSACION
    -- =========================
    conversacion_fluida TINYINT(1),
    llamada_interesante TINYINT(1),
    confusion_en_llamada TINYINT(1),

    -- =========================
    -- 7. ERRORES TECNICOS
    -- =========================
    error_conexion_telefonica TINYINT(1),
    buzon_de_voz TINYINT(1),
    numero_equivocado TINYINT(1),

    -- =========================
    -- 8. AUDITORIA
    -- =========================
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- =========================
    -- FOREIGN KEYS (OPCIONAL)
    -- =========================
    FOREIGN KEY (razon_finalizacion_id) REFERENCES razones_finalizacion(id)

);


CREATE TABLE error_origenes (
    id TINYINT SIGNED PRIMARY KEY,
    nombre VARCHAR(20) NOT NULL,
    descripcion VARCHAR(100) NULL
);
INSERT INTO error_origenes (id, nombre, descripcion) VALUES
(-1, 'desconocido', 'No se pudo determinar origen'),
(0,  'humano', 'El chofer no proporciono datos correctos'),
(1,  'ia', 'Falla de inteligencia artificial'),
(2,  'red', 'Problema de conectividad'),
(3,  'sistema', 'Error interno del sistema');




CREATE TABLE tmp_lotes_det (
    lote_id BIGINT NOT NULL,

    vapi_id VARCHAR(100) PRIMARY KEY,
    type VARCHAR(20),

    created_at VARCHAR(50),
    created_at_excel VARCHAR(50),

    llamada_tipo VARCHAR(50),
    ref VARCHAR(10),
    origen VARCHAR(100),
    destino VARCHAR(100),
    telefono VARCHAR(30),
    conductor VARCHAR(150),
    placa VARCHAR(20),
    fecha_prometida VARCHAR(50),

    mensajes_conten TEXT,
    audio VARCHAR(500),

    exitosa_segun_ia VARCHAR(10),
    entro_llamada VARCHAR(10),

    razon_finalizacion VARCHAR(100),
    razon_finalizacion_espanol VARCHAR(150),

    transportista VARCHAR(150),
    analisis_transcripcion VARCHAR(1000),
    analisis_audio VARCHAR(1000),

    conductor_confirma VARCHAR(10),
    buzon_de_voz VARCHAR(10),
    conductor_contesta_pero_no_habla VARCHAR(10),
    conductor_no_escucha VARCHAR(10),
    conductor_da_motivos VARCHAR(10),
    conductor_mala_senal VARCHAR(10),
    confusion_en_llamada VARCHAR(10),
    contesta_otra_persona VARCHAR(10),
    numero_equivocado VARCHAR(10),
    conversacion_fluida VARCHAR(10),
    llamada_interesante VARCHAR(10),

    ia_se_confunde VARCHAR(10),
    ia_no_escucha VARCHAR(10),
    ia_cambio_de_datos VARCHAR(10),
    ia_error_interpretacion VARCHAR(10),
    ia_dice_variable VARCHAR(10),
    ia_mala_pronunciacion VARCHAR(10),

    conductor_cuelga VARCHAR(10),
    conductor_no_contesta VARCHAR(10),

    error_tecnico_llamada VARCHAR(10),
    error_audio VARCHAR(10),

    error_origen VARCHAR(10),

    llamada_exitosa VARCHAR(10)
);



CREATE TABLE tmp_lotes (
    lote_id BIGINT PRIMARY KEY,
    usuario_id INT NOT NULL,
    tipo TINYINT(1),  -- para q sea escalable en este solo seria 1 para el json basico de vapi
    nombre VARCHAR(50) NOT NULL,
    comentario VARCHAR(200) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE tmp_lotes_ref_completo (

    lote_id VARCHAR(100) NOT NULL,

    ref VARCHAR(10) NULL,
    trt VARCHAR(100) NULL,
    tlf_conductor VARCHAR(50) NULL,
    titulo_viaje VARCHAR(200) NULL,
    placa VARCHAR(20) NULL,

    fin_descargue VARCHAR(50) NULL,
    inicio_descargue VARCHAR(50) NULL,
    qr_llegada_destino VARCHAR(50) NULL,
    fin_de_carga VARCHAR(50) NULL,
    inicio_de_carga VARCHAR(50) NULL,
    presenta_para_carga VARCHAR(50) NULL,


    INDEX idx_lote_id (lote_id),
    INDEX idx_ref (ref),
    INDEX idx_placa (placa),
    INDEX idx_tlf_conductor (tlf_conductor)
);


CREATE TABLE tmp_lotes_ref_compromiso (

    lote_id VARCHAR(100) NOT NULL,

    ref VARCHAR(10) NULL,
    trt VARCHAR(100) NULL,
    tlf_conductor VARCHAR(50) NULL,
    titulo_viaje VARCHAR(200) NULL,
    placa VARCHAR(20) NULL,

    fin_de_carga VARCHAR(50) NULL,
    inicio_de_carga VARCHAR(50) NULL,
    presenta_para_carga VARCHAR(50) NULL,

    INDEX idx_lote_id (lote_id),
    INDEX idx_ref (ref),
    INDEX idx_placa (placa),
    INDEX idx_tlf_conductor (tlf_conductor)

);
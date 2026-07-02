SET GLOBAL lc_time_names = 'es_ES';

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


ALTER TABLE users
    ADD COLUMN name VARCHAR(255) AFTER nickname,
    ADD COLUMN email VARCHAR(255) UNIQUE,
    ADD COLUMN email_verified_at TIMESTAMP NULL,
    ADD COLUMN remember_token VARCHAR(100) NULL,
    ADD COLUMN updated_at TIMESTAMP NULL;



CREATE TABLE trts (
    id INT AUTO_INCREMENT PRIMARY KEY,

    sis_id VARCHAR(9) NULL,
    vapi_id VARCHAR(9) NULL,

    nombres VARCHAR(150) NOT NULL,

    ruc VARCHAR(12) NULL,

    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FULLTEXT(nombres)
);

DELIMITER $$

CREATE PROCEDURE sp_insertar_o_obtener_trts(
    IN p_sis_id   VARCHAR(9),
    IN p_vapi_id  VARCHAR(9),
    IN p_nombres  VARCHAR(150),
    IN p_ruc      VARCHAR(12)
)
BEGIN
    DECLARE v_id INT;
    DECLARE v_es_nuevo BOOLEAN DEFAULT FALSE;

    -- Buscar si ya existe por nombre
    SELECT id INTO v_id
    FROM trts
    WHERE nombres = p_nombres
    LIMIT 1;

    -- Si no existe, insertar
    IF v_id IS NULL THEN
        INSERT INTO trts (
            sis_id,
            vapi_id,
            nombres,
            ruc
        ) VALUES (
            p_sis_id,
            p_vapi_id,
            p_nombres,
            p_ruc
        );

        SET v_id = LAST_INSERT_ID();
        SET v_es_nuevo = TRUE;
    END IF;

    -- Devolver resultado
    SELECT
        v_id AS id,
        v_es_nuevo AS es_nuevo;
END $$



CREATE TABLE conductores (
    id INT AUTO_INCREMENT PRIMARY KEY,

    sis_id VARCHAR(9) NULL,
    vapi_id VARCHAR(9) NULL,

    nombres VARCHAR(150) NOT NULL,
    trofeos JSON NOT NULL DEFAULT (JSON_OBJECT()),
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FULLTEXT(nombres)
);

ALTER TABLE conductores
    ADD COLUMN trofeos JSON NOT NULL DEFAULT (JSON_OBJECT()) AFTER nombres;

ALTER TABLE conductores ADD FULLTEXT(nombres);

CREATE TABLE tlf_conductores (
    conductor_id INT NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (conductor_id, telefono),
    INDEX idx_telefono (telefono)
);

ALTER TABLE tlf_conductores
ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN activo TINYINT(1) DEFAULT 1;

-- +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
CREATE TABLE referencias (
    ref VARCHAR(10) PRIMARY KEY,
    trt_id INT,
    conductor_id INT,
    fecha_despachador TIMESTAMP DEFAULT NULL,
    titulo_viaje VARCHAR(200) NOT NULL DEFAULT '',
    placa VARCHAR(10) NOT NULL DEFAULT '',

    fin_descargue TIMESTAMP DEFAULT NULL,
    inicio_descargue TIMESTAMP DEFAULT NULL,
    qr_llegada_destino TIMESTAMP DEFAULT NULL,
    inicio_ruta TIMESTAMP DEFAULT NULL,

    fin_de_carga TIMESTAMP DEFAULT NULL,
    inicio_de_carga TIMESTAMP DEFAULT NULL,
    presenta_para_carga TIMESTAMP DEFAULT NULL,
    compromiso_carga TIMESTAMP DEFAULT NULL

);

ALTER TABLE referencias
    ADD COLUMN inicio_ruta TIMESTAMP DEFAULT NULL AFTER qr_llegada_destino;

ALTER TABLE referencias
    MODIFY COLUMN conductor_id INT NULL;
    ADD COLUMN compromiso_carga TIMESTAMP DEFAULT NULL;

-- ++++++++++++++++++++++++++++++++++++++++++++
DELIMITER $$

CREATE PROCEDURE sp_insertar_o_nueva_referencia(
    IN p_ref VARCHAR(10),
    IN p_trt_id INT,
    IN p_conductor_id INT,
    IN p_fecha_despachador TIMESTAMP,
    IN p_titulo_viaje VARCHAR(200),
    IN p_placa VARCHAR(10),
    IN p_fin_descargue TIMESTAMP,
    IN p_inicio_descargue TIMESTAMP,
    IN p_qr_llegada_destino TIMESTAMP,
    IN p_fin_de_carga TIMESTAMP,
    IN p_inicio_de_carga TIMESTAMP,
    IN p_presenta_para_carga TIMESTAMP
)
BEGIN
    DECLARE v_existe INT DEFAULT 0;
    DECLARE v_id INT DEFAULT 0;
    DECLARE v_es_nuevo BOOLEAN DEFAULT FALSE;

    -- Verificar si existe la referencia
    SELECT COUNT(*) INTO v_existe
    FROM referencias
    WHERE ref = p_ref;

    IF v_existe > 0 THEN
        -- UPDATE - ya existe
        UPDATE referencias SET
            trt_id = p_trt_id,
            conductor_id = p_conductor_id,
            fecha_despachador = p_fecha_despachador,
            titulo_viaje = p_titulo_viaje,
            placa = p_placa,
            fin_descargue = p_fin_descargue,
            inicio_descargue = p_inicio_descargue,
            qr_llegada_destino = p_qr_llegada_destino,
            fin_de_carga = p_fin_de_carga,
            inicio_de_carga = p_inicio_de_carga,
            presenta_para_carga = p_presenta_para_carga
        WHERE ref = p_ref;

        -- Obtener ID (como no hay campo id, usamos ref como identificador)
        SET v_id = 0; -- Podrías usar algo como: SELECT id FROM ... WHERE ref = p_ref;
        SET v_es_nuevo = FALSE;
    ELSE
        -- INSERT - es nuevo
        INSERT INTO referencias (
            ref, trt_id, conductor_id, fecha_despachador,
            titulo_viaje, placa, fin_descargue, inicio_descargue,
            qr_llegada_destino, fin_de_carga, inicio_de_carga, presenta_para_carga
        ) VALUES (
            p_ref, p_trt_id, p_conductor_id, p_fecha_despachador,
            p_titulo_viaje, p_placa, p_fin_descargue, p_inicio_descargue,
            p_qr_llegada_destino, p_fin_de_carga, p_inicio_de_carga, p_presenta_para_carga
        );

        -- Para INSERT podemos usar LAST_INSERT_ID() si tuvieras id autoincremental
        SET v_id = 0; -- Como no hay id, usamos 0 o podrías usar ROW_COUNT()
        SET v_es_nuevo = TRUE;
    END IF;

    -- Devolver resultado
    SELECT p_ref AS ref, v_es_nuevo AS es_nuevo;

END$$

DELIMITER ;

-- +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++


CREATE TABLE razones_finalizacion (
    id INT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL UNIQUE,
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(150),
    origen VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO razones_finalizacion (id, codigo, name, nombre, descripcion, origen) VALUES
(0, 'DESCONOCIDO', 'UNKNOWN', 'Razon desconocida', 'No se pudo determinar la razón de finalización', 'SISTEMA'),
(1, 'IA-FINALIZA-LLAMADA', 'ASSISTANT-ENDED-CALL', 'IA corto', 'La llamada fue finalizada por la IA', 'SISTEMA'),
(2, 'CONDUCTOR-FINALIZA-LLAMADA', 'CUSTOMER-ENDED-CALL', 'Conductor corto', 'El conductor finalizo la llamada', 'CONDUCTOR'),
(3, 'CONDUCTOR-NO-RESPONDE', 'CUSTOMER-DID-NOT-ANSWER', 'No contesto', 'El conductor no respondio la llamada', 'CONDUCTOR'),
(4, 'ERROR-CONEXION-TELEFONIA', 'TWILIO-FAILED-TO-CONNECT-CALL', 'Error de conexion', 'Fallo al conectar la llamada', 'TELEFONIA'),
(5, 'CONDUCTOR-OCUPADO', 'CUSTOMER-BUSY', 'Ocupado', 'La linea del conductor estaba ocupada', 'CONDUCTOR'),
(6, 'TIEMPO-MAXIMO-EXCEDIDO', 'EXCEEDED-MAX-DURATION', 'Tiempo maximo excedido', 'La llamada supero la duracion permitida', 'SISTEMA'),
(7, 'IA-MARCO-NUMERO-INVALIDO', 'TWILIO-REPORTED-CUSTOMER-MISDIALED', 'Ia Marco numero erroneo', 'El numero fue marcado incorrectamente', 'SISTEMA'),
(8, 'SILENCIO-PROLONGADO', 'SILENCE-TIMED-OUT', 'Silencio prolongado', 'No hubo respuesta de voz por tiempo limite', 'SISTEMA'),
(9, 'ERROR_VAPI_SIN_WORKERS', 'CALL.IN-PROGRESS.ERROR-VAPIFAULT-WORKER-NOT-AVAILABLE', 'Error VAPI - Worker no disponible', 'La plataforma VAPI no tiene workers disponibles para procesar la llamada', 'SISTEMA'),
(10, 'CONEXIÓN_FANTASMA', 'CALL.IN-PROGRESS.TWILIO-COMPLETED-CALL', 'Twilio puso fin a la llamada de su lado', 'Twilio logró conectar con la red del destinatario pero se cerro sin intercambiar datos', 'SISTEMA');



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
    descripcion VARCHAR(150) NOT NULL,
    reporte_titulo VARCHAR(80) NOT NULL,
    color VARCHAR(10) DEFAULT '',
    emoji VARCHAR(10) DEFAULT '',
    activo BOOLEAN DEFAULT TRUE
);

INSERT INTO tipos_llamada (id, codigo, nombre, descripcion,reporte_titulo,color,emoji) VALUES
(0, 'INDETERMINADA', 'Indeterminada', 'La IA no pudo clasificar la llamada','Reporte de llamadas indeterminadas','','?'),
(1, 'CONFIRMACION_LLEGADA', 'Confirmacion', 'El conductor confirma arribo de carga','Reporte de Confirmaciones VAPI','success','✅'),
(2, 'ESPERA_FUERA_PLANTA_CARGA', 'Fuera de planta para carga', 'Conductor esperando fuera de instalaciones para cargar','Reporte de Espera fuera de planta para carga VAPI','primary','🛻'),
(3, 'TIEMPO_EN_PLANTA_CARGA', 'Dentro de planta para carga', 'Conductor dentro de planta cargando','Reporte de Espera dentro de planta para carga VAPI','info','🏭'),
(4, 'EN_RUTA', 'En ruta', '','','warning','🛣️'),
(5, 'ESPERA_FUERA_PLANTA_DESCARGA', 'Fuera de planta para descarga', 'Conductor esperando fuera de instalaciones para descargar','Reporte de Espera fuera de planta para descarga VAPI','danger','🚛'),
(6, 'TIEMPO_EN_PLANTA_DESCARGA', 'Dentro de planta para descarga', 'Conductor dentro de planta descargando','Reporte de Espera dentro de planta para descarga VAPI','success','🏁');


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
    vapi_id VARCHAR(36) PRIMARY KEY NOT NULL,   -- se convertira a bynari
    lote_id VARCHAR(100) NOT NULL,

    conductor_id INT not null,
    trt_id INT,
    telefono VARCHAR(20) NOT NULL,
    ref int, -- por si no tiene ref
    -- mensansaje en otra tabla

    -- =========================
    -- 2. CLASIFICACION GENERAL
    -- =========================
    llamada_tipo_id TINYINT,        -- 0 indeterminada / 1 confirmacion / 2 espera / 3 planta
    es_entrante TINYINT(1),         -- 1 inbound / 0 outbound
    razon_finalizacion_id INT,
    entro_llamada TINYINT(1),
    exitosa_segun_ia TINYINT(1),
    llamada_exitosa TINYINT(1),        -- 1 = exitosa / 0 = no exitosa / -1 = indeterminada

    -- =========================
    -- 3. METRICAS
    -- =========================
    costo DECIMAL(10,4) DEFAULT 0,
    audio_link VARCHAR(255) NOT NULL DEFAULT '',
    audio_duracion INT DEFAULT 0,

    ia_result_delay_reason_desc VARCHAR(255) NOT NULL DEFAULT '',
    ia_result_comments_text VARCHAR(255) NOT NULL DEFAULT '',

    analisis_transcripcion VARCHAR(255) NOT NULL DEFAULT '',
    analisis_audio VARCHAR(255) NOT NULL DEFAULT '',

    -- =========================
    -- 4. ETIQUETAS CONDUCTOR
    -- =========================
    conductor_confirma TINYINT(1) DEFAULT 0,
    buzon_de_voz TINYINT(1) DEFAULT 0,
    conductor_contesta_pero_no_habla TINYINT(1) DEFAULT 0,
    conductor_no_escucha TINYINT(1) DEFAULT 0,
    conductor_da_motivos TINYINT(1) DEFAULT 0,
    conductor_mala_senal TINYINT(1) DEFAULT 0,
    confusion_en_llamada TINYINT(1) DEFAULT 0,
    contesta_otra_persona TINYINT(1) DEFAULT 0,
    numero_equivocado TINYINT(1) DEFAULT 0,
    conversacion_fluida TINYINT(1) DEFAULT 0,
    llamada_interesante TINYINT(1) DEFAULT 0,

    ia_se_confunde TINYINT(1) DEFAULT 0,
    ia_no_escucha TINYINT(1) DEFAULT 0,
    ia_cambio_de_datos TINYINT(1) DEFAULT 0,
    ia_error_interpretacion TINYINT(1) DEFAULT 0,
    ia_dice_variable TINYINT(1) DEFAULT 0,
    ia_mala_pronunciacion TINYINT(1) DEFAULT 0,
    ia_cuelga_en_plena_llamada TINYINT(1) DEFAULT 0,

    conductor_cuelga TINYINT(1) DEFAULT 0,
    conductor_no_contesta TINYINT(1) DEFAULT 0,
    conductor_conducta_inapropiada TINYINT(1) DEFAULT 0,

    error_tecnico_llamada TINYINT(1) DEFAULT 0,
    error_audio TINYINT(1) DEFAULT 0,

    error_origen TINYINT(1) DEFAULT 0,
    -- =========================
    -- 8. AUDITORIA
    -- =========================
    procesado TINYINT(1) DEFAULT 0, -- si la llamad fue analizada por un hnumano
    fecha_prometida TIMESTAMP DEFAULT NULL,
    origen VARCHAR(100) NOT NULL DEFAULT '',
    destino VARCHAR(100) NOT NULL DEFAULT '',
    placa VARCHAR(20),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

    -- =========================
    -- FOREIGN KEYS (OPCIONAL)
    -- =========================
);

ALTER TABLE llamadas
    ADD COLUMN costo DECIMAL(10,4) DEFAULT 0 AFTER llamada_exitosa,
    ADD COLUMN ia_result_delay_reason_desc VARCHAR(255) DEFAULT '' AFTER audio_duracion,
    ADD COLUMN ia_result_comments_text VARCHAR(255) DEFAULT '' AFTER ia_result_delay_reason_desc;

update `llamadas` set `error_origen`= 2 where `razon_finalizacion_id`= 4;
update `llamadas` set `error_origen`= 3 where `razon_finalizacion_id`= 7  or `razon_finalizacion_id`= 9;


CREATE TABLE mensajes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vapi_id VARCHAR(36) NOT NULL,   -- UUID en formato binario
    orden INT NOT NULL,
    tipo ENUM('BOT', 'USER') NOT NULL,  -- Solo BOT o USER
    mensaje TEXT,

    INDEX idx_vapi_id (vapi_id),
    INDEX idx_orden (orden)
);


CREATE TABLE error_origen (
    id TINYINT PRIMARY KEY,
    nombre VARCHAR(20) NOT NULL,
    descripcion VARCHAR(100) NULL
);

INSERT INTO error_origen (id, nombre, descripcion) VALUES
(-1, 'Desconocido', 'No se pudo determinar origen'),
(0,  'Humano', 'El chofer no proporciono datos correctos'),
(1,  'IA', 'Falla de inteligencia artificial'),
(2,  'Red', 'Problema de conectividad'),
(3,  'Sistema', 'Error interno del sistema');




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
    audio_duracion VARCHAR(6),
    costo varchar(25),

    exitosa_segun_ia VARCHAR(10),
    entro_llamada VARCHAR(10),

    razon_finalizacion VARCHAR(100),
    razon_finalizacion_espanol VARCHAR(150),

    transportista VARCHAR(150),

    ia_result_comments_text VARCHAR(255),
    ia_result_delay_reason_desc VARCHAR(255),

    analisis_transcripcion VARCHAR(255),
    analisis_audio VARCHAR(255),

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
    ia_cuelga_en_plena_llamada VARCHAR(10),

    conductor_cuelga VARCHAR(10),
    conductor_no_contesta VARCHAR(10),
    conductor_conducta_inapropiada VARCHAR(10),

    error_tecnico_llamada VARCHAR(10),
    error_audio VARCHAR(10),

    error_origen VARCHAR(10),

    llamada_exitosa VARCHAR(10)
);

ALTER TABLE tmp_lotes_det
    ADD COLUMN costo VARCHAR(25) DEFAULT '' AFTER audio_duracion,
    ADD COLUMN ia_result_comments_text VARCHAR(255) DEFAULT '' AFTER transportista,
    ADD COLUMN ia_result_delay_reason_desc VARCHAR(255) DEFAULT '' AFTER ia_result_comments_text;


CREATE TABLE tmp_lotes (
    lote_id BIGINT PRIMARY KEY,
    usuario_id INT NOT NULL,
    tipo TINYINT(1),  -- para q sea escalable en este solo sería 1 para el json basico de vapi
    nombre VARCHAR(50) NOT NULL,
    comentario VARCHAR(200) NOT NULL,
    procesado TINYINT(1) NOT NULL DEFAULT 0 ,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE tmp_lotes_ref (

    lote_id VARCHAR(100) NOT NULL,

    ref VARCHAR(10) NULL,
    trt VARCHAR(100) NULL,
    tlf_conductor VARCHAR(50) NULL,
    titulo_viaje VARCHAR(200) NULL,
    placa VARCHAR(20) NULL,

    fin_descargue VARCHAR(50) NULL,
    inicio_descargue VARCHAR(50) NULL,
    qr_llegada_destino VARCHAR(50) NULL,
    inicio_ruta VARCHAR(50) NULL,
    fin_de_carga VARCHAR(50) NULL,
    inicio_de_carga VARCHAR(50) NULL,
    presenta_para_carga VARCHAR(50) NULL,

    compromiso_carga VARCHAR(50) NULL,
    fecha_despachador VARCHAR(50) NULL,
    PRIMARY KEY (lote_id, ref)
);

ALTER TABLE tmp_lotes_ref
    ADD COLUMN inicio_ruta  VARCHAR(50) NULL AFTER qr_llegada_destino;

ALTER TABLE tmp_lotes_ref
    ADD COLUMN fecha_despachador VARCHAR(50) NULL;
ALTER TABLE tmp_lotes_ref
    ADD PRIMARY KEY (lote_id, ref);

CREATE TABLE tmp_lotes_ref_compromiso (

    lote_id VARCHAR(100) NOT NULL,

    ref VARCHAR(10) NULL,
    fecha_llamada VARCHAR(50) NULL,
    trt VARCHAR(100) NULL,
    tlf_conductor VARCHAR(50) NULL,
    fecha_despachador VARCHAR(50) NULL,
    titulo_viaje VARCHAR(200) NULL,
    placa VARCHAR(20) NULL,

    fin_descargue VARCHAR(50) NULL,
    inicio_descargue VARCHAR(50) NULL,
    qr_llegada_destino VARCHAR(50) NULL,
    fin_de_carga VARCHAR(50) NULL,
    inicio_de_carga VARCHAR(50) NULL,
    presenta_para_carga VARCHAR(50) NULL,

    compromiso_carga VARCHAR(50) NULL
);

ALTER TABLE tmp_lotes_ref_compromiso
    ADD COLUMN compromiso_carga VARCHAR(50) NULL;

DROP TABLE IF EXISTS log_conductores;
CREATE TABLE log_conductores (
    id_log_conductor INT AUTO_INCREMENT PRIMARY KEY,
    id_conductor INT NOT NULL,
    last_id_trt INT NOT NULL,

    -- Fechas
    fecha_inicio TIMESTAMP NULL,
    fecha_fin TIMESTAMP NULL,

    -- Métricas de llamadas
    metricas JSON NULL,

    -- Etiquetas de IA (resultados de llamadas)
    etiquetas_1 JSON NULL,  -- Etiquetas positivas (confirmó, aceptó, etc)
    etiquetas_0 JSON NULL,  -- Etiquetas negativas (no contesta, cuelga, etc)

    -- Log de evasión
    analisis TEXT NULL,
    accion TEXT NULL,
    respuesta TEXT NULL,
    status ENUM('EN CURSO', 'CERRADO', 'CANCELADO') DEFAULT 'EN CURSO',
    ubicacion ENUM ('LIMA','PROVINCIA'),
    id_conclusion INT NULL ,

         -- Metadatos
    telefonos JSON NULL,    -- Historial de teléfonos usados
    rutas JSON NULL,		-- Historial de rutas

    -- Auditoría
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

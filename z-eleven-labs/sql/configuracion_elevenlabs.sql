-- ===========================================================================
-- Configuracion del modulo Eleven Labs
--
-- QUE TRAE: estructura + filas de las 4 tablas de configuracion del modulo.
-- NO TRAE: llamadas, lotes, transcripciones ni datos personales.
--
--   elevenlabs_agentes        11 fila(s)
--   elevenlabs_mapeos_campo   51 fila(s)
--   elevenlabs_mapeos_regla   20 fila(s)
--   elevenlabs_opciones        1 fila(s)
--
-- GENERADO: 2026-11-20 13:16:30 desde lupita_bd
--
-- COMO USARLO
--   mysql -u USUARIO -p TU_BASE < configuracion_elevenlabs.sql
--
--   Es re-ejecutable: cada corrida borra y vuelve a crear las 4 tablas de
--   configuracion con exactamente lo de este volcado. Correrlo dos veces deja
--   el mismo resultado.
--
-- QUE NO TOCA: llamadas, tmp_lotes, tmp_lotes_det, scoring, mensajes ni ninguna
-- otra tabla. Tampoco crea el modulo (eso son las migraciones).
--
-- SI PREFIERES MIGRACIONES en vez de este archivo, las que crean y ajustan
-- estas tablas son, en orden:
--   2026_11_19_150000_create_elevenlabs_agentes_table
--   2026_11_19_160000_create_elevenlabs_mapeos_tables
--   2026_11_19_170000_ordena_elevenlabs_mapeos_regla
--   2026_11_20_100000_rutas_candidatas_en_mapeos_campo
--   2026_11_20_110000_cadenas_de_rutas_candidatas
--   2026_11_20_130000_agrega_comparacion_diferente
--   2026_11_20_150000_agrega_comparacion_else
--   2026_11_20_160000_create_elevenlabs_opciones_table
--   (y los seeders ElevenLabsPermissionSeeder + ElevenLabsMapeoSeeder, que este
--   archivo reemplaza: los datos de abajo ya son el resultado final).
--
-- OJO: reemplaza la configuracion que hubiera en produccion por esta.
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------- elevenlabs_agentes
DROP TABLE IF EXISTS `elevenlabs_agentes`;
CREATE TABLE `elevenlabs_agentes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'agent_... tal como lo devuelve /v1/convai/agents',
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'nombre del agente, sincronizado desde la API',
  `tipo_llamada_id` tinyint unsigned DEFAULT NULL COMMENT '1,2,3,5,6 del enum de tipos; NULL = no entra al lote',
  `incluir_en_lote` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'entra en la importacion al lote',
  `activo` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 si el agente se archivo o ya no se usa',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `elevenlabs_agentes_agent_id_unique` (`agent_id`),
  KEY `idx_el_agentes_tipo` (`tipo_llamada_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- elevenlabs_mapeos_campo
DROP TABLE IF EXISTS `elevenlabs_mapeos_campo`;
CREATE TABLE `elevenlabs_mapeos_campo` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campo_destino` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'columna de tmp_lotes_det segun DBColumns::tmpLotesDet()',
  `tipo_origen` enum('campo','constante','regla','calculado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'campo' COMMENT 'campo = leer una ruta | constante = valor fijo | regla = lo resuelve elevenlabs_mapeos_regla | calculado = codigo',
  `ruta_origen` text COLLATE utf8mb4_unicode_ci,
  `valor_constante` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'valor fijo cuando tipo_origen = constante',
  `transformacion` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ninguna' COMMENT 'ninguna|texto|numero|telefono|segundos_a_ms|mayusculas|minusculas|truncar_255|json|fecha_texto',
  `valor_por_defecto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'se usa cuando la ruta no existe o viene vacia',
  `orden` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'orden de presentacion; replica el orden de DBColumns::tmpLotesDet()',
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `elevenlabs_mapeos_campo_campo_destino_unique` (`campo_destino`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- elevenlabs_mapeos_regla
DROP TABLE IF EXISTS `elevenlabs_mapeos_regla`;
CREATE TABLE `elevenlabs_mapeos_regla` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `clave` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'identificador estable de las reglas sembradas; NULL en las creadas por el usuario',
  `ruta_origen` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ruta del valor disparador, ej. metadata.termination_reason',
  `comparacion` enum('igual','diferente','contiene','empieza_por','vacio','no_vacio','else') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'igual',
  `valor_origen` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'NULL en vacio / no_vacio',
  `campo_destino` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'columna de tmp_lotes_det que se escribe',
  `valor_destino` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'valor a escribir; en razon_finalizacion debe ser un name exacto de razones_finalizacion',
  `prioridad` int NOT NULL DEFAULT '100' COMMENT 'menor = se evalua antes; para la misma columna gana la primera que cumple',
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `elevenlabs_mapeos_regla_clave_unique` (`clave`),
  KEY `idx_el_reglas_campo_prioridad` (`campo_destino`,`prioridad`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- elevenlabs_opciones
DROP TABLE IF EXISTS `elevenlabs_opciones`;
CREATE TABLE `elevenlabs_opciones` (
  `clave` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'valor de la opcion; en las listas, valores separados por coma',
  `etiqueta` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'nombre visible en la pantalla de Equivalencias',
  `ayuda` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'texto de apoyo debajo de la opcion',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

-- elevenlabs_agentes (11 filas)
INSERT INTO `elevenlabs_agentes` (`id`, `agent_id`, `nombre`, `tipo_llamada_id`, `incluir_en_lote`, `activo`, `created_at`, `updated_at`) VALUES (6, 'agent_0101m491x66gev18ymvwsrrk4xda', 'Efletexia - Time in Plant (ElevenLabs)', 6, 1, 1, '2026-11-19 13:30:33', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_agentes` (`id`, `agent_id`, `nombre`, `tipo_llamada_id`, `incluir_en_lote`, `activo`, `created_at`, `updated_at`) VALUES (3, 'agent_0301m50v57r0fj8rmjqmz9bckq16', 'Efletexia - Hoja de Vida', NULL, 0, 0, '2026-11-19 13:30:33', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_agentes` (`id`, `agent_id`, `nombre`, `tipo_llamada_id`, `incluir_en_lote`, `activo`, `created_at`, `updated_at`) VALUES (5, 'agent_0401m491xmwzfsnbged5sr4ttnaw', 'Efletexia - Waiting Time In Customer (ElevenLabs)', 3, 1, 1, '2026-11-19 13:30:33', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_agentes` (`id`, `agent_id`, `nombre`, `tipo_llamada_id`, `incluir_en_lote`, `activo`, `created_at`, `updated_at`) VALUES (4, 'agent_1801m492wf8efay8qqx08hsv88py', 'Efletexia - Waiting Time (ElevenLabs)', 2, 1, 1, '2026-11-19 13:30:33', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_agentes` (`id`, `agent_id`, `nombre`, `tipo_llamada_id`, `incluir_en_lote`, `activo`, `created_at`, `updated_at`) VALUES (8, 'agent_2001m3zcwkcffep88y92x7xs41gd', 'Efletexia - In Route (ElevenLabs)', 4, 1, 1, '2026-11-19 13:30:33', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_agentes` (`id`, `agent_id`, `nombre`, `tipo_llamada_id`, `incluir_en_lote`, `activo`, `created_at`, `updated_at`) VALUES (11, 'agent_2101m0dx8hw3ehasf49ry4dkke3w', 'DespachIA - Camila', NULL, 0, 0, '2026-11-19 13:30:33', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_agentes` (`id`, `agent_id`, `nombre`, `tipo_llamada_id`, `incluir_en_lote`, `activo`, `created_at`, `updated_at`) VALUES (7, 'agent_4701m491wqxdf9xrp2wryvz2z9g8', 'Efletexia - Time in Download (ElevenLabs)', 5, 1, 1, '2026-11-19 13:30:33', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_agentes` (`id`, `agent_id`, `nombre`, `tipo_llamada_id`, `incluir_en_lote`, `activo`, `created_at`, `updated_at`) VALUES (10, 'agent_5901m2zvgqjeey1bd39vmjyayybc', 'DespachIA - Camila Inbound', NULL, 0, 0, '2026-11-19 13:30:33', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_agentes` (`id`, `agent_id`, `nombre`, `tipo_llamada_id`, `incluir_en_lote`, `activo`, `created_at`, `updated_at`) VALUES (1, 'agent_6101m7670pnmfsfbvsyp22e74z6g', 'EfletexiaT2 - ChatBot', NULL, 0, 0, '2026-11-19 13:30:33', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_agentes` (`id`, `agent_id`, `nombre`, `tipo_llamada_id`, `incluir_en_lote`, `activo`, `created_at`, `updated_at`) VALUES (9, 'agent_6201m3zctfb3fh3sjmdbpfmndq37', 'Efletexia - Compromiso Carga (ElevenLabs)', 1, 1, 1, '2026-11-19 13:30:33', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_agentes` (`id`, `agent_id`, `nombre`, `tipo_llamada_id`, `incluir_en_lote`, `activo`, `created_at`, `updated_at`) VALUES (2, 'agent_6701m71ysee4fyh8p9t3xmgsegax', 'DespachIA - Asignación (placa y conductor)', NULL, 0, 0, '2026-11-19 13:30:33', '2026-11-20 12:13:59');

-- elevenlabs_mapeos_campo (51 filas)
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (1, 'lote_id', 'calculado', NULL, NULL, 'ninguna', NULL, 0, 'Se genera al crear el lote.', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (2, 'vapi_id', 'calculado', NULL, NULL, 'ninguna', NULL, 1, 'Es el conversation_id de ElevenLabs. Se escribe aqui porque es la llave de llamadas, mensajes y scoring.', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (3, 'type', 'regla', NULL, NULL, 'ninguna', '', 2, 'Solo informativo: outboundPhoneCall (llamo la IA) o inboundPhoneCall (llamo el humano), segun metadata.phone_call.direction.', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (4, 'created_at', 'campo', 'metadata.start_time_unix_secs', NULL, 'segundos_a_ms', NULL, 3, 'El pipeline divide entre 1000 y luego aplica FROM_UNIXTIME.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (5, 'created_at_excel', 'constante', NULL, NULL, 'ninguna', NULL, 4, 'Solo servia para el Excel de VAPI; aqui no se usa.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (6, 'llamada_tipo', 'calculado', NULL, NULL, 'ninguna', NULL, 5, 'Sale del catalogo de asistentes (pestana Asistentes).', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (7, 'ref', 'campo', 'conversation_initiation_client_data.dynamic_variables.reference\nconversation_initiation_client_data.dynamic_variables.trip_code\nconversation_initiation_client_data.dynamic_variables.referencia\nconversation_initiation_client_data.dynamic_variables.ref\nconversation_initiation_client_data.dynamic_variables.referencia', NULL, 'texto', NULL, 6, 'Si viene vacio se puede apuntar a trip_code.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (8, 'origen', 'campo', 'conversation_initiation_client_data.dynamic_variables.origen\nconversation_initiation_client_data.dynamic_variables.origen_nombre\nconversation_initiation_client_data.dynamic_variables.desde', NULL, 'ninguna', NULL, 7, '', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (9, 'destino', 'campo', 'conversation_initiation_client_data.dynamic_variables.destino\nconversation_initiation_client_data.dynamic_variables.destino_nombre\nconversation_initiation_client_data.dynamic_variables.hacia', NULL, 'ninguna', NULL, 8, '', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (10, 'telefono', 'campo', 'conversation_initiation_client_data.dynamic_variables.telefono\nconversation_initiation_client_data.dynamic_variables.celular\nconversation_initiation_client_data.dynamic_variables.telefono_conductor\nmetadata.phone_call.external_number', NULL, 'telefono', NULL, 9, 'Quita el + y todo lo que no sea digito.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (11, 'conductor', 'campo', 'conversation_initiation_client_data.dynamic_variables.carrier_name\nconversation_initiation_client_data.dynamic_variables.nombre_transportista\nconversation_initiation_client_data.dynamic_variables.driver_name\nconversation_initiation_client_data.dynamic_variables.conductor', NULL, 'mayusculas', NULL, 10, 'En inbound viene vacio.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (12, 'placa', 'campo', 'conversation_initiation_client_data.dynamic_variables.placa\nconversation_initiation_client_data.dynamic_variables.vehicle_plate\nconversation_initiation_client_data.dynamic_variables.placa_vehiculo', NULL, 'alfanumerico', NULL, 11, 'ElevenLabs todavia no envia la placa: dejar la ruta vacia cuando exista.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (13, 'fecha_prometida', 'campo', 'conversation_initiation_client_data.dynamic_variables.fecha_carga_texto\nconversation_initiation_client_data.dynamic_variables.fecha_compromiso_carga\nconversation_initiation_client_data.dynamic_variables.fecha_cita_carga', NULL, 'fecha_texto', NULL, 12, 'Texto natural (\"el sabado 21 de noviembre a las 7 y 28 de la manana\") -> dd/mm/aaaa HH:mm.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (14, 'mensajes_conten', 'calculado', NULL, NULL, 'ninguna', NULL, 13, 'Se arma recorriendo transcript[] como \"//BOT: ... //USER: ...\".', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (15, 'audio', 'calculado', NULL, NULL, 'ninguna', NULL, 14, 'Ruta propia /eleven-labs/audio/{id}. El MP3 no se almacena.', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (16, 'audio_duracion', 'campo', 'metadata.call_duration_secs', NULL, 'numero', NULL, 15, '', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (17, 'costo', 'campo', 'metadata.cost_fiat', NULL, 'numero', NULL, 16, 'USD. Los creditos estan en metadata.cost.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (18, 'exitosa_segun_ia', 'campo', 'analysis.call_successful', NULL, 'veredicto_ia', 'FALSE', 17, 'Lo determina ElevenLabs: success -> TRUE, cualquier otra cosa -> FALSE.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (19, 'entro_llamada', 'regla', NULL, NULL, 'ninguna', '', 18, 'Se resuelve por call_result / motivo de fin.', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (20, 'razon_finalizacion', 'regla', NULL, NULL, 'ninguna', '', 19, 'El valor debe ser un name exacto de razones_finalizacion.', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (21, 'razon_finalizacion_espanol', 'constante', NULL, NULL, 'ninguna', NULL, 20, 'No se usa al importar.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (22, 'transportista', 'campo', 'conversation_initiation_client_data.dynamic_variables.empresa_transporte\nconversation_initiation_client_data.dynamic_variables.operador_logistico', NULL, 'texto', NULL, 21, 'VAPI lo dejaba vacio; el TRT real lo resuelve el pipeline del lote.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (23, 'ia_result_comments_text', 'campo', 'analysis.transcript_summary', NULL, 'truncar_255', NULL, 22, '', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (24, 'ia_result_delay_reason_desc', 'campo', 'analysis.call_summary_title', NULL, 'truncar_255', NULL, 23, 'ElevenLabs no tiene \"delay reason\": se usa el título corto del análisis (call_summary_title).', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (25, 'analisis_transcripcion', 'calculado', NULL, NULL, 'ninguna', NULL, 24, 'Analizador de transcripcion (buzon / NO HABLA / CUELGA).', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (26, 'analisis_audio', 'constante', NULL, NULL, 'ninguna', NULL, 25, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (27, 'conductor_confirma', 'constante', NULL, NULL, 'ninguna', NULL, 26, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (28, 'buzon_de_voz', 'campo', 'metadata.features_usage.voicemail_detection.used', NULL, 'booleano_0_1', '1', 27, 'ElevenLabs avisa si el agente detecto buzon. Valores: true / false.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (29, 'conductor_contesta_pero_no_habla', 'calculado', NULL, NULL, 'ninguna', NULL, 28, 'Analizador de transcripcion.', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (30, 'conductor_no_escucha', 'constante', NULL, NULL, 'ninguna', NULL, 29, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (31, 'conductor_da_motivos', 'constante', NULL, NULL, 'ninguna', NULL, 30, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (32, 'conductor_mala_senal', 'constante', NULL, NULL, 'ninguna', NULL, 31, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (33, 'confusion_en_llamada', 'constante', NULL, NULL, 'ninguna', NULL, 32, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (34, 'contesta_otra_persona', 'constante', NULL, NULL, 'ninguna', NULL, 33, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (35, 'numero_equivocado', 'constante', NULL, NULL, 'ninguna', NULL, 34, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (36, 'conversacion_fluida', 'constante', NULL, NULL, 'ninguna', NULL, 35, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (37, 'llamada_interesante', 'constante', NULL, NULL, 'ninguna', NULL, 36, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (38, 'ia_se_confunde', 'constante', NULL, NULL, 'ninguna', NULL, 37, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (39, 'ia_no_escucha', 'constante', NULL, NULL, 'ninguna', NULL, 38, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (40, 'ia_cambio_de_datos', 'constante', NULL, NULL, 'ninguna', NULL, 39, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (41, 'ia_error_interpretacion', 'constante', NULL, NULL, 'ninguna', NULL, 40, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (42, 'ia_dice_variable', 'constante', NULL, NULL, 'ninguna', NULL, 41, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (43, 'ia_mala_pronunciacion', 'constante', NULL, NULL, 'ninguna', NULL, 42, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (44, 'ia_cuelga_en_plena_llamada', 'constante', NULL, NULL, 'ninguna', NULL, 43, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (45, 'conductor_cuelga', 'regla', NULL, NULL, 'ninguna', '', 44, 'Se resuelve por el motivo de fin.', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (46, 'conductor_no_contesta', 'regla', NULL, NULL, 'ninguna', '', 45, 'Se resuelve por el motivo de fin.', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (47, 'conductor_conducta_inapropiada', 'constante', NULL, NULL, 'ninguna', NULL, 46, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (48, 'error_tecnico_llamada', 'regla', NULL, NULL, 'ninguna', '', 47, 'Error de configuracion del agente (variables obligatorias faltantes).', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (49, 'error_audio', 'constante', NULL, NULL, 'ninguna', NULL, 48, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (50, 'error_origen', 'regla', NULL, NULL, 'ninguna', '', 49, 'Ojo: DBTmpLlamadas lo sobrescribe con la razon de finalizacion.', 1, '2026-11-20 08:50:32', '2026-11-20 08:50:32');
INSERT INTO `elevenlabs_mapeos_campo` (`id`, `campo_destino`, `tipo_origen`, `ruta_origen`, `valor_constante`, `transformacion`, `valor_por_defecto`, `orden`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (51, 'llamada_exitosa', 'constante', NULL, NULL, 'ninguna', NULL, 50, 'Lo mandaba vacio el flujo de VAPI.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');

-- elevenlabs_mapeos_regla (20 filas)
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (17, 'direccion_outbound', 'metadata.phone_call.direction', 'igual', 'outbound', 'type', 'OUTBOUNDPHONECALL', 1, 'Llamo la IA al conductor.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (18, 'direccion_inbound', 'metadata.phone_call.direction', 'igual', 'inbound', 'type', 'INBOUNDPHONECALL', 1, 'Llamo el conductor a la linea de la IA.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (19, 'error_agente_config', 'metadata.termination_reason', 'contiene', 'Missing required dynamic variables', 'error_tecnico_llamada', '1', 12, 'El agente no pudo arrancar por variables obligatorias faltantes.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (22, 'fin_end_call', 'metadata.termination_reason', 'contiene', 'end_call tool was called', 'razon_finalizacion', 'ASSISTANT-ENDED-CALL', 2, 'La IA decidio cerrar la llamada.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (23, 'fin_remote_party', 'metadata.termination_reason', 'contiene', 'ended by remote party', 'razon_finalizacion', 'CUSTOMER-ENDED-CALL', 3, 'El conductor corto.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (25, 'fin_busy', 'metadata.phone_call.call_result', 'igual', 'busy', 'razon_finalizacion', 'CUSTOMER-BUSY', 6, 'Linea ocupada.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (26, 'fin_no_answer', 'metadata.phone_call.call_result', 'igual', 'no-answer', 'razon_finalizacion', 'CUSTOMER-DID-NOT-ANSWER', 4, 'No contesto.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (27, 'entro_por_answered', 'metadata.phone_call.call_result', 'igual', 'answered', 'entro_llamada', '1', 10, 'La llamada fue contestada.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (30, NULL, 'metadata.termination_reason', 'contiene', 'Missing required dynamic variables', 'error_origen', '3', 13, NULL, 1, '2026-11-20 08:02:47', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (31, NULL, 'metadata.phone_call.call_result', 'contiene', 'max duration', 'razon_finalizacion', 'EXCEEDED-MAX-DURATION', 7, NULL, 1, '2026-11-20 08:13:06', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (32, NULL, 'status', 'igual', 'failed', 'razon_finalizacion', 'TWILIO-FAILED-TO-CONNECT-CALL', 5, NULL, 1, '2026-11-20 08:23:14', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (33, NULL, 'metadata.termination_reason', 'contiene', 'timeout', 'razon_finalizacion', 'EXCEEDED-MAX-DURATION', 8, NULL, 1, '2026-11-20 08:28:38', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (35, NULL, 'metadata.termination_reason', 'contiene', 'worker unavailable', 'razon_finalizacion', 'CALL.IN-PROGRESS.ERROR-VAPIFAULT-WORKER-NOT-AVAILABLE', 9, NULL, 1, '2026-11-20 08:31:14', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (36, NULL, 'metadata.termination_reason', 'contiene', 'ended by remote party', 'conductor_cuelga', '1', 11, NULL, 1, '2026-11-20 08:42:14', '2026-11-20 12:19:51');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (39, 'razon_no_reconocida', 'metadata.termination_reason', 'else', NULL, 'razon_finalizacion', 'UNKNOWN', 998, 'Motivo de fin no reconocido: queda como DESCONOCIDO.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (40, 'razon_vacia', 'metadata.phone_call.call_result', 'igual', 'voicemail', 'entro_llamada', '1', 15, 'Sin motivo de finalizacion.', 1, '2026-11-20 08:50:32', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (41, NULL, 'metadata.phone_call.call_result', 'igual', 'no-answer', 'entro_llamada', '0', 14, NULL, 1, '2026-11-20 10:24:54', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (44, NULL, 'metadata.termination_reason', 'contiene', 'voicemail_detection', 'razon_finalizacion', 'ASSISTANT-ENDED-CALL', 2, NULL, 1, '2026-11-20 10:38:11', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (45, NULL, 'metadata.phone_call.call_result', 'igual', 'answered', 'entro_llamada', '1', 16, NULL, 1, '2026-11-20 10:48:22', '2026-11-20 12:13:59');
INSERT INTO `elevenlabs_mapeos_regla` (`id`, `clave`, `ruta_origen`, `comparacion`, `valor_origen`, `campo_destino`, `valor_destino`, `prioridad`, `descripcion`, `activo`, `created_at`, `updated_at`) VALUES (46, NULL, 'metadata.phone_call.call_result', 'igual', 'busy', 'entro_llamada', '0', 17, NULL, 1, '2026-11-20 10:48:22', '2026-11-20 12:13:59');

-- elevenlabs_opciones (1 filas)
INSERT INTO `elevenlabs_opciones` (`clave`, `valor`, `etiqueta`, `ayuda`, `created_at`, `updated_at`) VALUES ('estados_a_importar', 'done,failed', 'Estados que entran al lote', 'Las llamadas con otro estado no se importan y aparecen como «estado fuera de la politica».', '2026-11-20 11:59:24', '2026-11-20 12:13:59');

ALTER TABLE `elevenlabs_mapeos_campo` AUTO_INCREMENT = 52;
ALTER TABLE `elevenlabs_mapeos_regla` AUTO_INCREMENT = 47;

COMMIT;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- 5) COMPROBACION. Ejecutar despues de cargar el archivo.
--
-- 5.1 Conteos: tienen que coincidir con los del encabezado.
-- ---------------------------------------------------------------------------
SELECT 'agentes' AS tabla, COUNT(*) AS filas FROM elevenlabs_agentes
UNION ALL SELECT 'campos', COUNT(*) FROM elevenlabs_mapeos_campo
UNION ALL SELECT 'reglas', COUNT(*) FROM elevenlabs_mapeos_regla
UNION ALL SELECT 'opciones', COUNT(*) FROM elevenlabs_opciones;

-- ---------------------------------------------------------------------------
-- 5.2 Referencias (COMENTADO: usa tablas que este archivo no trae).
--     Copia y corre estas dos consultas DESPUES de cargar el archivo: tienen
--     que devolver CERO filas. Si devuelven algo, esta configuracion apunta a
--     tipos de llamada o motivos de fin que esa base NO tiene, y esas reglas
--     quedarian inertes sin avisar.
-- ---------------------------------------------------------------------------
-- SELECT 'tipo_llamada_id inexistente' AS problema, r.requerido
-- FROM (SELECT 1 AS requerido UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) r
-- LEFT JOIN tipos_llamada t ON t.id = r.requerido
-- WHERE t.id IS NULL;

-- SELECT 'razon_finalizacion inexistente' AS problema, r.requerido
-- FROM (SELECT 'ASSISTANT-ENDED-CALL' AS requerido UNION ALL SELECT 'CALL.IN-PROGRESS.ERROR-VAPIFAULT-WORKER-NOT-AVAILABLE' UNION ALL SELECT 'CUSTOMER-BUSY' UNION ALL SELECT 'CUSTOMER-DID-NOT-ANSWER' UNION ALL SELECT 'CUSTOMER-ENDED-CALL' UNION ALL SELECT 'EXCEEDED-MAX-DURATION' UNION ALL SELECT 'TWILIO-FAILED-TO-CONNECT-CALL' UNION ALL SELECT 'UNKNOWN') r
-- LEFT JOIN razones_finalizacion z ON z.name = r.requerido
-- WHERE z.name IS NULL;

-- ---------------------------------------------------------------------------

-- ===========================================================================
-- 6) OPCIONAL (comentado a proposito): permisos del modulo (Spatie).
--
-- Sin estos permisos la pantalla no aparece en el menu y las rutas responden
-- 403 aunque la configuracion este cargada. NO vienen activos porque tocan
-- tablas compartidas con el resto de la app (permissions / role_has_permissions).
-- Descomenta el bloque y ajusta la lista de roles si en esa base se llaman
-- distinto a admin / editor.
--
-- Son idempotentes: no duplican el permiso ni la asignacion, y asignan por
-- NOMBRE de rol (los ids cambian entre bases).
-- ---------------------------------------------------------------------------
-- INSERT INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
-- SELECT 'ver eleven labs', 'web', NOW(), NOW()
-- WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'ver eleven labs' AND `guard_name` = 'web');
--
-- INSERT INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
-- SELECT 'escuchar audio eleven labs', 'web', NOW(), NOW()
-- WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'escuchar audio eleven labs' AND `guard_name` = 'web');
--
-- INSERT INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
-- SELECT 'importar eleven labs', 'web', NOW(), NOW()
-- WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'importar eleven labs' AND `guard_name` = 'web');
--
-- INSERT INTO `role_has_permissions` (`permission_id`, `role_id`)
-- SELECT p.`id`, r.`id`
-- FROM `permissions` p
-- INNER JOIN `roles` r ON r.`name` IN ('admin', 'editor')
-- WHERE p.`name` IN ('ver eleven labs', 'escuchar audio eleven labs', 'importar eleven labs')
--   AND p.`guard_name` = 'web'
--   AND NOT EXISTS (
--       SELECT 1 FROM `role_has_permissions` rp
--       WHERE rp.`permission_id` = p.`id` AND rp.`role_id` = r.`id`
--   );
-- ===========================================================================

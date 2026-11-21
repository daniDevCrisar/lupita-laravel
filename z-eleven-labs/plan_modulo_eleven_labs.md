# Plan de trabajo — Módulo Eleven Labs (llamadas)

> Carpeta `z-eleven-labs/` = todos los documentos técnicos de este módulo.
> Fecha de reconocimiento: **2026-11-19** · Zona horaria app/MySQL: **America/Lima (UTC-5)**
> Estado: **propuesta para aprobación** — no se ha escrito código del módulo.

---

## 1. Qué se va a construir (lectura de tu pedido)

Un módulo **de solo lectura** sobre la API de Conversational AI de ElevenLabs, con dos caras:

1. **Vista "main"** — elijo un rango con **fecha y hora**, veo qué llamadas hay en ese rango
   (listado, sin detalle). Un botón **"Ver detalle"** abre un **modal** con la llamada completa
   (transcripción, análisis, variables de negocio, audio).
2. **Botón "Importar llamadas al proyecto"** — toma el rango, pide el detalle de cada llamada,
   lo **traduce al contrato VAPI** (`tmp_lotes_det`) y **genera el lote** que hoy generas subiendo
   un Excel. Sin Excel: el flujo lo gatilla el usuario y termina en la pantalla de lote que ya existe.

Además: menú **"Eleven Labs"** en `layouts/app.blade.php`, columnas **`el_id`** e **`id_plataforma`**
en `llamadas` (con migración lista), y un **endpoint de audio** que sirve el MP3 desde el backend
**sin almacenarlo**.

---

## 2. Lo que verifiqué contra la API real (hechos medidos, no supuestos)

Probé la key del documento contra la API el 2026-11-19. Esto es lo que devuelve **de verdad**:

### 2.1 Volumen real y paginación

| Medición | Resultado |
|---|---|
| Llamadas en 12–19 nov 2026 | **100** en la primera página, `has_more: true` |
| Llamadas el **18 nov** (filtro de un día) | **40**, `has_more: false` |
| Llamadas últimos 7 días (suma por agente) | **90** |
| Tamaño de página | `page_size` **máx. 100**; `1000` → HTTP 422 |
| Paginación | **`next_cursor` (base64) + `has_more`** — *el documento no lo menciona* |

> ⚠️ El documento dice "obtiene una lista paginada" pero no documenta `next_cursor`.
> Con más de 100 llamadas en el rango **hay que encadenar cursores**, si no se pierden llamadas en silencio.

### 2.2 Agentes reales del workspace (11)

| agent_id | Nombre | Llamadas 7d |
|---|---|---|
| `agent_2101m0dx8hw3ehasf49ry4dkke3w` | DespachIA - Camila | 61 |
| `agent_5901m2zvgqjeey1bd39vmjyayybc` | DespachIA - Camila Inbound | 25 |
| `agent_6101m7670pnmfsfbvsyp22e74z6g` | EfletexiaT2 - ChatBot | 2 |
| `agent_6701m71ysee4fyh8p9t3xmgsegax` | DespachIA - Asignación (placa y conductor) | 2 |
| `agent_6201m3zctfb3fh3sjmdbpfmndq37` | Efletexia - Compromiso Carga (ElevenLabs) | 0 |
| `agent_0101m491x66gev18ymvwsrrk4xda` | Efletexia - Time in Plant (ElevenLabs) | 0 |
| `agent_4701m491wqxdf9xrp2wryvz2z9g8` | Efletexia - Time in Download (ElevenLabs) | 0 |
| `agent_1801m492wf8efay8qqx08hsv88py` | Efletexia - Waiting Time (ElevenLabs) | 0 |
| `agent_0401m491xmwzfsnbged5sr4ttnaw` | Efletexia - Waiting Time In Customer (ElevenLabs) | 0 |
| `agent_2001m3zcwkcffep88y92x7xs41gd` | Efletexia - In Route (ElevenLabs) | 0 |
| `agent_0301m50v57r0fj8rmjqmz9bckq16` | Efletexia - Hoja de Vida | 0 |

El endpoint `/v1/convai/agents` **sí funciona con esta key** → el mapa agente → `tipo_llamada`
se puede armar desde `main` sin hardcodear ids.

### 2.3 Estados y motivos de fin (distintos a lo que dice el documento)

- `status`: **`done` 86 / `initiated` 6 / `failed` 8** — el documento decía `done`/`in-progress`;
  **`in-progress` no apareció** y sí `initiated` y `failed`.
- `direction` (existe en el listado, el documento no lo pone): **`outbound` 72 / `inbound` 28**.
- `termination_reason` real:

| valor | n |
|---|---|
| `end_call tool was called.` | 46 |
| `Call ended by remote party` | 36 |
| *(vacío)* | 9 |
| `Missing required dynamic variables in tools: {'shipperid'}` | 5 |
| `voicemail_detection tool was called.` | 4 |

> ⚠️ **Ninguno coincide** con los códigos de VAPI (`customer-ended-call`, …) que alimentan
> `razones_finalizacion`. Hace falta un **mapa nuevo** (`MapaRazones`). Señal extra útil que sí trae
> el detalle: `metadata.phone_call.call_result` (`answered`, `voicemail`, …) y
> `metadata.error.error_type` (`agent_configuration_error`, …).

### 2.4 Detalle: estructura confirmada (3 llamadas reales revisadas)

- `metadata.start_time_unix_secs` en **segundos** (el pipeline VAPI trabaja en **milisegundos**).
- `metadata.phone_call`: `direction`, `external_number`, `agent_number`, `call_sid`, `call_result`, `type` (twilio).
- `metadata.timezone` = `America/Bogota` (mismo offset UTC-5 que Lima).
- `transcript[]`: `role` = `agent` | `user` (minúsculas, VAPI era `BOT`/`USER`),
  `message` **truncado con `...`**, `original_message` **completo**, `time_in_call_secs`, `interrupted`,
  `producing_llm`, más `tool_calls` / `tool_results`.
- `has_audio: true` **incluso** en llamadas `failed` con transcripción vacía.
- `analysis`: `call_successful` (`success`/`failure`), `transcript_summary`, `call_summary_title`,
  `sentiment_analysis`.

### 2.5 `dynamic_variables`: lo bueno y lo que falta

En **outbound** vienen completas y son oro:

```
carrier_name, trip_code, reference, origen, destino, tipo_vehiculo, producto_carga,
fecha_carga_texto, fecha_entrega_texto, telefono, empresa_id, empresas, operador_logistico,
tarifa_base, tarifa_ofrecida, tarifa_maxima, transportista_identificado, es_nuevo, es_recurrente, ...
```

En **inbound** vienen vacías salvo: `telefono`, `system__user_id`, `requesterid`, `personid`,
`negociaciones_activas`, `empresa_id: ""`.

Dos cosas que **no existen** en ElevenLabs y sí existían en VAPI:

| Campo VAPI (que hoy llena `llamadas`) | ¿Homónimo en ElevenLabs? |
|---|---|
| `driver_name` / `nombre_transportista` → `conductor` | ❌ (lo más cercano es `carrier_name`) |
| `vehicle_plate` / `placa` → `llamadas.placa` | ❌ **no viene en ninguna variable** |

> Existe el agente **"DespachIA - Asignación (placa y conductor)"**, lo que sugiere que la placa se
> asigna en otra llamada. → **Decisión D2 y D3**.

Inconsistencia de tipos a normalizar: `transportista_identificado` llega como **boolean `true`**
en outbound y como **string `"true"`** en inbound; `personid`/`shipperid` llegan como **int** o **null**.

### 2.6 Audio

| Prueba | Resultado |
|---|---|
| `GET /conversations/{id}/audio` | HTTP 200, `Content-Type: audio/mpeg`, firma `ID3` |
| Peso llamada de 100 s | **275,948 bytes** (~22 kbps) → ~2.7 KB/s |
| Soporte `Range: bytes=0-1023` | **No** (responde 200 con el archivo completo, nunca 206) |

> Consecuencia: el reproductor `<audio>` **no podrá adelantar/retroceder** por rango.
> Para llamadas de este largo es irrelevante (≈0.3–1.6 MB), pero queda documentado.

### 2.7 Alcance de la key (importante)

| Endpoint | Resultado |
|---|---|
| `/convai/conversations*` | ✅ 200 |
| `/convai/agents`, `/convai/phone-numbers` | ✅ 200 |
| `/user/subscription` (saldo/créditos) | ❌ **401 `missing_permissions: user_read`** |
| `/voices` | ❌ 401 `missing_permissions: voices_read` |

> El módulo **no podrá mostrar créditos restantes** salvo que agregues el permiso `user_read` a la key.
> El flujo de llamadas/transcripción/audio **no necesita** ese permiso.

### 2.8 Costos medidos (respuesta a tu pregunta del punto 6)

| Medición (100 llamadas, 78.6 min) | Valor |
|---|---|
| Créditos consumidos | **90,224** |
| USD | **$16.43** |
| Por llamada | ~902 créditos ≈ **$0.164** |
| Por minuto | ~1,147 créditos ≈ **$0.209** |
| Costo por llamada en tu base VAPI actual (`avg(costo)`, n=42,238) | **$0.048** |

**Respuesta corta: usar la API para leer NO te cuesta créditos.** El detalle trae un bloque
`metadata.charging` que ya viene **cerrado con lo que costó la llamada** (`call_charge`, `llm_charge`,
`platform_charge`); el `GET` de listado, detalle y audio no genera cargo nuevo — leer 100 llamadas
cuesta 0 créditos, y el audio se sirve sin almacenar. *(Verifícalo una vez en el dashboard; esta key
no tiene `user_read` para consultarlo por API.)*

Lo que **sí** cuesta es la llamada, y ya está pagada. Para tu saldo lo relevante es:

- **7 días reales = 90 llamadas ≈ 90k créditos ≈ $15.** Es decir, *una semana de operación se come
  casi el plan Creator completo (100k créditos/mes)*. Ese es el riesgo de saldo, no este módulo.
- ElevenLabs está saliendo **~3.4× más caro por llamada** que VAPI en tus propios datos ($0.164 vs $0.048).
- Costo de desarrollo/pruebas del módulo: despreciable (unas decenas de lecturas = 0 créditos).
- Recomendación: pide que suban el permiso `user_read` a la key y el módulo muestra el saldo en `main`.

---

## 3. Arquitectura propuesta

```
Navegador (main + modal)
   │  fetch JSON
   ▼
ElevenLabsController  ──►  ElevenLabsClient (curl/Http, cursor pagination, retry)
   │                          └─► api.elevenlabs.io
   │
   ├─ TraductorElevenLabs   (detalle EL ──► fila tmp_lotes_det, contrato VAPI idéntico)
   │     ├─ MapaAgentes     (agent_id ──► llamada_tipo_id)
   │     ├─ MapaRazones     (termination_reason + call_result + error ──► razon_finalizacion)
   │     ├─ AnalizadorTranscripcion (port PHP de procesar_transcripcion.js)
   │     └─ FechaTexto      (texto natural ──► timestamp)
   │
   ├─ audio/{id}            (stream passthrough MP3, sin disco)
   │
   └─ importar (por bloques) ──► tmp_lotes + tmp_lotes_det
                                     │
                                     ▼
                          pantalla de lote QUE YA EXISTE
                          (ImportController::procesar_importacion_de_lote)
                          → conductores, TRT, refs, llamadas, mensajes, scoring
```

**Decisiones de arquitectura (mis llamadas, corregibles):**

1. **La traducción corre en PHP, no en JS.** El flujo VAPI traducía en el navegador y generaba Excel.
   Con API eso sobra: se elimina el paso Excel y el paso manual de subida. Consecuencia: hay que
   **portar `procesar_transcripcion.js` (403 líneas) a PHP**, con tests, para que el análisis
   de buzón / "NO HABLA" / "CUELGA" quede idéntico.
2. **La importación reutiliza el pipeline existente al 100%.** Se escriben filas en `tmp_lotes_det`
   (`DBCore::insertBatch`) + cabecera `tmp_lotes`, y desde ahí el botón **"Procesar lote"** que ya
   tienes hace conductores/TRT/refs/llamadas/mensajes/scoring. **No se toca `ImportController` ni
   `DBTmpLlamadas`** salvo un arreglo de 1 línea (ver §7).
3. **Importación por bloques desde el navegador**, no en un solo POST: 100–400 detalles × ~1 s cada
   uno revienta cualquier timeout. El JS pide bloques de 25 con barra de progreso y al final redirige
   a la pantalla del lote. Reanudable si se corta (idempotente por `vapi_id`/`el_id`).
   *Descartado el queue job*: `QUEUE_CONNECTION=database` y no hay garantía de worker corriendo.
4. **`llamadas.vapi_id` sigue siendo la llave universal.** Ver §4.1.
5. **Sin Excel, sin storage de audio.** El link del audio en el lote será
   `route('elevenlabs.audio', $conversation_id)`.

---

## 4. El punto crítico: `el_id` / `id_plataforma` (tu punto 5.7.g)

### 4.1 El conflicto

`llamadas.vapi_id` **no es un dato, es la llave de todo el sistema**:

| Consumidor | Cómo usa `vapi_id` |
|---|---|
| `llamadas.vapi_id` | **PRIMARY KEY** varchar(36), 42,238 filas, todas de 36 chars |
| `mensajes.vapi_id` | PK lógica del chat (índice, `ORDER BY orden`) |
| `scoring_llamadas.vapi_id` | **PRIMARY KEY** + `JOIN llamadas l ON l.vapi_id = s.vapi_id` |
| `DBLlamadas` | filtro `?ids=<vapi_id>`, reportes, `GROUP_CONCAT` |
| `DBConsultarReferencias` | `GROUP_CONCAT(vapi_id)` para abrir llamadas del viaje desde el mapa |
| `Livewire\MensajesLlamada(Textbox)` | `SELECT * FROM mensajes WHERE vapi_id=?` |
| `DBPalabrasLlamadas`, `DBScoringLlamadas` | scoping por `vapi_id` en ~10 consultas |

Si las llamadas de ElevenLabs se guardan **solo** en `el_id` con `vapi_id = NULL`, se rompe en silencio:
el chat de la llamada no abre, el scoring no puntúa, el mapa no abre el viaje y los reportes no la ven.

### 4.2 Propuesta (recomendada)

```
llamadas:
  vapi_id       varchar(64)  ← se escribe SIEMPRE: id de la plataforma (vapi o eleven)
  el_id         varchar(64)  NULL  ← el conversation_id, para comparar entre plataformas
  id_plataforma tinyint      NOT NULL DEFAULT 1   (1 = VAPI, 2 = ElevenLabs)
```

- VAPI: `vapi_id = uuid`, `el_id = NULL`, `id_plataforma = 1` (backfill).
- ElevenLabs: `vapi_id = el_id = conversation_id`, `id_plataforma = 2`.
- **Cero roturas**, y `el_id` te da exactamente lo que pediste (comparar por plataforma).
- Los ids de ElevenLabs miden **33–35 chars** y `vapi_id` es varchar(36) → *entra, pero por 1 char*.
  Se ensancha a 64 en la misma migración (barato y reversible).

*Alternativa (más limpia, más riesgo, para después):* renombrar a un `plataforma_id` único y
migrar los ~15 puntos de uso. No lo recomiendo en esta tanda.

### 4.3 Catálogo de equivalencias agente → tipo_llamada (nuevo, pedido por ti)

Hoy el mapa agente → `tipo_llamada` está **hardcodeado en JS** (`procesar_json.js`, líneas 88-94,
comparando `item.assistantId` contra 5 UUIDs de VAPI). Eso no escala: hay **11 agentes** y vas a
cambiar el mapa.

Propuesta: **tabla propia**, editable después desde una vista.

```
elevenlabs_agentes
  id                 bigint PK
  agent_id           varchar(64)  UNIQUE      ← agent_...
  nombre             varchar(150)             ← sincronizado desde /v1/convai/agents
  tipo_llamada_id    tinyint   NULL            ← 0/NULL = "no entra al lote"
  incluir_en_lote    tinyint(1) DEFAULT 0
  activo             tinyint(1) DEFAULT 1
  timestamps
```

Dominio de `tipo_llamada_id`: **se lee de la tabla `tipos_llamada`**, no de una lista en el código
(la misma tabla que usan `ReportesController`, `DBLlamadas::$tipos_llamada` y los selects de
`lista_trts` / `palabras` / `scoring` / `filtros_busqueda`). Se excluye el id `0` ("Indeterminada",
valor de fallback) y los inactivos.

Contenido actual de la tabla:

| id | código | nombre |
|---|---|---|
| 0 | INDETERMINADA | Indeterminada *(no asignable)* |
| 1 | CONFIRMACION_LLEGADA | Confirmacion |
| 2 | ESPERA_FUERA_PLANTA_CARGA | Fuera de planta para carga |
| 3 | TIEMPO_EN_PLANTA_CARGA | Dentro de planta para carga |
| **4** | **EN_RUTA** | **En ruta** ← desde 2026-11 la IA hace estas llamadas |
| 5 | ESPERA_FUERA_PLANTA_DESCARGA | Fuera de planta para descarga |
| 6 | TIEMPO_EN_PLANTA_DESCARGA | Dentro de planta para descarga |
| `NULL` | — | **no entra al lote** (chats, hoja de vida, agentes de prueba) |

> Corrección: en la primera versión del plan se dijo que no existía tabla catálogo. **Sí existe:**
> se llama `tipos_llamada` (yo había buscado `llamada_tipos`). El tipo 4 ("En ruta") ya estaba
> cargado desde antes; solo faltaba leerlo.

- Se **siembra sincronizando** `/v1/convai/agents` (11 agentes), con `tipo_llamada_id = NULL`
  hasta que los configures. Semilla inicial con tu ejemplo: `tipo 1 → agent_6201m3zctfb3fh3sjmdbpfmndq37`
  (Efletexia - Compromiso Carga).
- La vista de configuración de equivalencias es su **propia etapa** (Etapa 3), pero la tabla nace
  en la Etapa 0 para que `main` ya pueda filtrar por agente sin datos prestados.
- En Ajustes, la tabla es la **única fuente de verdad**: el código no lleva ids de agente dentro.

---

## 5. Mapa de campos: ElevenLabs → contrato `tmp_lotes_det`

Este es el corazón del módulo. El orden y los nombres de columnas están fijados por
`DBColumns::tmpLotesDet()` (el Excel VAPI los llenaba así).

| Columna `tmp_lotes_det` | Origen ElevenLabs | Nota |
|---|---|---|
| `lote_id` | generado | entero, igual que `ExcelTool::generarLoteId()` |
| `vapi_id` | `conversation_id` | se escribe aquí **y** en `el_id` (§4) |
| `type` | `metadata.phone_call.direction` → regla | `outbound` ⇒ `outboundPhoneCall` · `inbound` ⇒ `inboundPhoneCall` (como VAPI) |
| `created_at` | `metadata.start_time_unix_secs` **× 1000** | el pipeline divide por 1000 y luego `FROM_UNIXTIME` |
| `created_at_excel` | *(vacío)* | solo servía para el Excel |
| `llamada_tipo` | `MapaAgentes[agent_id]` | si no hay mapa → `0` (el pipeline auto-clasifica) |
| `ref` | `dynamic_variables.reference` ?? `trip_code` | varchar(10) |
| `origen` | `dynamic_variables.origen` | |
| `destino` | `dynamic_variables.destino` | |
| `telefono` | `dynamic_variables.telefono` ?? `phone_call.external_number` | **sin `+`** (como VAPI) |
| `conductor` | `dynamic_variables.carrier_name` | ⚠️ **vacío en inbound → D2** |
| `placa` | ❌ sin origen · con transformación `alfanumerico` | ⚠️ **D3** |
| `fecha_prometida` | `dynamic_variables.fecha_carga_texto` | ⚠️ **parser nuevo → D4** |
| `mensajes_conten` | `transcript[]` → `//BOT: … //USER: …` | usar **`original_message`** (no truncado) |
| `audio` | `route('elevenlabs.audio', conversation_id)` | link propio |
| `audio_duracion` | `metadata.call_duration_secs` | VAPI lo sacaba de costos |
| `costo` | `metadata.cost_fiat` (USD) | ⚠️ **D7** (créditos vs USD) |
| `exitosa_segun_ia` | `analysis.call_successful` con `veredicto_ia` | ⚠️ **el veredicto lo da ElevenLabs**; solo se adapta el formato porque el pipeline compara `=='TRUE'` |
| `entro_llamada` | `call_result`/`termination_reason` | misma lógica que el JS |
| `razon_finalizacion` | `MapaRazones[...]` | ⚠️ **D11** mapa nuevo |
| `razon_finalizacion_espanol` | traducción | no se usa al importar |
| `transportista` | `dynamic_variables.operador_logistico` | VAPI lo dejaba `''` → **D10** |
| `ia_result_comments_text` | `analysis.transcript_summary` | varchar(255): truncar; descartar `"Summary couldn't be generated…"` |
| `ia_result_delay_reason_desc` | `analysis.data_collection_results` (si existe) | |
| `analisis_transcripcion` | `AnalizadorTranscripcion::sintaxis()` | port del JS |
| `buzon_de_voz` | `metadata.features_usage.voicemail_detection.used` con `texto_booleano` | Lo detecta ElevenLabs; sale como `"true"`/`"false"` |
| `conductor_contesta_pero_no_habla` | port del JS | |
| `conductor_cuelga` | razón = `Call ended by remote party` | |
| `conductor_no_contesta` | razón equivalente (D11) | |
| `error_origen` | `metadata.error.error_type` / razón | |
| resto de etiquetas (26 columnas) | `''` | igual que el JS |

---

## 6. Etapas

Regla de orden (tu corrección): **primero se VE, después se traduce, después se importa.**
Las Etapas 0–2 son **solo visualización**: en `main` no se importa nada todavía.

### Etapa 0 — Cimientos (sin UI nueva) ← *arrancando aquí*

- Migración `add_el_id_and_id_plataforma_to_llamadas`:
  `el_id varchar(64) NULL` (+índice), `id_plataforma tinyint NOT NULL DEFAULT 1` (+índice),
  backfill `id_plataforma = 1`, ensanche `vapi_id` 36 → 64. `down()` reversible.
- Migración `create_elevenlabs_agentes_table` (§4.3): catálogo de equivalencias.
- `config/services.php` → `elevenlabs.key` desde `ELEVENLABS_API_KEY`.
- `.env` (local) y `.env.example` con `ELEVENLABS_API_KEY=`.
- `ElevenLabsPermissionSeeder` (patrón `ScoringPermissionSeeder`, todo `firstOrCreate`):
  `ver eleven labs`, `importar eleven labs`, `escuchar audio eleven labs` → admin + editor.
- Grupo de rutas `eleven-labs` + item de menú **"Eleven Labs"** en `layouts/app.blade.php`.
- **Seguridad:** sacar la key del `.md` que está **staged en git** (hoy `git status` lo muestra como
  `AM`) y rotarla.

**Verificación:** `php artisan migrate` OK · `db:seed --class=ElevenLabsPermissionSeeder` OK ·
`php artisan route:list` muestra `elevenlabs.*` · el menú aparece solo con el permiso.

### Etapa 1 — Vista `main`: data cruda + filtro por asistente (solo mirar)

Objetivo: **ver** qué hay, sin traducir y sin importar.

- `app/ElevenLabs/ElevenLabsClient.php`: `conversaciones($desde,$hasta,$agentId)` con **cursor**,
  `detalle($id)`, `agentes()`, `sincronizarAgentes()`; manejo de 401/404/422/429 y la forma real
  de los errores (`{"detail":{"type","code","message"}}`).
- `ElevenLabsController@main` → vista; `@conversaciones` → JSON del rango.
- `resources/views/elevenlabs/main.blade.php`:
  - Rango **fecha + hora** desde/hasta.
  - **Selector de asistente** (de `elevenlabs_agentes`, con "todos") + botón
    **"Sincronizar asistentes"** que trae los 11 agentes con su nombre real.
  - Tabla con la **data cruda** de la API: `conversation_id`, hora inicio, agente, duración,
    `status`, `direction`, `call_successful`, `termination_reason`, `message_count`,
    `cost` (créditos), `cost_fiat`, `call_summary_title`.
  - **Botón por llamada: "Ver JSON"** → modal con el **JSON completo del detalle**, pretty-printed,
    con botón *copiar*. Sin interpretar nada (es la materia prima).
  - Aviso visible si `has_more` (hay más de 100 en el rango) y el conteo total traído.
  - El botón **"Importar al lote"** queda **visible pero deshabilitado** con la nota
    "Etapa 5" — así el layout no cambia después.

**Verificación:** rango 18-nov 00:00→23:59 = **40 filas** (medido hoy contra la API);
12–19 nov = **100** con aviso de truncamiento; el selector de asistente filtra (Camila 67 /
Camila Inbound 28 / ChatBot 3 / Asignación 2); "Ver JSON" abre un JSON válido y completo.

### Etapa 2 — Audio de la llamada (sin almacenar)

- `@audio` (`GET /eleven-labs/audio/{id}`): **stream passthrough**, `Content-Type: audio/mpeg`,
  **nada en disco**, permiso `escuchar audio eleven labs`.
- Reproductor en el modal de JSON. Documentar que no hay seek (la API no soporta `Range`).
- Aviso cuando `has_audio == false`.

**Verificación:** el MP3 suena · `storage/app` no crece · el link es reutilizable como
`llamadas.audio_link` más adelante.

### Etapa 3 — Modal de equivalencias (qué asistente es qué tipo de llamada) ✅

- **Modal** (no una vista aparte) dentro de `main`, abierto desde el botón **Equivalencias** del
  encabezado: lista los agentes sincronizados con un `select` de tipo, checkbox **Al lote** y
  checkbox **Activo**. Botones **Guardar** y **Sincronizar asistentes**.
- Los tipos del `select` salen de **`tipos_llamada`** (1 Confirmacion · 2 Fuera de planta para carga ·
  3 Dentro de planta para carga · **4 En ruta** · 5 Fuera de planta para descarga ·
  6 Dentro de planta para descarga) más la opción **— Fuera del lote —**. Si mañana agregan un tipo
  a la tabla, aparece solo: no hay lista de tipos en el código.
- Endpoints: `GET /eleven-labs/equivalencias` (catálogo) y `POST /eleven-labs/equivalencias`
  (guardar). El POST exige el permiso **`importar eleven labs`** porque define qué llamadas entran
  al lote; ver el catálogo basta con `ver eleven labs`.
- Al guardar, la columna **Tipo local** de la tabla se repinta en el sitio, sin volver a pedir la API.

**Verificación:** 11 agentes · 6 tipos disponibles, incluido el 4 · guardar `tipo 4 → Efletexia - In
Route` persiste y el listado del 18-nov resuelve 18 llamadas de Camila como tipo 4 · un `agent_id`
inventado no crea filas · un tipo inexistente (9) lo rechaza la validación.

### Etapa 3b — Efecto colateral del tipo 4 en el resto de la app ✅

Al habilitar el tipo 4 apareció un bug latente en la pantalla **Etiquetar**
(`resources/views/lupita/procesar_audio.blade.php`): la descarga masiva de MP3 armaba el nombre con
`$prefijos[$row->llamada_tipo_id]` y `$cont[$row->llamada_tipo_id]++`, y esas listas tenían solo
1,2,3,5,6. Con una llamada tipo 4 el PHP tiraba *"Undefined array key 4"* (visible, porque la app
corre con `APP_DEBUG=true`) y el nombre del MP3 salía sin prefijo.

Se agregó el `4 => 'EnRuta'` y, además, `?? 0` / `?? 'TipoN'` para que **cualquier tipo nuevo** no
rompa la descarga. Verificado compilando todas las vistas (`php artisan view:cache` + `php -l` sobre
el PHP compilado).

### Etapa 4 — Traductor a "shape VAPI" — **necesita D2, D3, D4, D7, D10, D11**

- `AnalizadorTranscripcion.php` — port fiel de `procesar_transcripcion.js`
  (`es_buzon`, `sintaxis`, "NO HABLA", "CUELGA"), con tests contra los umbrales originales
  (`score >= 2`, `score >= 3`).
- `FechaTexto.php` — parser de `"el miércoles 18 de noviembre a las 3 y 53 de la tarde"`.
  ⚠️ El parser JS **no sirve**: exige el año en letras (`"de dos mil veintiseis"`) y hora `HH:MM`.
- `TraductorElevenLabs.php` + `MapaRazones` (el mapa de agentes ya sale de la tabla de Etapa 3).
- `GET /eleven-labs/preview` → filas traducidas **sin escribir nada**, en tabla lado a lado con
  las 52 columnas de `tmp_lotes_det`, para que las revises.
- Fixtures reales en `z-eleven-labs/fixtures/` (inbound, voicemail, failed, outbound normal).

**Verificación:** preview del 18-nov llena todas las columnas · los 4 fixtures traducen sin
warnings · las fechas de texto dan el timestamp correcto.

### Etapa 5 — Importación al lote — **necesita D5**

- `POST /eleven-labs/importar` por bloques de 25: crea `tmp_lotes` en el primer bloque
  (`DBTmpLotes::crear`), inserta con `DBCore::insertBatch('tmp_lotes_det', DBColumns::tmpLotesDet(), …)`,
  y al terminar redirige a `importar.excel.lote`.
- Idempotencia: `tmp_lotes_det.vapi_id` (PK) + chequeo previo contra `llamadas.el_id`.
- JS con barra de progreso y botón de reanudar; sin Excel.
- **D5**: cómo entran `tmp_lotes_ref` / `tmp_lotes_ref_compromiso` (hoy vienen del Excel TRT/TRT_1).

**Verificación:** importar 5 llamadas → `SELECT` en `tmp_lotes_det` correcto → pulsar
"Procesar lote" (código existente) → `llamadas`, `mensajes` y `scoring_llamadas` pobladas,
con `id_plataforma = 2` y cero `sin_conductor` no justificados.

### Etapa 6 — Huecos de negocio (según decisiones)

Conductor en inbound (D2), placa (D3), transportista (D10), estados `initiated`/`failed` (D8),
`es_entrante` (D9), costo créditos vs USD (D7), dedupe entre plataformas.

### Etapa 7 — Cierre

Indicadores de plataforma en la lista de **Llamadas** (badge `el_id`, filtro `id_plataforma`),
documentación final en `z-eleven-labs/`, `pint`, y repaso de los `echo` de `DBTmpLotes::similitud`.

---

## 7. Trampas ya detectadas en el código existente (y cómo las esquivo)

| # | Hallazgo | Impacto | Tratamiento |
|---|---|---|---|
| 1 | `DBTmpLlamadas::guardar_mensajes()` hace `explode(':', $mensaje)` y exige **exactamente 2 partes** | Cualquier turno con `:` **se pierde en silencio** (afecta a VAPI y a ElevenLabs) | Arreglo de 1 línea: `explode(':', $mensaje, 2)` + test con un turno que contenga `:` |
| 2 | `explode('//')` para separar turnos | Un mensaje con `//` parte un turno | En el traductor sustituir `//` dentro del texto del turno |
| 3 | `DBTmpLotes::similitud()` tiene **`echo`** (líneas 216, 228, 235) | Contamina cualquier respuesta JSON durante el procesado del lote | **No** exponer el procesado del lote como AJAX: se queda como página, como hoy |
| 4 | `llamadas.created_at` = `FROM_UNIXTIME(?)`; MySQL aquí es **UTC−5** y app **America/Lima** | Mandar ms donde va s o al revés corre las horas | El traductor entrega **ms** (`secs × 1000`) porque el pipeline divide por 1000 |
| 5 | `sin_conductor` → **la llamada no se importa** (`return false`) | Llamadas inbound sin nombre se pierden | **D2** (llave) |
| 6 | `razones_finalizacion.name` son códigos VAPI; `obtener_id_razon` devuelve `0` si no encuentra | Motivos de ElevenLabs caerían todos en "DESCONOCIDO" | `MapaRazones` explícito + filas nuevas con `origen='ELEVENLABS'` |
| 7 | `exitosa_segun_ia` se compara `=='TRUE'` (mayúsculas) | Enviar `"true"` ⇒ siempre 0 | El traductor emite `'TRUE'`/`'FALSE'` |
| 8 | `page_size` máx 100 y `next_cursor` no documentado | Pérdida silenciosa de llamadas en rangos largos | Cliente con cursor obligatorio + aviso en `main` si `has_more` |
| 9 | `llamadas.vapi_id` varchar(**36**) vs ids de 33–35 | Entra hoy; frágil mañana | Ensanche a 64 en Etapa 0 |
| 10 | `procesar_audio.blade.php` hace `audio.src = url.toLowerCase()` | Un link con mayúsculas o token firmado se rompe | Nuestro link es minúsculas por construcción |
| 11 | Audio sin `Range` | No se puede hacer seek | Aceptado y documentado |
| 12 | La **key vive en un `.md` staged en git** | Fuga de credenciales | Etapa 0: mover a `.env`, rotar la key |

---

## 8. Decisiones que necesito de ti (no bloquean Etapas 0–2)

| # | Decisión | Mi recomendación |
|---|---|---|
| **D1** | Confirmar el esquema `vapi_id` + `el_id` + `id_plataforma` de §4.2 | ✅ sí, es la opción sin roturas |
| **D2** | `conductor` en llamadas **inbound** (28 de 100): `carrier_name` viene vacío → ¿usar `nombre`? ¿buscar por teléfono en `conductores`? ¿descartarlas del lote? | **Resolver por teléfono** y, si no aparece, dejar `carrier_name`/`nombre`; nunca descartar por defecto |
| **D3** | `placa`: ElevenLabs no la envía | Intentar `DBReferencias::verificar_placa($ref)` por `ref`; si no, vacía. **No** bloquear la importación |
| **D4** | `fecha_prometida` = ¿`fecha_carga_texto` o `fecha_entrega_texto`? Confirmar interpretación de `"… a las 3 y 53 de la tarde"` = `15:53`, año = el de la llamada | `fecha_carga_texto` → `fecha_prometida`, año de la llamada |
| **D5** | `tmp_lotes_ref` / `tmp_lotes_ref_compromiso` (TRT y TRT_1) no salen de la API: ¿seguimos subiendo el Excel de TRT junto a la importación, o el lote se crea solo con llamadas? | Mantener **subida opcional del Excel TRT** en la misma pantalla; sin él el lote solo trae llamadas |
| **D6** | Mapa `agent_id` → `llamada_tipo_id` | ✅ **resuelto**: tabla `elevenlabs_agentes` (§4.3) + vista propia en Etapa 3. Semilla `tipo 1 → agent_6201m3zctfb3fh3sjmdbpfmndq37` |
| **D7** | `llamadas.costo`: USD (`cost_fiat`) o créditos | **USD** (igual que VAPI), y créditos en una columna nueva si los quieres ver |
| **D8** | Estados: ¿importar `failed`? ¿saltar `initiated`? | Importar `done` + `failed`; saltar `initiated` (volverá como `done`) |
| **D9** | `inbound` → `es_entrante = 1`? (hoy el pipeline escribe 0 y **nadie lee esa columna**) | Sí, 1. Es gratis y semánticamente correcto |
| **D10** | `transportista`: ¿`operador_logistico` ("LOGISTICA MASTERS") o vacío como VAPI? | Dejarlo vacío; el TRT real lo resuelve el pipeline del lote |
| **D11** | Mapa `termination_reason` + `call_result` + `error` → `razones_finalizacion`: ¿creo filas nuevas `origen='ELEVENLABS'` o reutilizo ids VAPI? | Filas nuevas `origen='ELEVENLABS'` (no ensucia el histórico VAPI) |

Además, dos cosas que **no estaban en el documento** y conviene decidir:
- ¿`main` muestra también llamadas **ya importadas** (marcadas) o las oculta? → recomiendo mostrarlas marcadas.
- ¿Importación **idempotente** (reintentar no duplica)? → recomiendo sí, siempre.

**D2 queda abierta a propósito**: en las Etapas 0–2 no se importa nada, así que no urge. Se necesita
recién antes de la Etapa 5 (importación al lote).

---


1. Todos los documentos técnicos de este módulo viven en **`z-eleven-labs/`**.
2. La key **nunca** en el repo, ni en Blade, ni en JS público: solo `.env` + backend.
3. El audio **no se almacena**: se sirve en streaming.
4. No se genera ni se sube Excel en este flujo.
5. No se toca el pipeline de lote existente salvo el arreglo de 1 línea de §7.1.
6. **Ningún `agent_id` hardcodeado en el código**: las equivalencias viven en `elevenlabs_agentes`.
7. Las Etapas 0–2 son de **visualización**: `main` no escribe en `llamadas` ni en `tmp_lotes_det`.

---

## 10. Siguiente paso

Arrancando por **Etapa 0** (migraciones + config + permisos + menú), luego **Etapa 1**
(`main` con data cruda, filtro por asistente y "Ver JSON" por llamada) y **Etapa 2** (audio).
Ninguna de las tres depende de las decisiones abiertas de §8.

Se necesitan antes de la Etapa 5: **D2** (conductor en inbound) y **D5** (refs TRT).
Se necesitan antes de la Etapa 6: **D3, D7, D8, D9, D10, D11**.

---

## 11. Estado de avance

### Etapas 0, 1 y 2 — hechas y verificadas (2026-11-19)

| Etapa | Estado | Cómo se verificó |
|---|---|---|
| 0 — Cimientos | ✅ | `el_id varchar(64)`, `id_plataforma tinyint unsigned default 1` y `vapi_id` a 64 en `llamadas` (42.238 filas, todas `id_plataforma=1`, `el_id` NULL) · tabla `elevenlabs_agentes` creada · 3 permisos sembrados · `route:list` muestra las 5 rutas `elevenlabs.*` · el menú "Eleven Labs" aparece solo con el permiso |
| 1 — `main` data cruda | ✅ | La vista renderiza (11 asistentes en el selector) · 18-nov en hora local = **32 filas** · 12–19 nov = **116 llamadas en 2 páginas, 116 ids únicos** (el cursor funciona: sin él se quedaban en 100) · filtro por asistente devuelve solo ese agente |
| 2 — Audio | ✅ | `GET /eleven-labs/audio/{id}` devuelve `audio/mpeg` y un MP3 real (firma `ID3`), en streaming · id inexistente → 404 JSON · **nada escrito en disco** |
| 2b — Modal de detalle | ✅ | Modal con 6 pestañas (Resumen · Conversación · Entrada · Análisis · Metadatos y costo · JSON) y reproductor fijo arriba con el estilo de `/lupita/audio` · **262 verificaciones sobre 5 fixtures reales** (`z-eleven-labs/tools/verificar_render_modal.mjs`) |
| 3 — Modal de equivalencias | ✅ | 11 asistentes con select de los **6 tipos de `tipos_llamada`** (incluido el 4 "En ruta"), "Al lote" y "Activo" · guardar persiste · `agent_id` inventado no crea filas · tipo inexistente rechazado · la columna "Tipo local" se repinta sin reconsultar la API |
| 3b — Bug del tipo 4 en Etiquetar | ✅ | `procesar_audio.blade.php` agregaba el nombre del MP3 con listas que solo tenían 1,2,3,5,6: con una llamada tipo 4 tiraba "Undefined array key 4" en pantalla. Se agregó el 4 y un `?? 'TipoN'` para cualquier tipo nuevo |
| 4a — Equivalencias de traducción | ✅ | **51 columnas** clasificadas y configurables desde la pantalla (**15 ruta + 22 constante + 7 regla + 7 código**) · 13 reglas de valor sembradas con `clave` estable (seeder idempotente, sin duplicados ni huérfanas) · pestaña **Probar** que resuelve una llamada real sin escribir nada · rutas del JSON descubiertas dinámicamente · **310 verificaciones del JS + 12 de PHP** |
| 5 — Importación al lote | ✅ | `POST /eleven-labs/importar` por bloques de 25 · crea `tmp_lotes` con **`tipo = 2`** en el primer bloque e inserta en `tmp_lotes_det` con `insertOrIgnore` (idempotente por `vapi_id`) · botón **Previsualizar / importar** con barra de progreso en el modal · al terminar enlaza a la pantalla del lote de siempre · **verificado de punta a punta**: importó, la pantalla del lote cargó y la fila tiene las 51 columnas correctas |
| 4b — Previsualización del lote | ✅ | `TraductorElevenLabs` + `POST /previsualizar` (máx. 25 llamadas) · dice cuáles entran y **por qué** las demás no (5 motivos) + avisos que no bloquean · botón **Previsualizar lote** con resumen y tabla · **no escribe nada** |

### 11.0.0b Equivalencias de traducción (Etapa 4a)

Documento de diseño: `z-eleven-labs/especificacion_mapeo_traduccion.md`.

**Las 7 columnas "calculadas" ya se calculan de verdad** (antes salían vacías en la pestaña Probar):

| Columna calculada | Cómo se resuelve |
|---|---|
| `vapi_id` | el `conversation_id` |
| `audio` | `route('elevenlabs.audio', id)` → link propio del backend |
| `mensajes_conten` | `MensajesTranscripcion` arma `//BOT: … //USER: …` desde `transcript[]` |
| `llamada_tipo` | del catálogo `elevenlabs_agentes` (`null` = fuera del lote) |
| `analisis_transcripcion` | `AnalizadorTranscripcion` (port fiel de `procesar_transcripcion.js`) |
| `conductor_contesta_pero_no_habla` | idem |
| `lote_id` | **único** que no depende de la llamada: se genera al crear el lote |

El port del analizador mantiene umbrales y listas de frases **iguales** al JS
(`score >= 2` para buzón, `>= 3` para sintaxis) para que `llamadas.analisis_transcripcion`
siga coincidiendo con el histórico.

Tres bugs del flujo viejo que se corrigieron al portarlo:

1. `DBTmpLlamadas::guardar_mensajes()` hacía `explode(':', $mensaje)` y exigía **exactamente 2
   partes**: cualquier turno con dos puntos en el texto (`"a las 3:53"`) **se descartaba entero**.
   Ahora usa límite 2 y verificado con una prueba real contra la tabla `mensajes`.
2. El JS cortaba el texto en el segundo `:`; el port conserva todo el turno.
3. El JS **se saltaba el primer turno** (`if (index > 0)`), o sea perdía el saludo. Ahora se conserva:
   el analizador solo mira el texto del conductor, así que el análisis no cambia.

Cuatro pestañas en el modal **Equivalencias**:

| Pestaña | Qué hace |
|---|---|
| **Asistentes** | agente → tipo de llamada, al lote, activo |
| **Campos** | las 51 columnas del contrato: tipo de origen, **rutas candidatas en un `textarea` multilínea (se prueban en orden)**, transformación y valor por defecto. Las 7 calculadas y las 7 de regla salen **bloqueadas con candado**, como documentación |
| **Reglas** | *si la ruta cumple la condición → escribe el valor en la columna*. Se pueden crear y borrar |
| **Probar** | elige una llamada del rango cargado y muestra **qué se escribiría en cada columna**, con el origen del valor (ruta / constante / regla / por defecto / calculado) |

### 11.0.0c "Ruta disparador" dinámica ✅

El `<select>` de **Ruta disparador** ya no es una lista fija: las rutas comunes siguen ahí, pero al
pulsar **Probar** se descubren **todas las rutas hoja del JSON real** de esa llamada, con su valor.

| Pieza | Qué hace |
|---|---|
| `ResolvedorMapeo::aplanar($detalle)` | aplana el JSON en rutas hoja con su valor (`metadata.termination_reason` ⇒ `"Call ended by remote party"`). Poda los subárboles que solo generan ruido (`metadata.charging`, `otlp_traces`, `features_usage.workflow`…) |
| `probarMapeo()` | devuelve `rutas`, así Probar sirve también para descubrir |
| `opcionesRuta()` (JS) | `<select>` con dos `<optgroup>`: **Comunes** (7 fijas) y **Encontradas en el JSON (N)**, cada una con su valor al lado |
| `refrescarSelectoresRutas()` | reconstruye solo las `<option>` **conservando** lo ya elegido; también llena el `<datalist>` de la pestaña Campos |
| Al elegir una ruta | si el campo "Valor" está vacío, **se propone** el valor que traía la llamada para esa ruta |
| **Buscador de rutas** | la lupa está en **las dos pestañas**: en cada fila de tipo *campo* (Campos) y en cada **ruta disparador** (Reglas). Abre un modal con **buscador por ruta o por valor**. En Campos **agrega** la ruta a la cadena (una por línea); en Reglas la deja seleccionada aunque no estuviera en la lista. El desplegable "llamada de ejemplo" se **reconstruye cada vez** que se abre: antes solo se llenaba si estaba vacío, así que abrir la lupa antes de que cargara el listado lo dejaba en "no hay llamadas" para siempre |

Rutas descubiertas por llamada: **95 a 200** (depende del agente), incluidas las 97 de
`dynamic_variables.*`. La ruta que usa `buzon_de_voz`
(`metadata.features_usage.voicemail_detection.used`) **sí aparece**, porque de `features_usage` solo
se poda el árbol de configuración.

Una ruta ya guardada que no esté en ninguna lista **no se pierde**: se agrega al selector.

### 11.0.0d Etapa 4b — previsualización del lote ✅

`TraductorElevenLabs` + `POST /eleven-labs/previsualizar`. Traduce hasta **25 llamadas** por pedido
y dice cuáles entrarían al lote y **por qué** las demás no. **No escribe nada.**

| Motivo | Cuándo |
|---|---|
| `no_marcado_para_lote` | el asistente no tiene *Al lote* |
| `sin_tipo` | el asistente no tiene tipo de llamada asignado |
| `estado_no_final` | `status = initiated` |
| `sin_conductor` | `conductor` vacío → el pipeline descarta la llamada (**D2 pendiente**) |
| `ya_importada` | ya existe en `llamadas` por `vapi_id` o `el_id` |

Además marca **avisos** que no bloquean: sin transcripción, sin referencia de viaje, sin fecha
prometida interpretable.

En la pantalla es el botón **Previsualizar lote**, que abre un **modal** (`modal-xl`, no una tarjeta al
final de la página) con un resumen de badges (traducidas / entrarían / fuera / avisos), el conteo por
motivo, y una tabla por llamada con las columnas clave (`llamada_tipo`, `ref`, `origen → destino`,
`telefono`, `conductor`, `fecha_prometida`, `razon_finalizacion`, `costo`).

Tablas: `elevenlabs_mapeos_campo` y `elevenlabs_mapeos_regla`, sembradas con
`ElevenLabsMapeoSeeder` en el mismo estado en que traducía el flujo de VAPI.

Dos hard requirements que salieron de la implementación:

1. **El valor destino de `razon_finalizacion` se elige de un `select`** con los `name` reales de
   `razones_finalizacion`, porque `DBTmpLlamadas::obtener_id_razon()` compara **exacto y sensible a
   mayúsculas** (`customer-ended-call` → NO encontrado → DESCONOCIDO). El repositorio descarta
   cualquier regla que apunte a un nombre inexistente.
2. **`iconv('UTF-8','ASCII//TRANSLIT')` no sirve en este entorno**: translitera `ñ` como `~n` y `á`
   como `'a` (`mañana` → `ma~nana`), así que el parser de fechas fallaba en todos los textos con
   "de la mañana". `FechaTexto` usa un mapa explícito de tildes.

### 11.0.0 Filtro de asistentes: selección múltiple

El filtro del encabezado es un dropdown con checkboxes (**Todos** / **Ninguno** / **Aplicar filtro**)
y muestra en cada opción el tipo de llamada ya asignado. **Solo lista asistentes activos**: uno
desactivado no se ofrece y, si estaba marcado, se saca de la selección.

`/v1/convai/conversations` **solo acepta un `agent_id` por consulta**, así que el filtro se resuelve
**en el servidor, una consulta por asistente**:

| Marcados | Qué hace el servidor |
|---|---|
| **0** | una consulta al rango completo |
| **1** | una consulta con ese `agent_id` |
| **2 o más** | **una consulta por asistente**, y une los resultados quitando duplicados |

Así lo que llega a la tabla son **exactamente** los asistentes pedidos. Antes, con 2 o más, se pedía
el rango completo y se filtraba en el navegador: si ese filtro fallaba, se colaban llamadas de otros
asistentes en la tabla.

Detalles del comportamiento:

- `?agentes[]=<id>&agentes[]=<id>` es el parámetro (se sigue aceptando el `agent_id` viejo).
- Tope de **20 asistentes** por pedido → 422 si se pasa.
- Los ids se validan con `^[A-Za-z0-9_]{6,64}$`, así que un id manipulado no viaja a la API.
- Si **un** asistente falla (p. ej. su id ya no existe en ElevenLabs), **no rompe el listado**: se
  salta y se informa en el aviso *"La API no devolvió N asistente(s) del filtro"*. Si fallan
  **todos**, se devuelve 502 con el motivo.
- El filtro **se aplica solo** (con 450 ms de retardo al marcar/desmarcar): ya no hace falta pulsar
  *Aplicar filtro* para dejar de ver otros asistentes.
- El resumen dice *"N llamadas de 1 asistente"* / *"de 2 asistentes"* según la selección.
- **Los asistentes desactivados nunca se listan, ni siquiera sin filtro.** El servidor descarta sus
  llamadas y devuelve el conteo en `meta.excluidas_inactivas`; la pantalla avisa *"No se muestran N
  llamada(s) de asistentes desactivados"* — si no, la tabla quedaría vacía sin explicación. Un
  `agent_id` que **no** esté en el catálogo sí se muestra (puede ser uno nuevo sin sincronizar).

### 11.0 Qué muestra el modal de detalle

- **Reproductor** con el mismo look de `/lupita/audio`: `<audio controls>` a todo el ancho,
  fila de botones circulares (`.btn-circular` 40×40) para reiniciar, −5 s, +5 s, velocidad
  (1x/1.25x/1.5x/2x) y descargar, y la lista `list-group-flush` con la duración y la razón de
  finalización. Queda **pegado arriba** (`position: sticky`) para seguir visible en todas las pestañas.
- **Resumen**: asistente, estado, dirección, resultado, inicio, duración, turnos, números, costo,
  y el `transcript_summary` completo.
- **Conversación**: burbujas por turno con el minuto exacto. Usa `original_message` y cae a `message`
  (en esta cuenta `original_message` viene `null` en casi todos los turnos). Los turnos que **solo
  ejecutan herramientas** se pintan como línea discreta con el nombre del tool y si dio error.
- **Entrada**: las variables de negocio en tabla filtrable + la configuración de inicio
  (`conversation_config_override`, `source_info`, …) y las `system__*` en un bloque cerrado aparte.
- **Análisis**: veredicto, score, sentimiento, resumen y los bloques de datos recolectados.
- **Metadatos y costo**: ids, telefonía (`call_sid`, números), tiempos y el desglose real de
  `metadata.charging` (cargo de llamada, LLM, plataforma, TTS, ASR).
- **JSON**: el objeto completo, con botón de copiar.

### 11.0.1 Herramienta de verificación del modal

```bash
php z-eleven-labs/tools/render_main.php                     # compila la vista a HTML
node z-eleven-labs/tools/verificar_render_modal.mjs         # ejecuta los renderizadores
```

Toma el HTML **ya compilado por Blade**, extrae el `<script>` de la vista, lo corre en Node con un
DOM falso y le pasa los 5 fixtures de `z-eleven-labs/fixtures/` (outbound larga, inbound de 36
turnos, buzón de voz, `initiated` y `failed` sin transcripción). Revisa que ningún renderizador deje
`undefined`/`NaN`/`[object Object]`, que las etiquetas `<div>`/`<table>`/`<tr>` cuadren y que la
pestaña JSON devuelva exactamente el fixture original.

> ⚠️ Los fixtures son **llamadas reales** (números de teléfono y nombres de transportistas). Están en
> la carpeta del módulo porque hacen falta para probar el traductor sin gastar llamadas a la API.
> Si el repo se comparte fuera de la empresa, hay que anonimizarlos o sacarlos de git.

**Archivos nuevos**

```
app/ElevenLabs/ElevenLabsClient.php        cliente de la API (cursor, errores, audio)
app/ElevenLabs/ElevenLabsException.php     error normalizado (401/404/422/429)
app/ElevenLabs/AgentesElevenLabs.php       catálogo de equivalencias + tipos
app/Http/Controllers/ElevenLabsController.php
resources/views/elevenlabs/main.blade.php
database/seeders/ElevenLabsPermissionSeeder.php
database/migrations/2026_11_19_140000_add_el_id_and_id_plataforma_to_llamadas.php
database/migrations/2026_11_19_150000_create_elevenlabs_agentes_table.php
z-eleven-labs/fixtures/*.json              5 llamadas reales de muestra
z-eleven-labs/tools/render_main.php        compila la vista para probarla
z-eleven-labs/tools/verificar_render_modal.mjs
```

**Archivos tocados**: `config/services.php` · `.env` y `.env.example` · `routes/web.php` ·
`resources/views/layouts/app.blade.php` (menú) · `app/Providers/AppServiceProvider.php` (bindings) ·
`z-eleven-labs/api_eleven_que_usare.md` (key redactada).

### 11.1 Dos problemas PREEXISTENTES que encontré (no los causó este módulo)

**a) `php artisan migrate` está roto desde abril.** Hay **dos** migraciones
`create_permission_tables`: `2026_04_20_165101_...` y `2026_04_20_165952_...`. La registrada en la
tabla `migrations` es la `165952`; la `165101` quedó sin registrar y, al tener timestamp menor,
`migrate` la intenta correr primero y muere con *"Table 'permissions' already exists"*.
Solo hay **4** migraciones registradas de **24** archivos en disco.
Mientras eso siga así, las migraciones nuevas hay que correrlas con `--path`:
```
php artisan migrate --path=database/migrations/<archivo>.php
```
*Arreglo correcto (decisión tuya, no lo toqué):* borrar el archivo
`2026_04_20_165101_create_permission_tables.php`, porque el que creó las tablas es el `165952`.
Riesgo: en una base nueva, hoy fallarían igual.

**b) Pint no está configurado.** No hay `pint.json` y `vendor/bin/pint --test app` reporta
**105 issues en 117 archivos**, incluidos los módulos recientes (`ScoringPermissionSeeder`,
`ScoringDetalle`). Correr Pint reformatearía medio repo, así que **no lo corrí**: el código nuevo
sigue el estilo de los módulos recientes (que es también el que Pint pide salvo
`concat_space`/`! $x`). Si quieres estandarizar, se hace aparte y en un commit solo de formato.

### 11.1.1 El error «No se pudo importar el bloque» — causa real y arreglo ✅

**Reporte:** al importar el rango `2026-10-01 hasta 2026-10-15 11:00` el modal decía *«No se pudo
importar el bloque.»*

**Causa raíz (medida, no supuesta).** No era ni permiso ni CSRF: el log registra dos intentos del
navegador (`11:39:10` y `11:39:25`) y ambos son el mismo error de MySQL:

```
SQLSTATE[22001]: String data, right truncated:
1406 Data too long for column 'nombre' at row 1
  at app/Database/Tmp/DBTmpLotes.php  (INSERT INTO tmp_lotes …)
```

`tmp_lotes.nombre` es **`varchar(50)` NOT NULL** y el módulo armaba el nombre concatenando el texto
del rango:

```
'ElevenLabs API ' . '2026-10-01 00:00 hasta 2026-10-15 11:00'   → 54 caracteres > 50
```

El insert del lote va **antes** de traducir las llamadas, así que la importación completa moría ahí.
Con el filtro vacío el nombre mide 15 y pasaba (de hecho quedó un lote `'ElevenLabs API '` de una
prueba a las `11:34`), por eso el fallo aparecía **solo al usar el rango de fechas**.

**Comprobación de la causa** (`_tmp_prueba_nombre_lote.php`, dentro de una transacción revertida):

| caso | resultado |
|---|---|
| insert con el nombre crudo (54 chars) | `1406 Data too long for column 'nombre'` — reproduce el error exacto |
| insert con el arreglo | OK, `nombre` = 50 chars |
| rango absurdo de 120 chars (el máximo validado) | OK |

**Arreglo (3 capas).**

1. `ElevenLabsController::importar()` — el nombre se recorta a 50 con `mb_substr` y **el rango
   completo va al `comentario`**, que es `varchar(200)`:
   `nombre` = `ElevenLabs API 2026-10-01 00:00 hasta 2026-10-15 1`,
   `comentario` = `Importado desde la API de ElevenLabs. Rango: 2026-10-01 00:00 hasta 2026-10-15 11:00`.
   Nada de información se pierde: se mueve a la columna que sí tiene espacio.
2. `DBTmpLotes::crear()` — recorta `nombre` a 50 y `comentario` a 200 **para cualquier llamador**.
   Es el helper que también usa la importación por Excel: hoy le pasa nombres cortos, así que no
   cambia nada para ella, pero ya no puede romperse por datos largos.
3. `importar()` — la creación del lote y el insert del detalle van en `try/catch` y devuelven
   `{ok:false, mensaje:"No se pudo crear el lote: <error de la base>"}` con HTTP 500, en vez de una
   página de error HTML. El JS ya lee `mensaje` **o** `message` y aplana los `errors` de Laravel, así
   que la próxima vez el modal dirá la causa real en lugar de un texto genérico.

**Verificación de punta a punta** (`_tmp_prueba_importar.php`, transacción revertida, ids reales):

```
rango_texto : 2026-10-01 00:00 hasta 2026-10-15 11:00 (39 chars)
HTTP        : 200   ok: true   lote_creado: true
lote creado : nombre (50/50)  'ElevenLabs API 2026-10-01 00:00 hasta 2026-10-15 1'
              comentario (84/200) '… Rango: 2026-10-01 00:00 hasta 2026-10-15 11:00'
              tipo=2 usuario_id=1
```

**Limpieza:** se borró el lote huérfano `202611201134113549` (`'ElevenLabs API '`, tipo 2, sin filas
de detalle y sin procesar) que dejó la primera prueba con el rango vacío. No quedan lotes de tipo 2.

**Lección para el módulo:** las columnas del contrato legacy son **estrechas** y el `sql_mode` de este
MySQL incluye `STRICT_TRANS_TABLES`, así que pasarse de largo **no trunca: revienta**. Todo texto que
se arme con datos del usuario (rangos, comentarios, nombres de lote) se recorta explícitamente.

- **Etapa 6 — ✅ hecha el 2026-11-20** (ver §11.2). Los «enlaces de reporte para el tipo 4» **se
  descartan por decisión del usuario**: el lote se mira en la vista que ya existe y no se crean
  vistas nuevas ni se toca su código.

### 11.2 Etapa 6 — política de estados, `es_entrante` y plataforma ✅

**Lo que pidió el usuario al empezar la etapa (2026-11-20):**

> «yo tengo una vista q muestra el lote, solo usare eso por q ya esta programado no crees otro crees
> otro vista ni modifiques su codigo» · «si cambialo para detectar si es saliente o entrante,
> adicional hay tienes q asignar el campo de plataforma si es vapi o eleven»

Así que la etapa queda en tres cosas: **estados configurables**, **`es_entrante`** y
**`id_plataforma`/`el_id`**. Nada de enlaces de reporte ni de pantallas nuevas.

#### a) Qué estados entran al lote (configurable)

Antes estaba fijo en el código: `evaluar()` comparaba `status === 'initiated'` y la descartaba.
Medido en la cuenta: **`done` 86 · `initiated` 6 · `failed` 8**. `failed` ya entraba.

Ahora vive en la tabla nueva **`elevenlabs_opciones`** (`clave` PK → `valor`, `etiqueta`, `ayuda`) y
se edita en la **5.ª pestaña «Política»** del modal de Equivalencias (sin pantallas nuevas, como pidió
el usuario). Se carga y se guarda con los mismos endpoints del modal (`GET /mapeo`,
`POST /mapeo/guardar`), así que no hubo que agregar rutas.

| pieza | qué hace |
|---|---|
| `2026_11_20_160000_create_elevenlabs_opciones_table` | crea la tabla y siembra `estados_a_importar = done,failed` |
| `app/ElevenLabs/OpcionesElevenLabs.php` | `estadosAImportar()`, `estadoEsImportable()`, `paraPantalla()`, `guardar()`; cachea la lectura por petición |
| `TraductorElevenLabs::evaluar()` | `estadoEsImportable($detalle['status'])` en vez de la comparación fija |
| `main.blade.php` | pestaña «Política» + `renderPolitica()` + `leerOpcionesDeTabla()` |

Decisiones de implementación:

- **Valor por defecto `done,failed`**: con `initiated` adentro entrarían conversaciones **sin
  transcripción ni análisis** (la conversación no terminó) y volverían a aparecer después como `done`.
- **`status` vacío entra**: preferimos importar y que se vea en el lote antes que descartar una
  llamada en silencio por un campo que no vino.
- **`guardar()` valida contra la lista blanca** (`done`/`initiated`/`failed`) y **no acepta una lista
  vacía** para una opción múltiple: si no, un guardado sin casillas dejaría el módulo sin importar
  nada. Una fila editada a mano en MySQL tampoco rompe: al leer se filtra por la lista blanca.
- El motivo se sigue llamando **`estado_no_final`** (para no romper el mapa de textos del JS), pero su
  texto ahora dice «El estado de la llamada queda fuera de la política de importación».

#### b) `es_entrante`, `id_plataforma` y `el_id` en el pipeline

`DBTmpLlamadas::importar_llamadas_de_tmp_al_sistema()` tenía `es_entrante = 0; //OUTBOUNDPHONECALL`
fijo, y el INSERT de `guardar_llamada()` no escribía `id_plataforma` (quedaba en su default 1) ni
`el_id`. Ahora:

| columna | de dónde sale |
|---|---|
| `es_entrante` | `tmp_lotes_det.type` = `INBOUNDPHONECALL` → 1; cualquier otra cosa → 0 (compara en mayúsculas) |
| `id_plataforma` | `tmp_lotes.tipo` de la cabecera del lote: **1 = Excel/VAPI · 2 = ElevenLabs** (§12.2) |
| `el_id` | el `conversation_id` cuando la plataforma es 2; **NULL** en VAPI, donde `vapi_id` ya es el id nativo |

Se lee la cabecera **una sola vez por lote** (`plataformaDelLote()` con cache en memoria): el pipeline
llama a esa función una vez por llamada, así que sin cache serían 263 consultas por lote de 263.

**Verificación** (`_tmp_prueba_pipeline.php`, plantilla = fila real de `tmp_lotes_det`, transacción
revertida; 5 de 5 casos):

| `type` | `tmp_lotes.tipo` | `es_entrante` | `id_plataforma` | `el_id` |
|---|---|---|---|---|
| `INBOUNDPHONECALL` | 2 | 1 | 2 | = `vapi_id` |
| `OUTBOUNDPHONECALL` | 2 | 0 | 2 | = `vapi_id` |
| `OUTBOUNDPHONECALL` | 1 | 0 | 1 | NULL |
| `INBOUNDPHONECALL` | 1 | 1 | 1 | NULL |
| `inboundphonecall` (minúsculas) | 2 | 1 | 2 | = `vapi_id` |

Con los datos de hoy (42.238 filas `OUTBOUNDPHONECALL`, `es_entrante` en 0) **no cambia ninguna fila
existente**: solo empiezan a marcarse las entrantes nuevas.

#### c) `type` normalizado a MAYÚSCULAS

El contrato legacy guarda el tipo de llamada pegado y en mayúsculas: las **42.238** filas históricas
dicen `OUTBOUNDPHONECALL`. Las reglas del módulo se sembraron con la grafía de la API
(`outboundPhoneCall`), así que había dos formatos para el mismo dato según el lote. La migración
`2026_11_20_161000_normaliza_valores_type_a_mayusculas` deja las reglas en
`INBOUNDPHONECALL`/`OUTBOUNDPHONECALL`. No toca prioridades ni otras columnas.

#### d) Dos trampas nuevas, para no repetirlas

**1. `<>` es insensible a mayúsculas en este MySQL.** La primera versión del `UPDATE` filtraba con
`valor_destino <> UPPER(valor_destino)` y **no actualizó nada**: las columnas son `utf8mb4_*_ci`, así
que MySQL considera `'outboundPhoneCall' = 'OUTBOUNDPHONECALL'` y la condición daba falso siempre.
La migración informaba `DONE` igual. Se arregló quitando el filtro (`UPPER()` sobre un valor ya en
mayúsculas es idempotente). Para comparar texto con distinta caja hay que usar
`CAST(... AS BINARY)` o `BINARY col = ...`.

**2. El INSERT de `guardar_llamada()` tiene 51 columnas y dos `FROM_UNIXTIME`.** Al insertar
`el_id, id_plataforma` entre `fecha_prometida` y `created_at` el INSERT quedó descuadrado: los dos
`FROM_UNIXTIME(?)` cubren **`fecha_prometida` y `created_at`**, no solo `created_at`, así que las
columnas nuevas quedaron dentro de ese envoltorio y MySQL respondió
`1264 Out of range value for column 'id_plataforma'`. La forma segura de tocar ese INSERT es
**medir** las tres partes —columnas, placeholders y `$params`— y dejarlas iguales:

```
columnas en la lista : 51
placeholders         : 51  (FROM_UNIXTIME: 2)
parametros           : 51   → CUADRA
```

Las columnas nuevas van **antes de `fecha_prometida`**, con sus dos `?` antes de los
`FROM_UNIXTIME`.

#### e) Cómo se comprobó

Las tres primeras pruebas se corrieron con scripts temporales de una sola vez (no quedan en el repo;
todas revierten su transacción, así que no dejan filas):

| prueba | resultado |
|---|---|
| política de estados, fixture por fixture | 11/11 ok (por defecto, `initiated` dentro, validaciones de `guardar()`) |
| pipeline: `es_entrante` + `id_plataforma` + `el_id` | 5/5 casos ok, 0 filas al terminar |
| endpoint `/eleven-labs/importar` con una `initiated` y una `done` | HTTP 200, la `initiated` queda fuera con `estado_no_final` |

Y la verificación que sí queda en el repo:

```
php z-eleven-labs/tools/render_main.php && node z-eleven-labs/tools/verificar_render_modal.mjs
→ OK: 339 verificaciones (antes 327; +12 de la pestaña Política)
```

El arnés verifica que la pestaña pinte **3 casillas**, que `done` y `failed` vengan marcadas, que
`initiated` **no** lo esté, y que `leerOpcionesDeTabla()` arme `{estados_a_importar: [...]}` leyendo
el DOM (no el catálogo del servidor).

#### f) Banderas: **1 / 0 / vacío** (corrección del 2026-11-20)

> «`error_tecnico_llamada` solo puede ser 1, 0 o "" al igual que `conductor_cuelga`» — el usuario.

Tiene razón y yo lo tenía al revés. Las columnas de destino en `llamadas` son **`tinyint(1)`**:
`error_tecnico_llamada`, `conductor_cuelga`, `conductor_no_contesta`, `entro_llamada`,
`buzon_de_voz`, `llamada_exitosa`, `es_entrante`… El dominio válido es **`1` / `0` / vacío**.

Historia del error: la migración `2026_11_20_140000` había convertido `1`/`0` a `true`/`false` para
que el desplegable no mostrara «1 (fuera de la lista)». Pero `true` **no es el dominio**: en el
pipeline las banderas pasan por `etiqueta_valor()` (que tolera `true`/`false` y los traduce), sí,
pero `entro_llamada` se inserta **crudo** (`$llamada->entro_llamada = $item->entro_llamada ? … : 0`),
así que un texto ahí revienta con `Incorrect integer value` por el modo estricto.

Lo que se hizo:

| pieza | cambio |
|---|---|
| `MapeoTraduccion::opcionesValorDestino()` | las 3 banderas pasan de `['true','false']` a `['1','0','']`, con la nota de que son `tinyint(1)` |
| `MapeoTraduccion::guardarReglas()` | **valida el dominio**: una regla con valor fuera de la lista no se guarda (antes solo se validaba `razon_finalizacion`) |
| `2026_11_20_162000_devuelve_banderas_a_1_0` | `conductor_cuelga` estaba en `'true'` → **`'1'`**; `true`/`si` → `1`, `false`/`no` → `0` |
| `main.blade.php` | comentario del selector actualizado (la opción vacía ya se mostraba como «(vacío)») |

**Verificación** (con los fixtures y una transacción revertida): traduciendo llamadas reales,

| fixture | fin de la llamada | `conductor_cuelga` | `error_tecnico_llamada` |
|---|---|---|---|
| `outbound_largo` | `Call ended by remote party` | **`1`** | `''` |
| `failed` | `Missing required dynamic variables…` | `''` | **`1`** |
| `inbound` / `voicemail` / `initiated` | otros | `''` | `''` |

Y `guardarReglas()` **rechaza** una regla con `valor_destino = 'true'` y sí crea la misma con `'1'`.
El arnés pasó a **341** verificaciones: además de lo anterior, ahora comprueba la invariante *«ninguna
regla guarda un valor fuera del dominio de su columna»* y que ninguna columna `tinyint` vuelva a
ofrecer `true`/`false`.

### 11.3 Lo que sigue

- **Etapa 5b — las referencias del lote (D5).** Hoy el lote se crea con las **llamadas** pero sin
  referencias, así que la pantalla de referencias y `DBReferencias::actualizar_rutas()` no tendrán
  datos de viaje. Lo que falta es una subida que lleve el **Excel TRT principal** a `tmp_lotes_ref`
  de un lote ya creado.
  > **Decisión del usuario (2026-11-20):** solo hace falta ese Excel principal → `tmp_lotes_ref`.
  > Las demás hojas quedaron obsoletas y se manejan de otra manera, así que
  > **`tmp_lotes_ref_compromiso` no se va a llenar** en este módulo.
- **Etapa 6 —** estados `initiated`/`failed` como política configurable, `es_entrante`, y los
  enlaces de reporte para el tipo 4.

Ya listo para la importación: el catálogo (asistentes + campos + reglas), la traducción de las 51
columnas verificada contra llamadas reales, el analizador de transcripción, el parser de fechas, y la
previsualización que dice exactamente qué se va a insertar.

---

## 12. Dónde se guarda el id de llamada y el origen del lote

> **DECISIÓN CERRADA (2026-11-19)** — Así lo definió el usuario:
> el **origen de la plataforma va en la cabecera del lote** (`tmp_lotes.tipo`), el **id de la llamada
> en el lote es `vapi_id`**, y en `llamadas` se guarda **lo mismo en `vapi_id`**.
> **No se crean campos adicionales.**

### 12.1 El id de la llamada: **ya está resuelto, no falta ningún campo**

| Dónde | Qué guarda |
|---|---|
| `llamadas.vapi_id` (**PK**) | El `conversation_id`. Es la llave universal: `mensajes`, `scoring_llamadas`, el chat de Etiquetar, el mapa de refs y el filtro `?ids=` |
| `llamadas.el_id` (varchar 64, indexado) | El mismo `conversation_id`, explícito por plataforma |
| `llamadas.id_plataforma` (tinyint, indexado) | **1 = VAPI · 2 = ElevenLabs** |
| `tmp_lotes_det.vapi_id` (**PK**) | El `conversation_id` mientras la llamada vive en el lote (antes de procesarlo) |

Creado en la Etapa 0. El `el_id` se escribe además de `vapi_id` para poder comparar entre
plataformas sin romper las ~15 consultas que usan `vapi_id`.

### 12.2 La cabecera del lote: **no hace falta un campo nuevo, ya hay uno libre**

`tmp_lotes` tiene: `lote_id, usuario_id, tipo, nombre, comentario, procesado, created_at`.

**`tmp_lotes.tipo`** es `tinyint`, hoy vale **1 en los 496 lotes** y **ninguna consulta lo lee**
(lo escribe `DBTmpLotes::crear($lote_id, $nombre, $comentario, $tipo = 1)`). Es exactamente el
hueco para el origen:

| `tipo` | Origen |
|---|---|
| 1 | Excel / export de VAPI (histórico) |
| 2 | **API de ElevenLabs** |

- **Cero migraciones y cero cambios de código**: la importación solo pasa `tipo = 2`.
- El `nombre` del lote ya describe el rango, igual que el Excel ponía el archivo y los conteos:
  `ElevenLabs API 18/11/2026 00:00 → 23:59 (32 llamadas)`.
- `eliminarLote`, `procesado` y la lista de lotes funcionan igual sin tocarlos.

*Alternativa si más adelante se quiere consultar el rango:* agregar `plataforma_id` y
`rango_desde` / `rango_hasta` a `tmp_lotes`. No hace falta ahora; `tipo` + `nombre` cubren el
requisito de saber de dónde vino el lote.

---

## 13. Pasar la configuración a otra base (producción)

**Archivo:** `z-eleven-labs/sql/configuracion_elevenlabs.sql` (273 líneas, 43 KB, UTF-8).

Contiene **solo configuración**: la estructura y las filas de las cuatro tablas de configuración
del módulo, más dos bloques de verificación y un anexo opcional de permisos.

| Tabla | Qué es | Filas |
|---|---|---|
| `elevenlabs_agentes` | qué asistente es qué tipo de llamada, si entra al lote y si está activo | 11 |
| `elevenlabs_mapeos_campo` | columna destino ← ruta del JSON (con cadenas de rutas), transformación, valor por defecto | 51 |
| `elevenlabs_mapeos_regla` | «si ⟨ruta⟩ cumple ⟨condición⟩ → ⟨columna⟩ = ⟨valor⟩», con prioridad | 20 |
| `elevenlabs_opciones` | política del módulo (`estados_a_importar = done,failed`) | 1 |

**No trae** llamadas, lotes, `tmp_lotes`, `tmp_lotes_det`, transcripciones, mensajes, scoring ni
nada con datos personales. Tampoco crea el módulo en sí (eso es código).

**Cómo se usa**

```
mysql -u USUARIO -p TU_BASE < configuracion_elevenlabs.sql
```

Cada corrida hace `DROP TABLE IF EXISTS` + `CREATE TABLE` de esas cuatro tablas y vuelve a insertar
las filas, así que **correrlo dos veces deja el mismo resultado** y no duplica nada.

**Qué comprueba al final**

1. Los conteos por tabla (tienen que coincidir con el encabezado del archivo).
2. Dos consultas comentadas que tienen que devolver **cero filas**: si aparecen `tipo_llamada_id` o
   `razon_finalizacion` que **esa base no tiene**, esas reglas quedarían inertes sin avisar.
   Van comentadas porque usan tablas (`tipos_llamada`, `razones_finalizacion`) que el archivo no trae.

**Anexo opcional (comentado): permisos.** Los tres permisos del módulo
(`ver eleven labs`, `escuchar audio eleven labs`, `importar eleven labs`) y su asignación a los roles
`admin` y `editor`. Van comentados porque tocan tablas compartidas con el resto de la app
(`permissions`, `role_has_permissions`). Son idempotentes y asignan por **nombre** de rol, no por id.
Sin ellos la pantalla no sale en el menú y las rutas responden 403.

**Qué NO alcanza con este archivo.** La configuración sola queda inerte: además hay que tener el
código del módulo. El orden completo para una base nueva es:

| Pieza | Dónde |
|---|---|
| Columnas `llamadas.el_id` / `llamadas.id_plataforma` (y `vapi_id` a 64) | migración `2026_11_19_140000_add_el_id_and_id_plataforma_to_llamadas` |
| Las 4 tablas de configuración | este mismo SQL (o las migraciones que lista el encabezado) |
| Código: controlador, rutas, vista, cliente de la API | `app/ElevenLabs/`, `ElevenLabsController`, `resources/views/elevenlabs/`, `routes/web.php` |
| Menú del panel | `resources/views/layouts/app.blade.php` |
| `ELEVENLABS_API_KEY` en `.env` | nunca en el repo |

**Cómo se verificó este archivo** (no se entrega sin probarlo):

| prueba | resultado |
|---|---|
| cargarlo en una base **vacía** con el cliente `mysql` | exit 0 |
| cargarlo **dos veces seguidas** en la misma base | exit 0 las dos veces, mismos conteos |
| comparar fila por fila con la base de origen (MD5 de todas las filas, columnas ordenadas) | **idéntico** en las 4 tablas |
| acentos y `«»` dentro del archivo | UTF-8 válido, valor idéntico al de la base |
| bloque opcional de permisos corrido dos veces | idempotente: 3 permisos y 6 asignaciones antes y después |
| claves foráneas hacia estas 4 tablas | 0 (se puede recrear sin romper nada) |

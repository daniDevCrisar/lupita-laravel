# Especificación: equivalencias de traducción ElevenLabs → contrato del lote (VAPI)

> Carpeta del módulo: `z-eleven-labs/` · Estado: **propuesta para aprobación** (no hay código de esto todavía)
> Fecha: 2026-11-19

---

## 1. La respuesta corta

**Sí se puede**, pero no al 100 %: de las **51 columnas** de `tmp_lotes_det`, **38 se pueden
configurar** desde una pantalla y **13 no**, porque son cálculos (parsear una fecha en texto natural,
analizar la transcripción para decidir "BUZÓN"/"NO HABLA"/"CUELGA") y no una simple lectura de campo.

Configurar esas 13 sería inventar un lenguaje de expresiones dentro de un modal: mucho código,
difícil de mantener y fácil de romper. Lo honesto es: **tú decides los datos y las rutas; el código
solo hace los cálculos.**

Lo que sí desaparece por completo es tener que **editar código cuando ElevenLabs cambia o agrega una
variable** (`carrier_name`, `trip_code`, `origen`…). Eso se resuelve en la pantalla.

---

## 2. Las 51 columnas, clasificadas

### 2.1 Configurables por **ruta** (un campo del JSON) — 15

Estas son las que más van a cambiar con el tiempo.

| Columna destino | Ruta propuesta en el JSON de ElevenLabs | Transformación |
|---|---|---|
| `created_at` | `metadata.start_time_unix_secs` | **segundos → ms** (el pipeline divide /1000) |
| `ref` | `conversation_initiation_client_data.dynamic_variables.reference` | texto, recorta a 10 |
| `origen` | `…dynamic_variables.origen` | texto |
| `destino` | `…dynamic_variables.destino` | texto |
| `telefono` | `…dynamic_variables.telefono` | **quita `+` y espacios** |
| `conductor` | `…dynamic_variables.carrier_name` | mayúsculas |
| `placa` | *(sin ruta hoy — ver §5)* | **`alfanumerico`**: solo letras y números, en MAYÚSCULAS |
| `fecha_prometida` | `…dynamic_variables.fecha_carga_texto` | **fecha en texto natural** |
| `audio_duracion` | `metadata.call_duration_secs` | número |
| `costo` | `metadata.cost_fiat` | número |
| `transportista` | `…dynamic_variables.operador_logistico` | — |
| `ia_result_comments_text` | `analysis.transcript_summary` | recorta a 255 |
| `ia_result_delay_reason_desc` | `analysis.call_summary_title` | recorta a 255 |
| `exitosa_segun_ia` | `analysis.call_successful` | **`veredicto_ia`** (`success` → `TRUE`, resto → `FALSE`) |
| `buzon_de_voz` | `metadata.features_usage.voicemail_detection.used` | **`booleano_0_1`** (`1` / `0`), defecto `1` |

> `ia_result_delay_reason_desc` **no tiene equivalente real**: VAPI la llenaba desde
> `analysis.structuredData`, y `data_collection_results` viene vacío en todos los agentes. Se usa
> `analysis.call_summary_title`, que es el título corto del análisis ("Voicemail Detected",
> "Logistics Unit Availability"): es el dato corto más parecido, aunque **no es un motivo de demora**.
> Puede venir `null` (llamadas fallidas) y entonces queda vacío.

> `exitosa_segun_ia` es un **campo, no una regla**: el veredicto lo determina ElevenLabs
> (`analysis.call_successful` = `success` / `failure` / `null`). Solo se adapta el **formato**, porque
> `DBTmpLlamadas` compara exacto: `$item->exitosa_segun_ia == 'TRUE' ? 1 : 0`. Si se le pasa
> `success` tal cual, guarda **0** para todas las llamadas.
>
> `buzon_de_voz` también es campo: ElevenLabs avisa si el agente detectó buzón en
> `features_usage.voicemail_detection.used` (`true` en la llamada de buzón, `false` en el resto).
> La transformación es **`booleano_0_1`**, o sea que el valor sale como `1` / `0` —el mismo dominio
> que las demás banderas— porque el destino final es una columna `tinyint(1)` en `llamadas`.
> **Ojo:** `DBTmpLlamadas::etiqueta_valor()` se tuvo que corregir, porque en PHP el string `"false"`
> es **verdadero** y antes se guardaba como 1.

Cada fila se configura con **rutas candidatas**, **transformación**, **valor por defecto** y **activo**.

### 2.1.1 Varias rutas candidatas por campo (caída en cadena)

El campo **no guarda una sola ruta: guarda una lista, una por línea**, y se prueban **en orden**.
La primera que existe, no viene vacía y pasa la transformación, gana. Las que no existen en el JSON
se saltan solas.

```
conversation_initiation_client_data.dynamic_variables.reference
conversation_initiation_client_data.dynamic_variables.trip_code
conversation_initiation_client_data.dynamic_variables.referencia
```

**Por qué esto reemplaza la configuración por asistente:** si un asistente nombra la variable
`carrier_name` y otro `nombre_transportista`, la misma cadena cubre a los dos porque la ruta que no
existe se descarta. Sólo haría falta configuración por asistente si dos asistentes usaran **la misma
ruta con significado distinto**, cosa que hoy no pasa.

Cadenas sembradas (se mezclan los nombres de ElevenLabs con los de VAPI en `procesar_json.js`):

| Campo | Candidatas en orden |
|---|---|
| `ref` | `reference` → `trip_code` → `referencia` → `ref` |
| `origen` | `origen` → `origen_nombre` → `desde` |
| `destino` | `destino` → `destino_nombre` → `hacia` |
| `telefono` | `telefono` → `celular` → `telefono_conductor` → `metadata.phone_call.external_number` |
| `conductor` | `carrier_name` → `nombre_transportista` → `driver_name` → `conductor` |
| `placa` | `placa` → `vehicle_plate` → `placa_vehiculo` |
| `fecha_prometida` | `fecha_carga_texto` → `fecha_compromiso_carga` → `fecha_cita_carga` |

> `conductor` **no** incluye `nombre`: en inbound esa variable trae la empresa
> ("LOGISTICA MASTERS SAS"), no el transportista.

> La cadena también cubre **valores que no se pueden interpretar**: si `fecha_texto` no logra
> convertir el texto, se pasa a la siguiente candidata en vez de guardar el texto crudo.

### 2.1.2 Transformación `alfanumerico` (placas)

Deja **solo A-Z y 0-9, en mayúsculas**. Quita acentos, comas, guiones, espacios y puntos.

| Entra | Sale |
|---|---|
| `A,B,C,1,2,3` | `ABC123` |
| `abc-123` | `ABC123` |
| `ÁB C.123` | `ABC123` |
| `ÑANDÚ 45` | `NANDU45` |
| `---` | *(vacío → sigue con la siguiente candidata o usa el valor por defecto)* |

Por qué: el flujo de VAPI solo hacía `replaceAll(',', '')`, así que `A,B,C,1,2,3` quedaba con
comas y `abc-123` en minúsculas con guion. Comparar eso contra `DBReferencias::verificar_placa()`
o contra la placa del viaje fallaba.

> No se usa `iconv('//TRANSLIT')` para quitar acentos: en este entorno convierte `ñ` en `~n`
> (`mañana` → `ma~nana`). Se usa un mapa explícito.

En la pestaña Campos el campo de ruta es un **`textarea` multilínea** (una ruta por línea) y el
buscador de la lupa **agrega** la ruta al final de la lista en vez de reemplazarla. Las líneas que
empiezan con `#` son comentarios.

La lupa también está en la **Ruta disparador** de la pestaña Reglas: ahí la ruta elegida se deja
seleccionada en el desplegable aunque no estuviera entre las comunes.

### 2.2 Configurables por **constante** — 22

Hoy el flujo de VAPI las manda vacías. Si alguna debe llevar un valor fijo, se pone aquí.

`created_at_excel` · `razon_finalizacion_espanol` · `analisis_audio` · `conductor_confirma` ·
`conductor_no_escucha` · `conductor_da_motivos` · `conductor_mala_senal` · `confusion_en_llamada` ·
`contesta_otra_persona` · `numero_equivocado` · `conversacion_fluida` · `llamada_interesante` ·
`ia_se_confunde` · `ia_no_escucha` · `ia_cambio_de_datos` · `ia_error_interpretacion` ·
`ia_dice_variable` · `ia_mala_pronunciacion` · `ia_cuelga_en_plena_llamada` ·
`conductor_conducta_inapropiada` · `error_audio` · `llamada_exitosa`

### 2.3 Configurables por **regla de valor** (Pieza B) — 7

`type` · `razon_finalizacion` · `entro_llamada` · `error_tecnico_llamada` ·
`conductor_cuelga` · `conductor_no_contesta` · `error_origen`

> `type` está aquí y no en §2.1 porque no es una lectura directa: VAPI usaba
> `outboundPhoneCall` / `inboundPhoneCall` y ElevenLabs parte ese dato en
> `metadata.phone_call.direction` con `outbound` / `inbound`. Es un renombre, o sea
> dos reglas. En el detalle **no existe** un `direction` de nivel raíz (sí en el listado).

### 2.4 Calculadas por código (se muestran bloqueadas, con su explicación) — 7

| Columna | Por qué no es configurable |
|---|---|
| `lote_id` | Se genera al crear el lote |
| `vapi_id` | Es el `conversation_id`; por diseño va también ahí (§4.2 del plan) |
| `llamada_tipo` | Sale del catálogo de asistentes (pestaña **Asistentes**) |
| `mensajes_conten` | Se arma recorriendo `transcript[]` con los separadores del pipeline |
| `audio` | Es la ruta propia `route('elevenlabs.audio', id)`; el MP3 no se almacena |
| `analisis_transcripcion` | Analizador de transcripción (port de `procesar_transcripcion.js`) |
| `conductor_contesta_pero_no_habla` | Analizador de transcripción |

> Reparto final de las **51** columnas: **15 ruta + 22 constante + 7 regla + 7 código**.
> El **analizador de transcripción** y el **parser de fechas en texto natural** son las dos únicas
> piezas de lógica de negocio que quedan en código.

---

## 3. El mecanismo: dos piezas independientes

### 3.1 Pieza A — campo ← ruta (1:1)

Para las columnas cuyo valor es simplemente un dato del JSON:

```
ruta  →  transformación  →  valor por defecto
```

`ref` ← `…dynamic_variables.reference`, `costo` ← `metadata.cost_fiat`, etc.

### 3.2 Pieza B — reglas disparador → efecto

Hay columnas cuyo valor **no es un dato sino una decisión**: el motivo de fin, si la llamada entró,
si fue exitosa para la IA, si hubo buzón, si hubo error técnico. Esas se resuelven con reglas del tipo
*"si `<ruta>` cumple `<condición>`, entonces escribe `<valor>` en `<columna>`"*.

```
si metadata.termination_reason CONTIENE "voicemail_detection"  →  buzon_de_voz       = 1
si metadata.termination_reason CONTIENE "voicemail_detection"  →  razon_finalizacion = CUSTOMER-DID-NOT-ANSWER
si metadata.termination_reason CONTIENE "Missing required dynamic variables" → error_tecnico_llamada = 1
si metadata.termination_reason IGUAL "Call ended by remote party"           → razon_finalizacion = CUSTOMER-ENDED-CALL
si metadata.termination_reason VACÍO                                        → razon_finalizacion = UNKNOWN
si metadata.phone_call.call_result IGUAL "busy"                             → razon_finalizacion = CUSTOMER-BUSY
si analysis.call_successful IGUAL "success"                                 → exitosa_segun_ia  = TRUE
```

Una misma condición puede escribir **varias** columnas (buzón pone `buzon_de_voz = 1` **y** la razón).
Para la misma columna gana la **primera regla que cumple** según su prioridad, así lo específico va
antes que lo genérico.

**Por qué tu respuesta obligó a esto:** pediste que el error del agente quede como
`error_tecnico_llamada = 1` y el buzón como `buzon_voz = 1`. Eso no es "traducir un valor a otro",
es *encender una bandera*; el modelo de reglas lo expresa directamente y no hay que inventar filas
nuevas en `razones_finalizacion`.

### 3.3 Columnas que se mueven a la Pieza B

| Columna | Antes (v1 de esta especificación) | Ahora |
|---|---|---|
| `type` | ruta `conversation_product` | **regla** (direction `outbound`/`inbound` → `OUTBOUNDPHONECALL`/`INBOUNDPHONECALL`) |
| `razon_finalizacion` | mapa de valores | **regla** |
| `exitosa_segun_ia` | ruta + transformación | **sigue siendo campo** (§2.1), con la transformación `veredicto_ia`: el veredicto es de ElevenLabs, solo se adapta el formato |
| `entro_llamada` | derivado en código | **regla** (`1` / `0` / vacío) |
| `buzon_de_voz` | calculado (analizador) | **campo** (`features_usage.voicemail_detection.used`) con `booleano_0_1` → `1`/`0` |
| `error_tecnico_llamada` | constante | **regla** |
| `conductor_cuelga` | derivado en código | **regla** |
| `conductor_no_contesta` | derivado en código | **regla** |
| `error_origen` | derivado en código | **regla** |

> ⚠️ `error_origen` está limitado a `-1, 0, 1, 2, 3` y **`DBTmpLlamadas` lo sobrescribe** según la
> razón de finalización (líneas 60-66). Una regla sobre él es informativa: casi siempre perderá.

Quedan **verdaderamente calculadas por código** (no aparecen editables): `lote_id`, `vapi_id`,
`llamada_tipo`, `mensajes_conten`, `audio`, `analisis_transcripcion`,
`conductor_contesta_pero_no_habla`.

### 3.4 Advertencia dura: el nombre de la razón es exacto y en mayúsculas

`DBTmpLlamadas::obtener_id_razon()` usa `BuscarEnArray::cualquiera_std($valor, 'name', …)`, que es una
**comparación exacta y sensible a mayúsculas** contra `razones_finalizacion.name`. Comprobado:

| Valor enviado | Resultado |
|---|---|
| `customer-ended-call` | **NO ENCONTRADO → 0 (DESCONOCIDO)** |
| `CUSTOMER-ENDED-CALL` | id = 2 ✅ |
| `Call ended by remote party` | **NO ENCONTRADO → 0** |

Por eso, en la pantalla, el valor destino de `razon_finalizacion` se elige de un **`select` con los
`name` reales de la tabla**, nunca texto libre. (Que el flujo de VAPI haya funcionado se debe a que
el Excel normalizaba el valor a mayúsculas de camino.)

---

## 4. Las dos tablas nuevas

### 4.1 `elevenlabs_mapeos_campo` — una fila por columna destino

| Columna | Tipo | Para qué |
|---|---|---|
| `id` | bigint PK | |
| `campo_destino` | varchar(64) **UNIQUE** | columna de `tmp_lotes_det` |
| `tipo_origen` | enum `campo`,`constante`,`calculado` | cómo se obtiene el valor |
| `ruta_origen` | varchar(200) NULL | ruta con puntos al JSON |
| `valor_constante` | varchar(255) NULL | si `tipo_origen = constante` |
| `transformacion` | varchar(30) DEFAULT `ninguna` | de la lista cerrada |
| `valor_por_defecto` | varchar(255) NULL | si la ruta no existe o viene vacía |
| `descripcion` | varchar(255) NULL | texto que explica la fila en la pantalla |
| `activo` | tinyint(1) DEFAULT 1 | |
| timestamps | | |

Se siembra con las 51 filas en su estado actual (`ElevenLabsMapeoSeeder`), así la pantalla abre con
lo que hoy hace el código, no vacía.

### 4.2 `elevenlabs_mapeos_regla` — condiciones disparador → efecto

| Columna | Tipo | Para qué |
|---|---|---|
| `id` | bigint PK | |
| `ruta_origen` | varchar(200) | ruta del valor disparador (`metadata.termination_reason`, `metadata.phone_call.call_result`, `analysis.call_successful`, `metadata.error.error_type`) |
| `comparacion` | enum `igual`,`contiene`,`empieza_por`,`vacio`,`no_vacio` | cómo se compara |
| `valor_origen` | varchar(255) NULL | con qué se compara (NULL en `vacio`/`no_vacio`) |
| `campo_destino` | varchar(64) | columna que se escribe |
| `valor_destino` | varchar(255) | valor a escribir |
| `prioridad` | int DEFAULT 100 | menor = se evalúa antes |
| `descripcion` | varchar(255) NULL | explicación en la pantalla |
| `activo` | tinyint(1) DEFAULT 1 | |
| timestamps | | |

Reglas iniciales propuestas (todas verificables contra los 5 fixtures):

| Prioridad | Ruta | Comparación | Valor | → Columna | = Valor |
|---|---|---|---|---|---|
| 5 | `metadata.phone_call.direction` | igual | `outbound` | `type` | `outboundPhoneCall` |
| 6 | `metadata.phone_call.direction` | igual | `inbound` | `type` | `inboundPhoneCall` |
| 10 | `metadata.termination_reason` | contiene | `Missing required dynamic variables` | `error_tecnico_llamada` | `1` |
| 11 | `metadata.termination_reason` | contiene | `Missing required dynamic variables` | `razon_finalizacion` | `UNKNOWN` |
| 20 | `metadata.termination_reason` | contiene | `voicemail_detection` | `buzon_de_voz` | `1` |
| 21 | `metadata.termination_reason` | contiene | `voicemail_detection` | `razon_finalizacion` | `CUSTOMER-DID-NOT-ANSWER` |
| 30 | `metadata.termination_reason` | igual | `end_call tool was called.` | `razon_finalizacion` | `ASSISTANT-ENDED-CALL` |
| 40 | `metadata.termination_reason` | igual | `Call ended by remote party` | `razon_finalizacion` | `CUSTOMER-ENDED-CALL` |
| 50 | `metadata.phone_call.call_result` | igual | `voicemail` | `buzon_de_voz` | `1` |
| 60 | `metadata.phone_call.call_result` | igual | `busy` | `razon_finalizacion` | `CUSTOMER-BUSY` |
| 70 | `metadata.phone_call.call_result` | igual | `no-answer` | `razon_finalizacion` | `CUSTOMER-DID-NOT-ANSWER` |
| 80 | `metadata.phone_call.call_result` | igual | `answered` | `entro_llamada` | `1` |
| 999 | `metadata.termination_reason` | vacio | — | `razon_finalizacion` | `UNKNOWN` |

Cada regla sembrada lleva una **`clave` estable** (`direccion_outbound`, `fin_remote_party`, …).
Es lo que hace idempotente al seeder: casar por la clave natural (ruta + comparación + valor +
columna) insertaba un duplicado en cuanto cambiaba uno de esos cuatro valores. Las reglas que crea
el usuario tienen `clave = NULL`.

Comparaciones disponibles (enum de la tabla `elevenlabs_mapeos_regla`):

| Comparación | Qué hace |
|---|---|
| `igual` | texto exacto (sensible a mayúsculas), recortando espacios |
| `diferente` | lo contrario de `igual` |
| `contiene` | el texto contiene el valor (sin distinguir mayúsculas) |
| `empieza_por` | el texto empieza por el valor (sin distinguir mayúsculas) |
| `vacio` | el valor está vacío — no compara contra nada, el campo "Valor" se deshabilita |
| `no_vacio` | el valor no está vacío — idem |
| `else` | **en otro caso**: cumple siempre. No compara ninguna ruta; su efecto depende solo de la **prioridad** |

> `comparacion` es un **ENUM** en MySQL: agregar una opción en el código no basta, hace falta
> `ALTER TABLE ... MODIFY` (si no, el insert falla con *"Data truncated for column"*).

> **`else` es una red de seguridad, no un `if`.** Como para cada columna gana la primera regla que
> cumple (por prioridad), un `else` con prioridad **menor** que otra regla de la misma columna la
> anula. Ponlo siempre al final (prioridad alta). La pantalla lo marca con el borde ámbar en el
> campo de prioridad y deshabilita la ruta.

**"(fuera de la lista, se conserva)"**: la pantalla agrega esa marca cuando el valor guardado no
está entre las opciones permitidas de la columna, para **no borrarlo** al guardar. Es una señal
para revisarlo, no un error.

## 4.5 Dominio de valores por columna de regla

> **Corrección del 2026-11-20 (la definió el usuario):** las banderas son `tinyint(1)` en `llamadas`,
> así que su valor es **`1` / `0` / vacío**, no `true` / `false`. La versión anterior de esta tabla
> decía `true` / `false` y la migración `2026_11_20_140000` llegó a convertir `1`→`true`; ambas cosas
> se revirtieron con `2026_11_20_162000_devuelve_banderas_a_1_0`. `etiqueta_valor()` sigue aceptando
> `true`/`false` por si aparece un lote viejo, pero **no es el dominio que se ofrece ni se guarda**.

| Columna | Valores permitidos |
|---|---|
| `type` | texto libre (`OUTBOUNDPHONECALL` / `INBOUNDPHONECALL`) |
| `razon_finalizacion` | nombre exacto de `razones_finalizacion` (se elige de la lista) |
| `entro_llamada` | `1` / `0` / vacío (va crudo a un `tinyint`) |
| `error_origen` | `-1` / `0` / `1` / `2` / `3` |
| `error_tecnico_llamada`, `conductor_cuelga`, `conductor_no_contesta` | `1` / `0` / vacío (`tinyint(1)` en `llamadas`) |

Los dominios de tipo lista **se validan al guardar** (`MapeoTraduccion::guardarReglas()`): una regla
con un valor fuera de la lista **no se guarda**, en vez de reventar después contra la columna
numérica del pipeline con `Incorrect integer value`.

Además, el repositorio **solo acepta reglas sobre columnas de tipo `regla`**: una regla sobre una
columna que se lee por campo o constante queda inerte y solo confunde en la pantalla. (Así se
detectó que `exitosa_segun_ia` había quedado con dos reglas huérfanas al pasar a campo.)

Sin filas nuevas en `razones_finalizacion`: el error del agente se expresa como
`error_tecnico_llamada = 1` y el buzón como `buzon_de_voz = 1`, tal como pediste.

### 4.3 ¿Qué pasa si NINGUNA condición se cumple?

Cada columna de tipo `regla` arranca con su `valor_por_defecto` (vacío en la semilla) y queda en
estado `sin_regla` si ninguna condición matchea. Para `razon_finalizacion` hay **dos redes de
seguridad** que cierran el caso:

| Prioridad | Condición | Efecto |
|---|---|---|
| 998 | `metadata.termination_reason` **no está vacío** | `razon_finalizacion = UNKNOWN` |
| 999 | `metadata.termination_reason` **está vacío** | `razon_finalizacion = UNKNOWN` |

Así, un motivo nuevo que ElevenLabs invente mañana no deja la columna en blanco: cae en `UNKNOWN`
(= razón 0, "DESCONOCIDO"), igual que hacía el pipeline al no encontrar el nombre. El resto de
columnas de regla quedan vacías y el pipeline las lee como 0, sin romperse:

| Columna | Vacío se interpreta como |
|---|---|
| `razon_finalizacion` | `obtener_id_razon('')` → 0 (DESCONOCIDO) |
| `entro_llamada` | `0` |
| `error_tecnico_llamada`, `conductor_cuelga`, `conductor_no_contesta` | `etiqueta_valor('')` → `0` |
| `error_origen` | `validar_error_origen('')` → `0` |
| `type` | no lo usa el pipeline |

### 4.4 ¿Para qué sirve `activo` en la pestaña Campos?

Es un **interruptor para apagar una columna sin borrar su configuración**, no un requisito para que
se llene: las 51 filas vienen con `activo = 1` y se llenan igual.

- Solo tiene efecto en las filas **campo** y **constante**: con `activo = 0` la columna se escribe
  **vacía**, aunque su ruta siga guardada (útil si ElevenLabs cambia una variable y se quiere dejar
  de leerla sin perder cómo estaba configurada).
- En las filas **regla** y **calculado** no hace nada (cada regla tiene su propio `activo`), así que
  **la casilla ya no se muestra** en esas filas para no confundir.

> ⚠️ **Cuidado con volver a sembrar.** `ElevenLabsMapeoSeeder` casa las reglas sembradas por su
> `clave` y **sobrescribe sus valores**: si editaste a mano una regla que vino de la semilla
> (prioridad, condición, valor), una nueva corrida del seeder la revierte. Las reglas que creas tú
> (`clave = NULL`) no se tocan.

---

## 5. Lo que NO se puede, aunque se configure

| Cosa | Por qué |
|---|---|
| **`placa`** | ElevenLabs **no la envía**. Revisé todas las rutas hoja del JSON real: existe `tipo_vehiculo` ("TRACTOMULA") pero ninguna placa. Queda la fila configurable para el día que la agreguen |
| **`ia_result_delay_reason_desc`** | No hay un "motivo de demora" en ElevenLabs (`data_collection_results` viene vacío). Se usa `analysis.call_summary_title` como aproximación: es un título corto, **no** un motivo de demora, y viene `null` en llamadas fallidas |
| **Parser de fechas** | `"el miércoles 18 de noviembre a las 3 y 53 de la tarde"` → fecha: es código. Lo configurable es **qué campo** se parsea |
| **Analizador de transcripción** | "BUZÓN" / "NO HABLA" / "CUELGA" salen de puntuar las palabras del conductor: es código |
| **Separadores de `mensajes_conten`** | El pipeline espera `//BOT: … //USER: …` y `guardar_mensajes` parte por `:`. Si se hace configurable se puede romper la importación |

---

## 6. La pantalla

**Una sola entrada**: el botón **Equivalencias** que ya existe, con **5 pestañas**:

| Pestaña | Contenido | Estado |
|---|---|---|
| **Asistentes** | agente → tipo de llamada, al lote, activo | ✅ ya hecho |
| **Campos** | las 14 con ruta + las 22 constantes, con transformación y valor por defecto; las 7 de código salen bloqueadas con su explicación | ✅ |
| **Reglas** | condiciones disparador → efecto (motivo de fin, resultado del marcado, éxito de la IA, banderas) | ✅ |
| **Política** | qué estados de llamada entran al lote (`elevenlabs_opciones`, Etapa 6) | ✅ |
| **Probar** | resuelve una llamada real contra lo guardado, sin escribir nada | ✅ |

En la pestaña **Campos**, las 7 calculadas salen en la lista **bloqueadas y con la explicación**,
para que la pantalla sirva también como documentación del contrato.

En la pestaña **Reglas**, el valor destino de `razon_finalizacion` es un **`select` con los `name`
reales de `razones_finalizacion`** (por la sensibilidad a mayúsculas de §3.4); los de las banderas son
`0`/`1`; el de `error_origen`, `-1`/`0`/`1`/`2`/`3`.

### El botón que hace la diferencia: **Probar**

Elige una llamada del rango actual y muestra, **por cada columna**, el valor que se escribiría en
`tmp_lotes_det` con la configuración guardada, marcando:

- ✅ resuelto por ruta
- ✅ resuelto por regla (dice cuál)
- ⚠️ la ruta no existe → se usó el valor por defecto
- ⛔ columna calculada (no aplica)

Sirve para validar el mapeo **sin importar nada** y sin tocar código. Es el paso previo natural a la
Etapa 5 (importación al lote).

### Endpoints

| Método | Ruta | Devuelve |
|---|---|---|
| GET | `/eleven-labs/mapeo` | campos, reglas, transformaciones, razones y columnas destino |
| POST | `/eleven-labs/mapeo/campos` | guarda filas de `elevenlabs_mapeos_campo` |
| POST | `/eleven-labs/mapeo/reglas` | guarda filas de `elevenlabs_mapeos_regla` |
| POST | `/eleven-labs/mapeo/probar` | resuelve una llamada real contra el mapeo guardado |

Permisos, igual que hoy: leer con **`ver eleven labs`**, escribir con **`importar eleven labs`**.

---

## 7. Cómo queda el orden de las etapas

Esta pantalla **adelanta** parte de la Etapa 4: el mapeo es **datos**, y el traductor de la Etapa 4
lo consume. Por eso conviene hacerla antes de escribir el traductor:

```
Etapa 4a  tablas de mapeo + seeder + pantalla + Probar     ← esta propuesta
Etapa 4b  TraductorElevenLabs + AnalizadorTranscripcion + FechaTexto
          (lee las tablas de 4a en vez de tener rutas en el código)
Etapa 5   importación al lote
```

---

## 8. Decisiones

| # | Decisión | Estado |
|---|---|---|
| **M1** | ¿3 pestañas dentro del modal o vista aparte? | ✅ **pestañas**, dentro del modal de Equivalencias |
| **M2** | ¿Incluir las 22 columnas de constante o solo las 14 con ruta? | **las 51 clasificadas** (14 + 22 + 8 + 7): la lista completa es la documentación del contrato |
| **M3** | ¿Filas nuevas en `razones_finalizacion`? | ✅ **no**. El error del agente va a `error_tecnico_llamada = 1` y el buzón a `buzon_de_voz = 1`, expresado como reglas |
| **M4** | ¿Transformación de lista cerrada o expresiones libres? | **lista cerrada de 10**. Con expresiones libres se puede romper la importación desde la pantalla |

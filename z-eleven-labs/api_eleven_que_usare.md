# Documento de Referencia: API de Conversaciones de ElevenLabs para Lupita

> ⚠️ **La API key ya NO se guarda en este documento.**
> Vive en el `.env` del proyecto como `ELEVENLABS_API_KEY` y se lee del servidor con
> `config('services.elevenlabs.key')`. La key que estaba escrita aquí quedó expuesta en git,
> así que debe **rotarse** en el panel de Eleven Labs.
>
> Permisos que necesita la key: `convai_read` (conversaciones, agentes, números).
> `user_read` es opcional y solo sirve para consultar el saldo de créditos.

**Propósito**: Este documento describe los **3 endpoints** de la API de ElevenLabs Conversational AI que Lupita necesita consumir para implementar la funcionalidad de **solo lectura** de llamadas (listado, detalle, transcripción, análisis, variables de entrada y audio).

**Resumen del flujo**:
1. **Listado** → obtiene los `conversation_id` en un rango de fechas.
2. **Detalle** → por cada ID, obtiene transcripción, análisis, costos y variables dinámicas.
3. **Audio** → por cada ID, descarga la grabación MP3 (solo cuando el usuario quiera escucharla).
4. **Flujo Completo Recomendado para Lupita**
5. **Consideraciones Humanas**
---

## 1. Endpoint: Listar Conversaciones

**Método**: `GET`
**URL base**: `https://api.elevenlabs.io/v1/convai/conversations`
**Header obligatorio**: `xi-api-key: <TU_API_KEY>`

### Propósito
Obtiene una **lista paginada** de conversaciones, con metadatos básicos de cada una. Sirve para descubrir qué llamadas existen en un rango de fechas y obtener sus `conversation_id`.

### Query params relevantes

| Parámetro | Tipo | Descripción |
|---|---|---|
| `page_size` | integer | Número de resultados por página (máx. 100) |
| `call_start_after_unix` | integer | Timestamp Unix mínimo de inicio de llamada |
| `call_start_before_unix` | integer | Timestamp Unix máximo de inicio de llamada |
| `summary_mode` | string | Opcional. `include` para incluir `transcript_summary` |

### Ejemplo de solicitud:
https://api.elevenlabs.io/v1/convai/conversations?page_size=100&call_start_after_unix=$DesdeLima&call_start_before_unix=$HastaLima

### Respuesta (campos por cada elemento)
| Campo | Tipo | Descripción |
|---|---|---|
| `conversation_id` | string | ID único de la conversación (clave para los otros 2 endpoints) |
| `agent_id` | string | ID del agente |
| `agent_name` | string | Nombre del agente |
| `start_time_unix_secs` | integer | Timestamp Unix de inicio |
| `call_duration_secs` | integer | Duración en segundos |
| `status` | string | Estado (`done`, `in-progress`, etc.) |
| `call_successful` | string | Resultado (`success`, `failure`, etc.) |
| `cost` | integer | Costo en créditos |
| `cost_fiat` | float | Costo en USD |
| `message_count` | integer | Número de turnos |

> ⚠️ **Importante**: este endpoint **NO** devuelve transcripción, análisis de sentimiento ni variables dinámicas. Solo el resumen.

---

## 2. Endpoint: Obtener Detalle de Conversación

**Método**: `GET`
**URL**: `https://api.elevenlabs.io/v1/convai/conversations/{conversation_id}`
**Header obligatorio**: `xi-api-key: <TU_API_KEY>`

### Propósito
Obtiene **todos los datos** de una llamada específica: transcripción turno a turno, análisis de sentimiento, resumen, metadatos de costos, datos telefónicos y las variables dinámicas enviadas al iniciar la llamada.

### Parámetros de ruta
| Parámetro | Tipo | Descripción |
|---|---|---|
| `conversation_id` | string | ID único de la conversación (ej: `conv_6601m7t91gake51vntgb6bdhrn5c`) |

### Respuesta (campos relevantes para Lupita)

| Campo | Tipo | Descripción |
|---|---|---|
| `agent_id` | string | ID del agente que realizó la llamada |
| `agent_name` | string | Nombre del agente (ej: "DespachIA - Camila") |
| `status` | string | Estado de la conversación (`done`, `in-progress`, etc.) |
| `user_id` | string | Identificador del usuario (teléfono en tu caso) |
| `metadata.start_time_unix_secs` | integer | Timestamp Unix de inicio de la llamada |
| `metadata.call_duration_secs` | integer | Duración en segundos |
| `metadata.cost` | integer | Costo en créditos de ElevenLabs |
| `metadata.cost_fiat` | float | Costo en USD |
| `metadata.termination_reason` | string | Razón de finalización (`Call ended by remote party`, etc.) |
| `metadata.phone_call.direction` | string | Dirección (`outbound` / `inbound`) |
| `metadata.phone_call.external_number` | string | Número del usuario |
| `metadata.phone_call.agent_number` | string | Número del agente |
| `metadata.phone_call.call_sid` | string | SID de Twilio |
| `has_audio` | boolean | Indica si existe grabación de audio |
| `has_user_audio` | boolean | Indica si existe audio del usuario |
| `has_response_audio` | boolean | Indica si existe audio de respuesta del agente |
| `transcript` | array | Lista de turnos (ver sección 2.1) |
| `analysis.transcript_summary` | string | Resumen textual de la conversación |
| `analysis.call_successful` | string | Resultado (`success`, `failure`, etc.) |
| `analysis.sentiment_analysis` | object | Sentimiento general y por turno |
| `conversation_initiation_client_data.dynamic_variables` | object | Variables de negocio enviadas al iniciar (ver sección 2.2) |

### 2.1 Estructura de `transcript[]` (cada turno)

| Campo | Tipo | Descripción |
|---|---|---|
| `role` | string | `agent` o `user` |
| `message` | string | Texto del turno (puede venir truncado con `...`) |
| `original_message` | string | Texto completo sin truncar (usar este cuando exista) |
| `time_in_call_secs` | integer | Segundo en que ocurrió el turno |
| `interrupted` | boolean | Si el turno fue interrumpido |
| `producing_llm` | string | Modelo LLM que generó la respuesta del agente |

### 2.2 Variables dinámicas de negocio (`dynamic_variables`)

Campos clave que Lupita debe mapear a su base de datos:

| Variable | Ejemplo en tu llamada |
|---|---|
| `carrier_name` | "DUVAN GONZALO ABRIL CUERVO" |
| `reference` / `trip_code` | "1189448" |
| `origen` | "Malambo" |
| `destino` | "Cartagena De Indias" |
| `tipo_vehiculo` | "TRACTOMULA" |
| `tarifa_ofrecida` | "1400000" |
| `fecha_carga_texto` | "el sábado 21 de noviembre a las 7 y 28 de la mañana" |
| `fecha_entrega_texto` | "el jueves 26 de noviembre a las 6 y 28 de la tarde" |
| `telefono` | "+573115246409" |
| `empresa_id` | "2618" |
| `transportista_identificado` | `true` |

**Nota importante**: Las fechas solo vienen en **texto natural**, no en formato ISO (`aaaa-mm-dd hh:mm`). Si Lupita necesita fechas estructuradas, se recomienda **enviar campos adicionales** al iniciar la llamada (ej: `fecha_carga_iso: "2026-11-21T07:28:00-05:00"`).

---

## 3. Endpoint: Obtener Audio de Conversación

**Método**: `GET`
**URL**: `https://api.elevenlabs.io/v1/convai/conversations/{conversation_id}/audio`
**Header obligatorio**: `xi-api-key: <TU_API_KEY>`

### Propósito
Descarga la grabación de audio de la llamada en formato **MP3** (respuesta binaria, **no JSON**).

### Parámetros de ruta
| Parámetro | Tipo | Descripción |
|---|---|---|
| `conversation_id` | string | Mismo ID usado en el endpoint de detalle |

### Respuesta
- **Tipo de contenido**: `audio/mpeg`
- **Cuerpo**: Stream binario del archivo MP3

### Consideraciones para Lupita
1. La llamada debe hacerse **desde el backend** para no exponer la `xi-api-key` en el frontend.
2. El audio **no viene incluido** en la respuesta del endpoint de detalle; requiere una llamada separada.
3. Se puede cachear el MP3 en almacenamiento propio para evitar descargas repetidas.
4. Llamar solo si `has_audio == true` (campo del endpoint de detalle).

---

## 4. Flujo Completo Recomendado para Lupita

┌─────────────────────────────────────────────────────────────┐
│ 1. LISTAR conversaciones │
│ GET /v1/convai/conversations?page_size=100&... │
│ → Obtiene IDs de llamadas en rango de fechas │
└─────────────────────────────────────────────────────────────┘
│
▼
┌─────────────────────────────────────────────────────────────┐
│ 2. DETALLE por cada conversation_id de interés │
│ GET /v1/convai/conversations/{id} │
│ → Obtiene transcripción, análisis, costos, variables │
└─────────────────────────────────────────────────────────────┘
│
▼
┌─────────────────────────────────────────────────────────────┐
│ 3. AUDIO (bajo demanda) │
│ GET /v1/convai/conversations/{id}/audio │
│ → Descarga MP3 (solo si has_audio == true) │
└─────────────────────────────────────────────────────────────┘


## 5. Consideraciones Humanas
bien ahora te explicare con lujo de detalle lo q quiero
5.1 Requerimiento principal:necesito generar un lote con la misma data q obtenia de vapi pero ahora sera traducida de eleven lab a vapi
5.2 en mi layout/app.blade agregaremos un nuevo menu "Eleven Labs" hay pondras los links de todas tus vistas.
5.3 te voy a decir nombres provicionales no los tomes como nombres finales son solo para ordenarme en este momento de escribir las especificaciones.
5.4 puesto q ahora ya tengo un api necesito una vista donde pueda selecionar un rango con fecha y hora y mostrar q llamadas hay , 
en esta vista no es necesario el detalle la vista la llamaremos provicionalmente como "main".
5.5 en main tiene q haber un boton para ver el detalle y hay recien se solicitara la info detallada de la llamada de preferencia q sea un modal
5.6 en la vista main habra un boton para importar las llamadas para mi proyecto lupita
5.7 el flujo de esa accion debe de ser el siguiente:
    5.7.a obtener el rango de fechas
    5.7.b usando el json obtenido darle foreach a cada llamada para obtener su detalle con el api.
    5.7.c crea un nuevo array con la data lista (ya q esta si sera el detalle completo del rango).
    5.7.d traducir este detalle a la estructura q tenia con las llamadas vapi
    5.7.e el flujo era en vapi obtener el json detallado de las llamadas dentro del rango,
          luego traducir ese json con "public/js/import/json/procesar_json.js" recuerda q tambien se procesaba la transcripcion
          en "procesar_transcripcion.js" q esta en la misma carpeta este proceso generaba un excel con los campos q yo necesitaba
          para crear mi lote.
          despues de crear el excel lo subia a mi backend para que me generara un lote de llamadas y la funcion era "procesar_excel_llamadas"
          del controller "ImportController"
    5.7.f sabiendo el proceso anterior los cambios necesarios son traducir los campos del json de eleven labs q sea identicos al VAPI
          tambien te voy a dar los ids de los homonimos de los agentes por tipo_llamada de eleven lab.
          pero ya no vamos hacer este mismo flujo como ahora con el api tenemos el json directamente y no por archivo el procesamiento y la logica sera
          gatillada por el usuario entonces no necesitamos generar un archivo de excel
    5.7.g adicional agregare un campo a la tabla llamadas "el_id" para agilizar las comparaciones por plataformas seria inteligente crear otro campo ala tabla
          id_plataforma int entonces 1 para vapi y 2 para eleven labs si creas la migracion seria mas rapido para yo no hacerlo.
    5.7.g la creacion del lote no se puede omitir por que es necesario para el proceso de obtener la data de los viajes ya q no contamos con un api
          directo a esa data.
5.8 con respecto a los audios ya que no puedo poner mi token en un js publico necesito generar un endpoint en mi backend que me devuelva el audio de la llamada de eleven labs 
    pero no almacenarlo ya q cuento con muy poco espacio , la manera de guardar el link en el lote seria darle mi link generado en mi app.
5.9 actuaremos por etapas por q no tengo mucho tiempo en mi trabajos me enumeras el plan a seguir y las etapas en un archivo , luego me preguntas como comenzaremos.

## 6. Consideraciones Finales Humanas
si me falta mas por considerar planteamelo , tambien necesito q me des un costo aprox de uso de tu api para saber si alcanza mi saldo


    

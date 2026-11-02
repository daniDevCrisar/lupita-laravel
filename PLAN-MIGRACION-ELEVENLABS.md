# Plan de Migración VAPI → ElevenLabs — Sesión del Lunes

> **Propósito de este documento:** es el "punto de partida" que retomaré el lunes.
> Cuando me pases el **JSON real de ElevenLabs**, leeré este archivo y te expondré
> las posibilidades concretas de mapeo campo a campo antes de tocar código.
> **Hoy no se modifica código.** Solo se dejan registradas las decisiones tomadas.

---

## 1. Decisiones ya acordadas (baseline)

| # | Tema | Decisión |
|---|------|----------|
| D1 | Identificador de llamada | Se mantiene **`vapi_id` como columna genérica** (no se renombra). Guardará el ID del proveedor que sea (VAPI o ElevenLabs). Evita tocar ~25 archivos. |
| D2 | Origen / proveedor | Se crea columna **`id_proveedor`**: `1 = VAPI`, `2 = ElevenLabs`. Debe existir en `llamadas` (y probablemente en `tmp_lotes_det`) para separar métricas y reportes. |
| D3 | Importación | En el módulo **Importar → JSON** se creará **una vista nueva** para procesar el formato de ElevenLabs, llamada provisoriamente **"JSON ElevenLabs"**, en paralelo a la actual de VAPI. |
| D4 | Razón de finalización | `razon_finalizacion_id` se resuelve con una **tabla de equivalencias** ElevenLabs→VAPI: para cada `termination_reason` de ElevenLabs se elige la razón VAPI **más similar** y se reutiliza la tabla `razones_finalizacion` existente (no se crean IDs nuevos). |
| D5 | Costo | El `costo` **se recalculará** con la nueva lógica de facturación de ElevenLabs (por minuto). Se define el lunes con el JSON. |
| D6 | Audio | **Pendiente de verificar lunes.** Si hay acceso a los audios, se descargarán a storage propio (los URLs de ElevenLabs expiran). Ver §3.4. |

### Reutilización intencional (bajo riesgo)
Scoring (`DBScoringLlamadas`), `mensajes` (BOT/USER), conductores, referencias y rutas
**sobreviven intactos** si el nuevo pipeline produce las mismas columnas. El esfuerzo se
concentra en el **conector de importación** y en el **mapeo de campos de análisis**.

---

## 2. Qué necesito ver en el JSON de ElevenLabs del lunes

Marcá con ✔ lo que traiga el JSON; cada punto alimenta una decisión de mapeo.

### 2.1 Identificación y metadatos
- [ ] `conversation_id` → confirma formato/tipo para `vapi_id` (D1) y `id_proveedor=2` (D2).
- [ ] Fecha/hora de la llamada (nombre del campo y zona horaria → cómo se normaliza a UTC-5 Perú).
- [ ] Teléfono del conductor / número marcado (E.164?).
- [ ] Duración de la llamada (segundos/ms) → mapea a `audio_duracion`.

### 2.2 Telefonía y estado
- [ ] `termination_reason` / `status` → lista **todos los valores posibles** para la
      tabla de equivalencias (D4). Ej. esperados: `user_hangup`, `max_duration_exceeded`,
      `max_no_input`, `success`, `error`, `voicemail`, `transfer`, etc.
- [ ] ¿Hay separación entre "colgó el usuario" vs "colgó el agente" vs "sin respuesta"?

### 2.3 Transcripción
- [ ] Estructura del array de mensajes: nombres de clave (`role`, `message`, `type`, tiempos).
- [ ] Valores de `role`: `agent`/`user` (→ normalizar a `BOT`/`USER` de la tabla `mensajes`).
- [ ] ¿Incluye timestamps por mensaje? ¿interrupciones? ¿marcas de "user speaking"?

### 2.4 Análisis de IA (el reto más grande — ver §3.1)
- [ ] ¿Existen campos tipo "structured data" / "data capture" / "evaluation"?
- [ ] ¿Hay un resumen / comentario generado por la IA? (= `ia_result_comments_text`)
- [ ] ¿La IA devuelve un booleano o etiqueta de "éxito"? (= `exitosa_segun_ia`)
- [ ] ¿Se puede configurar que la IA capture "motivo de demora"? (= `ia_result_delay_reason_desc`)

### 2.5 Audio / grabación
- [ ] ¿Hay URL de grabación? ¿`recording_enabled` en el agent?
- [ ] ¿La URL expira? ¿en cuánto tiempo? ¿se puede descargar en formato estable (mp3/wav)?
- [ ] ¿Grabación mono o por canal separado (agente/user)?

### 2.6 Costo / facturación
- [ ] Campos de `billing` / `cost` / `usage` (minutos, modelo, voz, telefónía).
- [ ] Unidad y moneda → para definir la fórmula de recálculo de `costo` (D5).

---

## 3. Retos a resolver el lunes (con el JSON en mano)

### 3.1 Sustituir el "analysis output" de VAPI  ← **BLOCKING TÉCNICO**
Hoy VAPI genera con su prompt las 3 columnas: `exitosa_segun_ia`,
`ia_result_comments_text`, `ia_result_delay_reason_desc`.
ElevenLabs **no tiene un equivalente tan maduro**. Opciones a evaluar según el JSON:
- **(a) Capture / evaluation fields nativos** de ElevenLabs → si alcanzan, se mapean directo.
- **(b) Post-procesamiento propio con LLM** sobre la transcripción (webhook o job que llama
      a un LLM) → reproduce el prompt actual de VAPI. Es el camino si ElevenLabs no lo da.
- **(c) Degradado:** dejar esas columnas vacías y que el etiquetado humano + scoring las
      cubra. Impacta el reporte ejecutivo y el pre-etiquetado.
> Definir el lunes cuál aplica en función de §2.4.

### 3.2 Tabla de equivalencias `termination_reason` (D4)
Crear tabla/`dict` `elevenlabs_terminacion → razon_finalizacion_id` y revisar la lógica dura
de `DBTmpLlamadas` que hoy depende de IDs 4/7/9/10 para `error_origen`.

### 3.3 Normalizar transcript a `mensajes`
Adaptar `DBTmpLlamadas::guardar_mensajes()` para consumir el JSON estructurado de
ElevenLabs (rol `agent`→`BOT`), sin depender del formato texto `"BOT: ... // USER: ..."`.

### 3.4 Grabaciones (D6)
Si hay acceso: **descargar el audio a storage propio** durante la importación (URLs expiran),
no guardar solo el link. Si no hay acceso este lunes → se pospone, `audio_link`/`audio_duracion`
a 0/null y se documenta.

### 3.5 Conector de importación (D3)
Decidir arquitectura del "JSON ElevenLabs":
- **Poller por lote** (`GET /v1/convai/conversations?start_time=&end_time=` con paginación)
  → encaja con tu modelo actual de lotes y con "sin consola en servidor final".
- **Webhook post-call** → tiempo real, pero exige endpoint público HTTPS.
- Evaluar **rate limits (429)** y reintentos.

### 3.6 Convivencia histórica VAPI ↔ ElevenLabs
Con `id_proveedor` (D2): filtrar reportes por proveedor, y ojo con el **período de prueba
paralelo** (misma llamada el mismo día en ambos → el check `existe()` no la detecta como
duplicada porque el ID es distinto). Definir regla de desdoble.

### 3.7 UI con texto "VAPI"
Decidir si se renombra el "Reporte Ejecutivo VAPI"/menú/vistas `import-vapi/` a algo genérico,
o se mantiene "VAPI" para lo histórico y se añade lo de ElevenLabs aparte.

---

## 4. Orden de trabajo propuesto para el lunes

1. Recibir el JSON → completo checklist §2 (marcar qué trae).
2. Exponer **posibilidades de mapeo campo a campo** (este documento → tabla definitiva).
3. Cerrar las 4 decisiones blocking: §3.1 (análisis IA), §3.2 (razones), §3.4 (audio), §3.5 (conector).
4. Recién ahí, plantar código: columna `id_proveedor`, vista "JSON ElevenLabs", parser de
   transcripción, tabla de equivalencias y recálculo de costo.

---

## 5. Notas para cuando retome el contexto
- Usuario prefiere **propuesta lógica antes de implementar código** → el lunes primero mapeo y opciones, luego código.
- Preferencia de **Livewire nuevo con cambios mínimos** al código original.
- `procesar_audio` es patrón de referencia, **no modificarlo**.
- Servidor final **sin consola** → tareas Artisan/migraciones vía ruta HTTP.

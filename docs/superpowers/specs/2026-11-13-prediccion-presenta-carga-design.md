# Predicción de `presenta_para_carga` — diseño

**Fecha:** 2026-11-13
**Estado:** aprobado en conversación (método híbrido + parámetros inferidos y editables + vistas y API nuevas)

## 1. Intención

Adivinar a qué hora un camión se va a presentar para cargar (y, derivado, a qué hora
empieza a cargar) usando lo que el camión está haciendo ahora, no la costumbre de la
placa. La prueba de que la predicción sirve es una tabla que, con la configuración
actual y en modo automático, reproduce la predicción de las referencias cuyo
`presenta_para_carga` ya conocemos y la compara contra el método que hay hoy.

## 2. Lo que hay hoy y por qué está mal planteado

- `DBComportamiento::veredicto_carga()` (`app/Database/DBComportamiento.php:1171`), con
  `calendario_presentas()` (`:1127`), es un histograma día×hora de las presentas pasadas
  de la placa o del conductor: elige la hora más frecuente que todavía no pasó y la
  pinta en la ficha como «Vuelve a cargar hoy a las 10:38»
  (`resources/views/lupita/comportamiento_ficha.blade.php:58-120`).
- No mira dónde está el camión, ni el viaje en curso, ni el ciclo, ni la espera en
  planta, ni el descanso. Predice la costumbre: acierta el día típico y falla el viaje
  concreto, y no distingue dos camiones que salieron a la misma hora por rutas distintas.
- El prototipo `comportamiento_predecir` (página ya borrada; sólo queda el compilado en
  `storage/framework/views/53282eda…php`) repetía el histograma y resolvía la ubicación
  con «~1 h si está cerca / ~4 h si está lejos» escrito a mano.

## 3. Objetivos

1. Predecir `presenta_para_carga` de una referencia dada (input `ref`) anclando en el
   último hito conocido del camión.
2. Devolver también `inicio_de_carga = presenta + espera en planta de carga`.
3. Tabla de aciertos: para las refs ya cerradas, predicción automática vs real, con
   MAE, sesgo, % dentro de ±1 h/±3 h y comparación contra el histograma actual.
4. API pública (sin auth, como el resto de `/api`) que reciba la ref a predecir, el
   último viaje y N refs de historia, y opcionalmente las persista.
5. Todo explicable: cada predicción publica su desglose en minutos, sus supuestos y su
   nivel de evidencia.

## 4. No-objetivos y anti-objetivos

- **No** hay GPS ni telemetría en la base: la posición se deriva del último hito o la
  declara el usuario. No se inventa una latitud.
- **No** se reentrena ningún modelo automáticamente ni se guarda un histórico de
  predicciones.
- **No** se cambia el comportamiento de la ficha más allá de un botón que abre la
  calculadora con la placa precargada.
- **No** se escribe en `referencias` desde la predicción salvo `guardar=1` explícito, y
  esa escritura pasa por el mismo código que ya usa tceflexia.
- Anti-objetivo: un número sin desglose. Si el motor no puede explicar un tramo,
  lo marca como defecto y lo dice.

## 5. Restricciones

- PHP 8.3 / Laravel 12. Convención del repo: capas de datos estáticas en
  `app/Database/DB*`, payloads `stdClass`, vistas Blade con Bootstrap 5, textos y
  comentarios en español.
- En el servidor no hay consola: las tablas nuevas se aplican a mano. La migración es la
  fuente de verdad y además se deja el DDL en `sql/`.
- Si la tabla de parámetros no existe, el cálculo no revienta: usa los defaults de
  `config/prediccion.php` (misma convención que `scoring_config`).
- Las APIs del grupo `/api` son públicas (sin `auth` ni `permission`); las pantallas
  cuelgan de `auth` + `permission:ver tiempos`.
- Este entorno de desarrollo no tiene MySQL accesible: la lógica con base de datos se
  verifica con el comando de diagnóstico en el servidor, no con PHPUnit.

## 6. Enfoques considerados

| | Enfoque | A favor | En contra |
|---|---|---|---|
| A | **Cadena física**: último hito → etapas que faltan (promedios) → traslado destino→planta de carga → tiempo muerto → presenta | auditable, funciona con poca historia, explicable línea por línea | depende de promedios que pueden estar viejos; el tiempo muerto queda como supuesto |
| B | **Modelo aprendido**: regresión/cuantiles sobre features (hora, día, planta, ruta, hueco previo) | mejor error si hay miles de filas | caja negra, se degrada en placas nuevas, hay que reentrenar y defenderlo |
| C | **Híbrido**: A como esqueleto + calibración empírica del hueco real `fin_descargue → presenta` + ventanas de no-actividad inferidas | precisión de B con explicación de A; la tabla de aciertos puede apagar la parte aprendida si no gana | dos fuentes de verdad que hay que mantener coherentes |

**Elegido: C.** La cadena física da la estructura y los números cuando no hay historia;
la calibración aporta lo que sólo la operación sabe (cuánto tarda en volver, cuándo
duerme, cuándo almuerza). El histograma actual queda como línea base medida, no como
predictor.

## 7. Modelo

### 7.1 Ancla

`ancla` = último hito con fecha válida (≥ año 2000, igual que el módulo de tiempos) del
camión antes del corte `ahora`, en el viaje que el usuario elija o en el último si se
deja automático. Hitos válidos para anclar: `presenta_para_carga`, `inicio_de_carga`,
`fin_de_carga`, `inicio_ruta`, `qr_llegada_destino`, `inicio_descargue`, `fin_descargue`.

Todo el modelo trabaja en dos pasos:

1. **`t_fin`**: cuándo queda libre el camión.
   - Ancla cerrada (`fin_descargue`) → `t_fin = fin_descargue`.
   - Ancla a mitad de viaje → `t_fin = max(ahora, ancla + etapas faltantes)` (promedios
     por ruta, ver 7.2). Si el promedio ya se consumió y no hay hitos nuevos, se acota a
     `ahora` y se advierte `etapa_vencida`.
2. **`t_presenta = t_fin + hueco`**, donde `hueco` sale del calibrador (7.3) o, si no hay
   datos, de la cadena (`traslado + muerto_defecto`, con `muerto_defecto` = 60 min). El
   traslado nunca deja de ser un piso: `hueco = max(hueco, traslado)`. Luego se corre
   fuera de las ventanas de no-actividad (7.4).

El hueco se mide de `fin_descargue` a `presenta_para_carga` siguiente **de la misma
placa**, así que ya incluye el regreso vacío, la preparación y el descanso. Por eso el
traslado no se suma cuando hay hueco medido: sólo actúa como piso de coherencia
(`hueco = max(hueco, traslado)`) y como cadena cuando no hay hueco.

### 7.2 Etapas que faltan

Las cinco etapas se calculan por ruta desde `referencias`, con las mismas expresiones
del módulo de tiempos (`DBComportamiento::expresiones_etapas()`, `:220`), en una sola
consulta agregada por `ruta_id` sobre la ventana de lookback. Los compilados de las dos
APIs no alcanzan para esto: `tiempos_ruta_promedio` trae E4 y el ciclo completo
(E2..E6) pero no E3/E5/E6 por separado, y `tiempos_planta_promedio` trae las esperas
por planta y rol.

Qué se suma según el último hito del ancla:

| último hito | faltante hasta fin_descargue |
|---|---|
| `inicio_ruta` | E4 + E5 + E6 |
| `qr_llegada_destino` | E5 + E6 |
| `inicio_descargue` | E6 |
| `inicio_de_carga` / `fin_de_carga` | E3 + E4 + E5 + E6 |
| `presenta_para_carga` | E2 + E3 + E4 + E5 + E6 |

Si la ruta no tiene etapas medidas se prorratea con `prom_ciclo_min` del API
`/api/tiempos/promedio`; si tampoco hay ciclo, el ancla no sirve y se degrada la
evidencia. Guarda de frescura: si el tiempo ya transcurrido en la etapa actual supera
1,5× su promedio, se advierte y se usa lo que quede del promedio (nunca negativo).

### 7.3 Calibrador del hueco

Insumo: pares consecutivos de la placa `(ancla = fin_descargue del viaje Y, presenta del
viaje X siguiente)`, con `0 < hueco ≤ 10080 min` (7 días, mismo criterio que
`DBComportamiento::CICLO_MAX`).

- **Recencia:** se usan sólo los `pares_recientes_max` (20) pares más recientes de cada
  placa o chofer. No es un adorno: el hueco mediano de la flota pasó de ~1900 min en abril
  a ~540 min en noviembre, así que calibrar con 120 días parejos sobreestima la vuelta en
  casi medio día (medido: sesgo +176 min → −23 min al aplicarlo).
- `base` = mediana del hueco del **par de rutas** (ruta de Y → ruta de X) si hay
  `min_pares_ruta` (3) observaciones; si no, mediana de **la entidad** (placa; o
  conductor cuando la placa no concentra un chofer habitual > 50 %, misma regla que
  `ComportamientoController::chofer_habitual`); si no, mediana global.
- `residual` = corrección por hora del ancla, en 8 bins de 3 h, calculada como
  `mediana(hueco − mediana de la propia entidad)` de ese bin. Se mide contra la base de la
  entidad —no contra la del par de rutas— para que la corrección se pueda sumar a
  cualquier base, incluida la de una calibración hecha sólo con los pares de una placa.
- `hueco = base + residual`, acotado a `[traslado_min, 10080]`, con `p25/p75` de la misma
  fuente para la ventana que se muestra.
- **El hueco medido manda sobre el traslado promedio**: si el hueco de 20 pares reales da
  menos que el tránsito promedio de la ruta (que se calcula con `AVG` y se infla con
  viajes atípicos), se usa el medido y se advierte `hueco_menor_que_traslado`. El traslado
  sólo es piso cuando el hueco no existe (rama `cadena`).
- Nivel: `medido` (par de rutas con n ≥ 3), `parcial` (entidad o base global con n ≥ 3),
  `cadena` (sin pares suficientes: `traslado + muerto_defecto_min`, 60 min por defecto).

### 7.4 Tiempo muerto y ventanas inferidas

Se miden con la **densidad de hitos por franja del día**, no con cobertura de intervalos:
el hueco entre un fin de descargue y la presentación siguiente también es un intervalo, y
contarlo como actividad vuelve el descanso invisible (fue el primer error de esta parte).
Una franja de 30 min con menos de `min_hitos_bin` (2) marcas en todo el lookback es una
franja muerta, que es como se ven el descanso nocturno y el almuerzo sin que nadie los
configure. Con menos de `min_dias_actividad` (5) días de historia, o si el perfil sale
absurdo (más de 60 % del día quieto), se usan las ventanas por defecto de
`Servicio::VENTANAS_DEFECTO` (22:00–06:00) en vez de un perfil que empujaría todo al día
siguiente.

`Ventanas::desplazar()` mueve la predicción al final de la ventana en la que cayó, como
máximo `max_desplazamiento_min` (600) y en hasta 3 pasadas; si no alcanza, deja aviso.
Los parámetros se pueden corregir desde la pantalla y se guardan en `prediccion_config`.

### 7.5 Salida

```
presenta:  fecha, ventana{desde,hasta}, confianza 0..100, evidencia
inicio_de_carga: fecha, espera_min          (espera en planta de carga)
estado: {clave, etiqueta, motivo}           (en_ruta, en_planta_descarga, descargando,
                                             regresando, descansando, almuerzo,
                                             inactivo_extendido, sin_datos)
ancla: {ref, hito, fecha, edad_min, cerrada}
desglose: [{concepto, min, fuente}]         (restante_viaje, hueco_total, traslado_regreso,
                                             tiempo_muerto, espera_ventana, espera_carga)
supuestos: string[]
advertencias: string[]
```

`restante_viaje` + `hueco_total` + `espera_ventana` = lo que hay entre el ancla y
`presenta`. Dentro del hueco, `traslado_regreso` es el viaje de vuelta estimado con los
promedios de las APIs y `tiempo_muerto = hueco_total - traslado_regreso` es la espera y el
descanso del conductor: el número que hoy nadie mide.

Confianza: 95 para `medido` con n ≥ 10 y rango intercuartílico ≤ 120 min; se resta 20 si
el IQR > 240, 15 si el ancla es a mitad de viaje, 25 si hubo desplazamiento por ventana,
20 si el traslado salió de un fallback, 15 si el horizonte supera 24 h. Topes: 95
`medido`, 70 `parcial`, 45 `cadena`; `sin_datos` no devuelve fecha.

### 7.6 Estado del conductor

Función pura de (último hito, edad del ancla, `ahora`, ventanas). `descansando` si `ahora`
cae en ventana inactiva; `inactivo_extendido` si la edad del ancla supera 6 h y `ahora`
está en franja activa; el resto se deriva del hito (`en_ruta`, `en_planta_descarga`,
`descargando`, `regresando`). Es lo que se muestra como «dónde está / qué está haciendo».

## 8. Traslado desde una posición declarada

`ubicacion` puede ser `local` (id de `locales`), `ubigeo` (6 dígitos) o `indeterminado`.
Cuando el usuario la declara, resuelve el traslado hasta la planta de carga de la ref a
predecir con esta jerarquía (la primera que tenga datos):

1. Ruta inversa exacta: `rutas` con `origen_id` = posición y `destino_id` = planta de
   carga → `prom_efectivo` (una fila de `tiempos_ruta_promedio` por ruta y sentido).
2. Mismo par de ubigeos en sentido inverso.
3. La ruta de la propia ref (el regreso suele parecerse al viaje de ida).
4. Mediana de las rutas con el mismo par de departamentos.
5. `traslado_defecto_min` (240) con fuente `defecto`.

Cada nivel deja su marca en `fuente`, que es lo que se pinta en el desglose.

## 9. Tabla de aciertos (modo automático)

Para cada ref X del rango con `presenta_para_carga` no nulo y un viaje anterior Y de la
misma placa:

- corte `ahora = fin_descargue(Y)`;
- **calibración as-of por fila**: cada fila calibra sólo con los pares de su propia placa
  anteriores a ese corte (los `pares_recientes_max` más recientes), y toma la corrección
  horaria global —que no conoce ninguna fila— de una calibración hecha sobre la ventana
  anterior al rango. Así ninguna fila ve su propia presentación ni arrastra una
  calibración vieja (era la causa del sesgo de +8 h);
- se descartan las filas con hueco real ≤ 0 o > 7 días (`descartados` en la respuesta):
  están fuera del dominio del modelo y sólo aportan ruido a las métricas;
- se corre también la réplica del histograma actual con la historia previa al corte, para
  comparar manzanas con manzanas;
- agregados por corrida: n, MAE, mediana del error, sesgo, % |error| ≤ 30/60/120/180/360/720
  min, % dentro de la ventana p25–p75; y los mismos números para el histograma.

Los pares se traen en una sola consulta con `LAG(fin_descargue) OVER (PARTITION BY placa
ORDER BY presenta_para_carga)` sobre una ventana más larga que el rango (lookback 120
días) para que la primera ref de cada placa también tenga ancla.

### 9.1 Estado medido (2026-11-13, rango 1-ago → 3-nov, 500 filas)

| | n | MAE | mediana error | sesgo | ±1 h | ±6 h | ±12 h | ventana |
|---|---|---|---|---|---|---|---|---|
| Motor | 411 | 1189 min | +142 min | −23 min | 9 % | 37 % | 54 % | 77 % |
| Histograma actual | 326 | 1385 min | −96 min | −493 min | 8 % | 39 % | 55 % | — |

El motor queda centrado (sesgo ≈ 0) y gana en MAE, pero en tasa de acierto está empatado
con el histograma: predecir a qué hora vuelve un camión cuyo hueco mediano es de 19 h es un
problema difícil, y la tabla está para decirlo con números, no para maquillarlo. El sesgo
del histograma (−493 min) muestra por qué no sirve como predictor del viaje concreto:
acierta la rutina y llega temprano.

## 10. Superficies

**Pantallas** (`auth` + `permission:ver tiempos`):

- `GET /lupita/prediccion/presenta` — calculadora: ref a predecir, viajes ancla
  seleccionables, ubicación (auto/local/ubigeo), corte `ahora` editable, parámetros; y la
  tarjeta de resultado con desglose, supuestos y advertencias.
- `GET /lupita/prediccion/aciertos` — la tabla, con filtros de rango/placa y las tarjetas
  motor vs histograma.
- Botón «Predecir carga» en la ficha de comportamiento, que abre la calculadora con la
  placa precargada.

**API pública** (grupo `/api`, sin auth):

- `GET /api/prediccion/presenta?ref=…` — atajo sobre la BD.
- `POST /api/prediccion/presenta` — cuerpo con `ref`, y opcionales `ahora`, `ancla`,
  `ubicacion {tipo,id}`, `viaje`, `historia[]`, `guardar`. Cada ref del payload lleva sus
  hitos. Con `guardar: true` las refs del payload se escriben en `referencias`
  **antes** de predecir, por el mismo camino que `/api/refs/actualizar`
  (`App\Database\DBRefsActualizar`); sin `guardar` la predicción usa sólo lo que ya está
  en la base. `guardar` sin `viaje` ni `historia` ⇒ 422.
- `GET /api/prediccion/aciertos?desde=&hasta=&placa=&limit=` — la tabla en JSON.

Los errores de validación devuelven 422 en JSON (nunca redirect), como
`TiemposPromedioApiController::error_validacion()` (`:72`); ref inexistente 404 y fallo de
base 500, siempre con `success => false`.

## 11. Decisiones tomadas (y por quién)

- **C** como enfoque, decidido con el usuario en la conversación del 2026-11-13.
- Vistas y clases nuevas; la ficha sólo gana un botón (pedido explícito del usuario).
- Parámetros inferidos de los huecos **y** editables desde pantalla (elegido por el
  usuario).
- La ref a predecir es la única entrada obligatoria; todo lo demás es automático con
  overrides.
- Se predice `presenta_para_carga` como salida validable y `inicio_de_carga` como
  derivada con la espera en planta del API de plantas.
- La tabla muestra el método actual como línea base medida, no como recomendación.
- La escritura opcional de refs (`guardar`) se hace extrayendo el camino existente a
  `App\Database\DBRefsActualizar`, sin cambiar la respuesta del endpoint de tceflexia.

## 12. Queda a criterio de quien implemente

- Nombres exactos de los métodos privados y estructura interna de `Servicio`.
- Cómo se pinta el desglose (tabla o lista) mientras respete las claves de 7.5.
- Umbral de `min_pares_*` si el diagnóstico de datos muestra que 3 es demasiado exigente
  o demasiado laxo.
- Partir o no `Aciertos` en dos clases si crece.

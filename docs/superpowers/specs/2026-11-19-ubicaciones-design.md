# Ubicaciones · diseño

Fecha: 2026-11-19
Estado: pendiente de aprobación

## Intención y por qué

Hoy el catálogo `locales` (84 filas, tabla `locales`) es lo que permite saber de qué planta
a qué planta va un viaje: `sp_actualizar_ruta_id` busca la `palabra_clave` de cada local dentro
del `titulo_viaje` y con eso arma `rutas` / `referencias.ruta_id`. Cuando ningún local matchea,
la ref queda con `ruta_id` nulo y el viaje no entra en tiempos, plantas, reportes ni predicción.

De 32.707 refs, **691 están sin `ruta_id`**. Replicando la lógica del SP sobre esos títulos, el
origen del problema se reparte así:

| clase | refs | qué es realmente |
|---|---|---|
| falla el destino | 302 | casi todo **tiendas MAKRO de Lima** que no están en el catálogo (VES, ATE, SURCO, SJL, SMP, VENEZUELA, INDP, HUAYLAS, ZARATE). Locales faltantes reales. |
| falla el origen | 225 | `MAQUILA AGUA` (92), `MAQUILA` (9), `MAQUILA AGUA AQP` (4) y `REDI ...` / `DETERGENTE TACZANA`. Locales faltantes. |
| falla por paréntesis | ~90 | `PLANTA PUCALLPA(CORONADO)` → el SP borra los paréntesis y queda `PUCALLPACORONADO`, que ya no matchea `PUCALLPA`. **Bug de parseo, no local faltante.** |
| MULTIPARADA | 54 | el SP las rechaza a propósito. Decisión de negocio, no catálogo. |
| títulos basura | ~15 | `----` como título. |

O sea: el catálogo sí tiene huecos reales, pero la lista de "refs sin ruta" mezcla huecos de
catálogo con bugs de parseo y con basura. Para decidir qué local registrar hace falta ver los
**fragmentos de texto que no resuelven**, agrupados y clasificados, no las refs una por una.

Para dimensionar el trabajo, esos 691 títulos son en realidad **508 títulos distintos** y producen
**155 fragmentos sin resolver distintos**. El análisis completo es barato: se calcula al vuelo,
no necesita tabla compilada ni proceso nocturno.

Un dato más que apareció verificando: de los 691, **88 ya resolverían hoy sin crear ningún local**
(por ejemplo `ENVIO PT AJEPER CARAL - BABEL MAXO / CARGA 24.06` produce `RUT_CARAL-BABEL_MAXO` y
aun así está en nulo). Son refs que **nunca fueron procesadas**, no huecos de catálogo. Cualquier
pantalla de este módulo tiene que distinguirlas, o la lista de "problemas de catálogo" queda
inflada en un 13 % con casos que no son de catálogo.

Objetivo de negocio: que el catálogo `locales` sea completo, para que los viajes dejen de quedar
huérfanos y las pantallas de tiempos/predicción tengan de dónde agarrarse.

## Alcance

Grupo nuevo **Ubicaciones** en el menú, con cuatro pantallas, más dos columnas nuevas en `locales`:

| # | pantalla | ruta | responde |
|---|---|---|---|
| 1 | Títulos sin ruta | `/lupita/ubicaciones/titulos` | ¿qué refs no entraron y por qué? |
| 2 | Palabras repetidas | `/lupita/ubicaciones/palabras` | ¿qué local me falta crear? |
| 3 | Locales (+ lat/lng) | `/lupita/locales` (se conserva) | ¿dónde está cada planta? |
| 4 | Clientes | `/lupita/ubicaciones/clientes` | ¿a qué cliente cuelga cada local? |
| 5 | Desde Google Maps | `/lupita/ubicaciones/maps` | pego un link y salen las coordenadas |

Entregable **primero** (decisión del usuario): pantallas 1 y 2. Son un solo sistema — la 2 es la
lista de decisiones, la 1 es el "ver los títulos" de cada decisión.

## Goals

- Ver los 691 títulos sin `ruta_id` y **por qué** falla cada uno (motivo + origen/destino ya limpios).
- Ver un ranking de **fragmentos y palabras que no resuelven**, agrupado, con conteo de refs.
- Que cada candidato venga **clasificado solo** contra el catálogo: *local nuevo* / *typo de uno existente* / *el local existe pero el título se cortó* / *basura o multiparada*.
- Poder **descartar** un candidato para que no vuelva a aparecer, y **crear el local** desde ahí mismo con el nombre y la palabra clave prellenados.
- Guardar `lat`/`lng` por local, mostrarlas y dejar el campo listo para que predicción lo consuma después.
- Obtener `lat`/`lng` pegando un link de Google Maps, **sin APIs de pago**.

## Non-goals

- **No** se toca `sp_actualizar_ruta_id` ni se reprocesan refs: este módulo diagnostica y edita el catálogo, no recalcula rutas. Si un local nuevo hace que una ref deba re-procesarse, eso se hace con el mecanismo que ya existe (`CALL sp_actualizar_ruta_id`), fuera de estas pantallas.
- **No** se llama a ninguna API de Google ni de pago. El link se parsea localmente.
- **No** se consume Geoapify en este módulo. La key está en el `.env` y el servicio existe (`App\Prediccion\Geoapify`), pero acá no se usa: las coordenadas las provee el usuario con el link. Queda disponible para cuando predicción use `lat`/`lng`.
- **No** se calculan distancias ni tiempos en estas pantallas.
- **No** se toca la predicción, tiempos, reportes ni scoring.

## Anti-goals (aunque técnicamente funcionen, serían un fracaso)

- Un ranking de palabras sueltas lleno de `DE`, `CARGA`, `PT`, `PLANTA`, `CON`, `GPS`, `UT`, `AUXILIAR`, `VALIDAR`, `MERCADERIA`: sería mirar y no decidir nada.
- Que la pantalla muestre el mismo problema 691 veces (una por ref) en vez de 702 fragmentos agrupados.
- Que "crear local" sea un link a otra pantalla donde hay que volver a escribir todo lo que la vista ya sabía.
- Que las coordenadas queden como texto libre sin validar (una lat `-1110` rompe cualquier cálculo futuro).
- Depender de una lista negra de palabras que hay que engordar a mano en el código.

## Restricciones

- Laravel + Blade + Bootstrap 5, tema oscuro. Layout `layouts.app`, `@section('scripts')` para el JS. Sin frameworks JS nuevos.
- MySQL 8 (se puede usar `REGEXP_REPLACE`, `ROW_NUMBER`, CTE).
- Regla de acceso actual: catálogo `locales` es `middleware('role:admin')`. Ubicaciones hereda esa misma regla (admin).
- El `id` del local no se edita (es la clave a la que apuntan `rutas.origen_id` / `rutas.destino_id`).
- Los nombres de ruta `lupita.locales*` **se conservan** (los usa el menú y son links vivos).
- Código y comentarios en español, sin tildes en comentarios, igual que el resto del proyecto.

## Approaches considerados

Para el conteo de "palabras más usadas" había tres caminos:

**1. Palabras sueltas del título completo.** Partir cada título en palabras, contar, marcar si un
local ya la contiene. Lo más directo y lo más rápido de escribir.
*Descartado* porque el ranking se llena de ruido y necesita una lista negra manual que crece sin
fin; y las palabras que ya existen como local (`MAKRO`, `HUACHIPA`) tapan a los candidatos reales.

**2. Fragmentos de origen/destino que no resuelven.** Correr la lógica del SP, sacar los dos
fragmentos y contar solo los que ningún local cubre. Sin ruido, cada fila es un candidato real.
*Descartado como única opción* porque el fragmento completo puede ser larguísimo
(`MAKRO ATEUT CON GPS REQUIERE UN AUXILIAR PARA VALIDAR MERCADERIA`): te dice el problema pero no
te aísla la palabra clave que hay que registrar.

**3. Fragmentos + palabras, clasificados, con bandeja de descartes.** ← **elegida por el usuario.**
Del fragmento se guardan las dos cosas: el fragmento entero (para leer la frase real) y sus
palabras y n-gramas (para juntar `MAKRO ATE`, `MAQUILA AGUA`, `HUCHIPA` aunque vengan con cola
distinta). Cada candidato se clasifica solo contra el catálogo y trae acciones
(*crear local* / *agregar variante* / *ver títulos* / *descartar*).
Cuesta un poco más de código (el analizador de títulos y la clasificación), pero es la única que
responde la pregunta real: *¿qué local me falta?*

## El diseño

### 1. Analizador de títulos (pieza compartida)

`App\Ubicaciones\AnalizadorTitulos` — réplica fiel del SP V3 (`sql/rutas.sql`, líneas 243-400),
documentada en `/lupita/refs/ruta-id`. **No** escribe nada: recibe un título y devuelve el
diagnóstico.

```
analizar(string $titulo): array
  motivo:  sin_titulo | multiparada | sin_separador | ok
  origen_raw, destino_raw, origen, destino          (limpios, como los ve el SP)
  origen_resuelto:  {tipo: local|distrito|null, id, nombre, via: palabra_clave|distrito}
  destino_resuelto: idem
  ruta_id: string|null                              (el que habría quedado)
  falla:   null | origen | destino | ambos
```

Se replica **con sus rarezas a propósito**, porque si el analizador es más listo que el SP el
diagnóstico miente:

- corta en el **primer** `/`;
- separa por `" - "`, si no por `-`, si no por `" A "`;
- borra paréntesis **antes** de todo → `PUCALLPA(CORONADO)` queda `PUCALLPACORONADO` (esta es la causa de ~90 refs);
- `|` en `palabra_clave` es alternancia regex, y el texto viaja **crudo** al regex (por eso `.CHICLAYO` matchea `BABEL CHICLAYO`);
- **gana la primera fila en orden de PK** (`locales.id`), no la mejor coincidencia;
- si no hay local, busca el distrito del ubigeo y usa el ubigeo como id.

Fidelidad y costo: la resolución se hace **por fragmento distinto, no por ref**. 702 fragmentos
× 84 locales ≈ 59.000 comparaciones; se puede iterar en orden de PK para ser fiel al SP y sigue
siendo instantáneo.

`DBUbicaciones` (capa de datos, al estilo `DBLocales` / `DBTiemposPlanta`) hace las consultas y
usa el analizador. Nada de lógica de parseo en el controlador.

### 2. Pantalla "Palabras repetidas" (la principal)

Ruta `GET /lupita/ubicaciones/palabras` → `Ubicaciones\PalabrasController@index`.

Qué hace, en orden:

1. Trae los títulos de refs con `ruta_id` nulo o vacío y `titulo_viaje` no vacío → **691 refs**, **508 títulos distintos**.
2. Los agrupa por título normalizado (se borran fechas y números).
3. Corre el analizador sobre cada título distinto.
4. De cada fragmento (origen o destino) que **no** resolvió, extrae candidatos:
   - sus **palabras** de 3+ caracteres que no estén en la lista de ignoradas;
   - sus **n-gramas de 2 y 3** palabras;
   - el **fragmento entero** (espacios colapsados, máx. 120 caracteres) — pero solo como
     *texto para leer*, nunca como candidato a palabra clave (ver abajo).
5. Suma por candidato: `refs` (cantidad de refs en las que aparece) y `titulos` (cantidad de
   títulos distintos), guardando un título y una ref de ejemplo para el drill-down.
6. Clasifica cada candidato contra el catálogo (reglas abajo).
7. Marca los descartados y ordena por `refs` desc.

Resultado esperado del paso 4 sobre la data real: **155 fragmentos** distintos y unos **pocos
cientos de frases/palabras** candidatas. Números chicos: una sola página filtrada en cliente
alcanza.

**Ojo con el conteo:** un mismo título aporta varias frases (`MAQUILA AGUA` y `MAQUILA` y `AGUA`),
así que la columna `refs` de dos filas no se suma entre filas. Es intencional: cada fila es una
*palabra clave posible*, no una partición de las 691 refs. La cabecera muestra el total real de
refs, y la tabla nunca se presenta como un desglose que suma 691.

**Clasificación de un candidato** (en este orden, gana la primera que aplique):

| veredicto | condición | acción ofrecida |
|---|---|---|
| `basura` | fragmento vacío, `----`, solo signos, o título con `MULTIPARADA` | Descartar |
| `cubierto_parseo` | algún local cubre el candidato, pero el fragmento completo no resolvió | Ver títulos · Agregar variante al local |
| `typo` | ningún local lo cubre; hay un local con `similar_text` ≥ 85 % o `levenshtein` ≤ 2 sobre palabras clave normalizadas | Agregar variante · Ver títulos |
| `generica` | el candidato aparece en títulos de **clientes distintos** (p. ej. `ACEITE`, `INSUMOS`) | Ver títulos · Descartar (sin "Crear") |
| `nuevo` | nada de lo anterior | **Crear local** (prellenado) · Ver títulos · Descartar |

`generica` existe por el caso `(ACEITE)` de §3bis: una palabra que es un producto, no un cliente,
captura refs de varios clientes y crearía un local que se come viajes ajenos. En vez de ofrecer
"Crear", esas filas se leen y se descartan.

La normalización previa para comparar y para el hash: mayúsculas, sin tildes, espacios
colapsados. Ojo: **la comparación contra el catálogo usa el texto real**, la normalización es solo
para el hash y los parecidos.

**Pantalla** (Bootstrap, tema oscuro del proyecto):

**Dos solapas, no una lista mezclada.** Los fragmentos largos y las palabras cortas no son lo
mismo y no se leen igual:

- **Solapa "Palabras y frases"** (la que se abre por defecto) — el ranking accionable de
  candidatos a `palabra_clave`. Solo frases de hasta 60 caracteres: palabras sueltas y n-gramas
  de 2 y 3. Es donde se decide.
- **Solapa "Fragmentos"** — los **155** fragmentos completos sin resolver, en orden alfabético y
  con su conteo. Es para leer el texto real y entender el caso, no para crear nada (un fragmento
  de 120 caracteres es inútil como palabra clave).

```
Ubicaciones · Palabras repetidas                       [ Ver títulos sin ruta ]
691 refs sin ruta_id · 508 titulos distintos · 155 fragmentos · 88 ya resolverian

[ Palabras y frases ] [ Fragmentos ]     Veredicto [todos ▾]  Buscar [_______]
[ ] ver descartados

#  palabra / frase   refs  titulos  ¿local que lo cubre?   veredicto           acciones
1  MAQUILA AGUA       92    92     —                     local nuevo         [Crear] [Ver] [Descartar]
2  MAKRO              103   102    LOC_MAKRO_CAÑETE…    nuevo (¿uno o por tienda?)  [Crear] [Ver] [Descartar]
3  PUCALLPA           78    78     LOC_PUCALLPA          el local existe     [Ver] [Variante]
4  HUCHIPA            9     9     PLANTA HUACHIPA       typo                [Variante] [Ver]
5  MULTIPARADA        54    54     —                     basura / se descarta [Descartar]
```

(El orden y los conteos exactos los define el cálculo, no este boceto.)

**El caso MAKRO es una decisión de negocio que la vista debe hacer visible.** El catálogo tiene
cinco MAKRO (Cañete, Huancayo, Piura, Sullana, Trujillo) con la palabra clave **completa**
(`MAKRO CA¥ETE`), así que el texto `MAKRO ATE` no los matchea y aparecen 103 refs de tiendas de
Lima sin local. Hay dos salidas y son incompatibles:

- **un local por tienda** (`LOC_MAKRO_ATE`, `LOC_MAKRO_VES`, …): mantiene la granularidad actual,
  pero es dar de alta ~10 locales;
- **un `LOC_MAKRO` genérico** con palabra clave `MAKRO`: resuelve las 103 refs de una, pero
  colapsa las 10 tiendas en una sola ruta y pierde la distinción por tienda que hoy existe entre
  Cañete y Piura.

La vista no elige: lo muestra y el usuario decide. Lo que **no** debe pasar es que el módulo
proponga las dos cosas a la vez sin avisar.

**Crear** lleva al alta de local con `nombre` y `palabra_clave` precargados
(`/lupita/locales/nuevo?nombre=…&palabra_clave=…&desde=palabras`). El prefill está garantizado
por construcción: la solapa de candidatos solo muestra frases de ≤ 60 caracteres, así que nunca
choca con el `max:100` de la validación ni con el `VARCHAR(100)` de la columna.

**Variante** (no crea un endpoint nuevo) lleva a `/lupita/locales/{id}/editar` con la palabra
candidata **ya agregada** al final de `palabra_clave`, separada por `|`, para que el usuario vea
el campo completo y guarde. El impacto *no* se previsualiza en fase 1 (calcular "cuántas refs
cambiarían de ruta" es un cálculo aparte); si eso se vuelve necesario, es una fase posterior.

Detalles de la pantalla:

- Filtros: por veredicto y por texto, todo en cliente sobre las filas ya renderizadas, como hace
  hoy `lupita/locales/index.blade.php`.
- El contador de cabecera sale del cálculo, no de las filas visibles.
- **Ver** enlaza a la pantalla de títulos filtrada por ese fragmento.
- **Descartar** hace POST a `/lupita/ubicaciones/palabras/descartar` y la fila desaparece.
- Un botón **Exportar CSV** con lo que está filtrado (mismo patrón que el resto del panel).

**Descartes: se pueden ver y se pueden deshacer.** El servidor **no** los filtra: devuelve todos
los candidatos con una marca `descartado`, y el navegador los oculta. Así el checkbox
`[ ] ver descartados` significa algo de verdad y un clic equivocado no esconde un candidato real
para siempre. Con el checkbox marcado, las filas descartadas aparecen atenuadas y con un botón
**Restaurar** (POST a `/lupita/ubicaciones/palabras/restaurar`), que borra la fila de
`ubicaciones_descartes`. Es la única forma de deshacer que hay en fase 1 y está en la misma
pantalla, sin SQL.

### 3. Pantalla "Títulos sin ruta"

Ruta `GET /lupita/ubicaciones/titulos` → `Ubicaciones\TitulosController@index`.

Lista las refs con `ruta_id` nulo/vacío, agrupadas por título normalizado para que 92 refs con el
mismo título se lean como una línea con su contador, y desplegable a la lista de refs.

Columnas: `familia` (título de ejemplo) · `refs` · `por qué falla` (motivo del analizador) ·
`origen` → resuelto o `sin local` · `destino` → resuelto o `sin local` · acciones.

**Población y motivos.** La pantalla lista **694 refs** con `ruta_id` nulo/vacío: **691 con
título** (las que se analizan) y **3 sin `titulo_viaje`**, que se muestran aparte como "sin título".
(Las 55 refs sin título de la base no son un problema de esta pantalla: 52 de ellas ya tienen
`ruta_id`.) Los motivos del analizador sobre las 691 cuadran exacto:

| motivo | refs | significado | qué toca hacer |
|---|---|---|---|
| `origen` | 225 | el origen no resuelve | crear local o variante |
| `destino` | 302 | el destino no resuelve | crear local o variante |
| `ambos` | 22 | ninguno de los dos resuelve | ídem |
| `multiparada` | 54 | el SP las rechaza a propósito | decisión de negocio, fuera de catálogo |
| `ya_resolveria` | 88 | **ambos resuelven con el catálogo de hoy** | no es hueco de catálogo: la ref nunca se procesó |

225 + 302 + 22 + 54 + 88 = 691.

El motivo `ya_resolveria` no existe en el SP; es propio de esta pantalla y es el que evita
confundir "nunca procesado" con "falta un local". Esas 88 refs se muestran en un bloque aparte,
plegable, con la etiqueta **"no necesita local nuevo"** y sin acciones de catálogo.

**Reprocesar está fuera de fase 1.** El módulo diagnostica y edita el catálogo; no recalcula
`ruta_id`. Consecuencia que hay que tener presente: **crear los locales que faltan no mueve el
contador de 691** — las refs siguen en nulo hasta que alguien corra
`sp_actualizar_ruta_id` / `sp_actualizar_rutas_lote`. Y en esta base el SP **no está instalado**
(`SHOW PROCEDURE STATUS` viene vacío), así que hoy no hay nada que correr. Instalarlo y reprocesar
es un trabajo aparte, con su propio riesgo (escribe en `rutas` y `referencias`).

Filtros: por motivo, por texto, y por fragmento (parámetro `?fragmento=`) para llegar desde la
pantalla de palabras. Acepta `?ref=` para mostrar una sola ref (drill-down desde cualquier lado).

### 3bis. Una trampa del catálogo que este módulo destapa

`LOC_AJEPER_GRUPO_PACIFIC_OIL` tiene `palabra_clave = "GRUPO PACIFIC OIL|(ACEITE)"`. Ese
`(ACEITE)` no es un nombre de cliente: es un **producto**. Y como el SP matchea por contención de
palabra y gana la primera fila en orden de PK (`LOC_AJEPER...` va primero en el alfabeto), hay hoy
**30 refs sin ruta que mencionan "ACEITE" en el título** — `RECOJO ACEITE GRUPO PATRONA - PTA
HUACHIPA`, `RECOJO DE NN ACEITE ALDEBSA - PTA HUACHIPA`, `RECOJO DE ACEITE SUPERALIMENTOS NN` —
que al procesarse caerían **en el local equivocado**.

Es la prueba de que una palabra clave genérica hace daño. Consecuencias para el diseño:

- La clasificación debe marcar como riesgoso un candidato que sea una **palabra genérica**
  (aparece en títulos de clientes distintos), en vez de ofrecer "Crear local" a ciegas.
- `(ACEITE)` queda **fuera de este módulo**: no se corrige acá, pero el documento lo deja anotado
  porque cualquier reproceso futuro lo va a disparar. Si el usuario quiere, se limpia el catálogo
  antes de reprocesar.

Acepta `?ref=` para mostrar una sola ref (drill-down desde cualquier otro lado).

### 4. Locales con coordenadas

Dos columnas nuevas:

```sql
ALTER TABLE locales
  ADD COLUMN lat DECIMAL(10,7) NULL COMMENT 'latitud, -90..90',
  ADD COLUMN lng DECIMAL(10,7) NULL COMMENT 'longitud, -180..180';
```

`DECIMAL(10,7)` da 7 decimales (~1 cm) y llega de sobra para Peru (lat -0,04 a -18,45;
lng -68,65 a -81,33).

- **Formulario** (`lupita/locales/form.blade.php`): bloque "Ubicacion" con `lat`, `lng` y un
  recuadro para **pegar el link de Google Maps** con boton "Extraer". Validacion
  `numeric|between:-90,90` / `-180,180`; ambos vacios o ambos llenos (no medio par).
- **Listado**: columnas lat/lng y un boton "Ver en mapa" que arma
  `https://www.google.com/maps?q=<lat>,<lng>` (link externo, sin JS ni iframe).
- `DBLocales::crear/actualizar/fila/buscar/listar` suman `lat` y `lng`.
- Se actualiza `sql/locales.sql` con el `ALTER` para que el esquema quede documentado donde vive.

### 5. Pantalla "Desde Google Maps"

Ruta `GET /lupita/ubicaciones/maps` + endpoint `POST /lupita/ubicaciones/maps/extraer` (JSON).

`App\Ubicaciones\LinkGoogleMaps::extraer(string $link): array` — parser puro, sin dependencias:

```
{ ok: true, lat, lng, zoom, nombre, fuente: 'data|arroba|query', link_normalizado }
{ ok: false, error: 'mensaje en espanol' }
```

Patrones soportados, en orden de preferencia (el `!3d/!4d` es el mas preciso porque es el punto
del pin, no el centro del viewport):

| patron | ejemplo | que da |
|---|---|---|
| data block | `...!3d-11.1063!4d-77.4512!...` | lat, lng |
| arroba | `.../@-11.1063,-77.4512,17z` | lat, lng, zoom |
| query | `?q=-11.1063,-77.4512` · `?ll=` · `?daddr=` · `?center=` | lat, lng |
| place | `/maps/place/PLANTA+CARAL/...` | nombre (decodificado) |
| corto | `maps.app.goo.gl/...` · `goo.gl/maps/...` | se resuelve siguiendo el redirect |

Reglas de seguridad y robustez:

- Solo se aceptan hosts de Google (`google.com`, `www.google.com`, `maps.google.com`,
  `goo.gl`, `maps.app.goo.gl`). Cualquier otro → error. Esto evita que el endpoint sirva de SSRF.
- El redirect se sigue **a mano**, máximo 3 saltos, timeout 5 s, y cada salto se revalida contra
  la lista de hosts.
- Validacion de rango: lat -90..90, lng -180..180, y rechazo de `0,0` (link mal copiado).
- Funciona sin `.env` ni key: no hay ninguna credencial involucrada.

La pantalla tiene dos modos:

1. **Extraer**: pego el link, muestra nombre + coordenadas + link "Ver en el mapa" para confirmar
   a ojo, y permite **asignar a un local existente** (select con busqueda) o **crear uno nuevo**
   con lo extraido.
2. **Rellenar muchos**: la misma caja de pegado vive dentro del formulario de local, para no
   cambiar de pantalla cuando estas editando.

### 6. Pantalla "Clientes"

Ruta `GET /lupita/ubicaciones/clientes` → `Ubicaciones\ClientesController`
(+ `crear`, `guardar`, `editar`, `actualizar`), calcado del patron de `LocalesController`.

CRUD sobre `clientes` (`id`, `nombre`, `tipo`, `descripcion`, `palabra_clave`):

- El `id` no se edita (los locales apuntan a el con `locales.cliente_id`). Para "renombrar" hay
  que dar de alta otro cliente, igual que en locales.
- `tipo` es texto libre con `datalist` de los valores ya usados (Principal, Distribuidor,
  Mayorista, Cadena de Tiendas, ...).
- **No** hay borrado: el listado muestra cuantos locales cuelgan de cada cliente y enlaza a
  `/lupita/locales?cliente=`. Borrar dejaria locales huerfanos.
- El listado muestra: id, nombre, tipo, palabra clave (como badges separados por `|`), cuantos
  locales dependen y accion editar.

### 7. Navegacion

Nuevo desplegable **Ubicaciones** en el menu de usuario (donde hoy vive "Locales"), visible con
`@role('admin')` como ahora. Dentro: Títulos sin ruta · Palabras repetidas · Locales · Clientes ·
Desde Google Maps. Se quita la entrada suelta "Locales" (queda dentro del grupo) y los nombres de
ruta `lupita.locales*` no cambian.

### 8. Persistencia de decisiones

Una sola tabla, **solo para lo que decide el humano**. Todo lo demas se recalcula:

```sql
CREATE TABLE ubicaciones_descartes (
  id CHAR(32) PRIMARY KEY COMMENT 'md5(tipo|clave normalizada)',
  tipo VARCHAR(20) NOT NULL COMMENT 'fragmento | palabra | titulo',
  clave VARCHAR(500) NOT NULL COMMENT 'texto original, para leerlo en SQL',
  motivo VARCHAR(255) NULL,
  usuario_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  INDEX idx_tipo (tipo)
) COMMENT 'candidatos que el usuario descarto en Ubicaciones';
```

Por que solo descartes y no estados: si se guardara "creado" o "resuelto", el catalogo cambiaria y
las filas persistidas quedarian mintiendo. Con este diseno, en cuanto el local nuevo existe el
fragmento **resuelve solo** y desaparece de la lista, sin que nadie tenga que marcarlo.

### 9. Configuracion

`config/ubicaciones.php`, al estilo de `config/geoapify.php` (editable sin tocar codigo):

```php
'palabras_ignoradas' => [ ... ],   // DE, CARGA, PT, PLANTA, CON, GPS, UT, AUXILIAR, ...
'minimo_caracteres'  => 3,
'maximo_ngrama'      => 3,
'maximo_fragmento'   => 120,       // solo para la solapa "Fragmentos"
'maximo_candidato'   => 60,        // tope de una frase candidata a palabra_clave
'parecido_porcentaje'=> 85,        // similar_text para el veredicto "typo"
'parecido_distancia' => 2,         // levenshtein
```

### 10. Archivos

Nuevos:

- `app/Ubicaciones/AnalizadorTitulos.php` — replica del SP (puro, testeable).
- `app/Ubicaciones/LinkGoogleMaps.php` — parser del link (puro, testeable).
- `app/Ubicaciones/ExtractorCandidatos.php` — fragmentos → palabras y n-gramas.
- `app/Ubicaciones/Clasificador.php` — veredicto contra el catalogo.
- `app/Database/DBUbicaciones.php` — consultas de refs/rutas + descartes.
- `app/Database/DBClientes.php` — CRUD de clientes.
- `app/Http/Controllers/Ubicaciones/{PalabrasController,TitulosController,ClientesController,MapsController}.php`
- `resources/views/lupita/ubicaciones/{palabras,titulos,clientes,maps}.blade.php`
- `resources/views/lupita/ubicaciones/partials/tabla-candidatos.blade.php` (si hace falta)
- `config/ubicaciones.php`
- `database/migrations/…_add_lat_lng_to_locales.php`
- `database/migrations/…_create_ubicaciones_descartes.php`
- `sql/ubicaciones.sql` (el DDL para documentar el esquema, como el resto de `sql/`)

Modificados:

- `routes/web.php` — grupo `/lupita/ubicaciones` (incluye `palabras/descartar` y `palabras/restaurar`)
  + rutas de clientes y maps.
- `resources/views/layouts/app.blade.php` — desplegable Ubicaciones.
- `app/Database/DBLocales.php` — `lat`/`lng` en `listar`, `buscar`, `fila`.
- `app/Http/Controllers/LocalesController.php` — validacion de `lat`/`lng`.
- `resources/views/lupita/locales/{index,form}.blade.php` — coordenadas + caja de link.
- `sql/locales.sql` — `ALTER` de lat/lng.

### 11. Pruebas

PHPUnit como el resto (`tests/Feature/Ubicaciones/`):

- `AnalizadorTitulosTest` — los 6 casos reales de `/lupita/refs/ruta-id` (CARAL-BABEL MAXO, el de INSUMOS-HUACHIPA con guion sin espacios, el de PUCALLPA con parentesis, MULTIPARADA, titulo vacio, destino sin local). Es la prueba que fija la fidelidad con el SP.
- `ExtractorCandidatosTest` — n-gramas, palabras ignoradas, colapso de espacios.
- `ClasificadorTest` — nuevo / typo / cubierto_parseo / generica / basura; y que `ACEITE` cae en `generica` y no en `nuevo`.
- `LinkGoogleMapsTest` — un link por patron (`!3d`, `@`, `?q=`, place, corto, host invalido, coordenadas fuera de rango). Sin red: el caso del link corto se prueba con el resolvedor inyectado.
- `PalabrasTest` — la pantalla responde 200 con admin, 403 sin rol, y el conteo de una fixture cuadra.
- `DescartesTest` — descartar oculta la fila, marcado "ver descartados" aparece, y restaurar la devuelve.
- `ClientesTest` — alta y edicion; el `id` no se puede cambiar.

### 12. Fases

| fase | contenido | por que |
|---|---|---|
| **1** | Analizador + las 2 pantallas de diagnostico + tabla de descartes + menu Ubicaciones | es lo que el usuario pidio primero: identificar locales faltantes |
| 2 | `lat`/`lng` en locales + pantalla "Desde Google Maps" | depende de que el catalogo ya este completo; su valor es preparar prediccion |
| 3 | Pantalla de clientes | independiente, puede ir en cualquier momento |

Fase 1 se entrega y se usa antes de empezar la 2.

## Decisiones

**Del usuario:**

- Sin APIs de pago. Las coordenadas salen de un link de Google Maps que el pega a mano.
- Coordenadas solo para **guardar y mostrar**, dejando el campo listo para que prediccion lo
  consuma despues. No se toca prediccion ni Geoapify en este trabajo.
- Empezar por las palabras mas usadas para identificar locales.
- Opcion 3 para el conteo (fragmentos + palabras + clasificacion + descartes).

**Mias (para que las revises):**

- Conteo **por título y fragmento distinto, no por ref** (508 títulos y 155 fragmentos en vez de
  691 refs repetidas).
- Se reconstruye la logica del SP en PHP en vez de instalar el SP y consultarlo. El SP no esta
  instalado en esta base (`SHOW PROCEDURE STATUS` viene vacio) y ademas el analisis necesita
  correr sobre texto hipotetico ("¿que pasaria si..."), que un SP no permite.
- Se replica la logica **con sus rarezas** (parentesis, `|` como regex, primera fila en orden de
  PK) en vez de "arreglarla". Si el analizador fuera mas listo que el SP, el diagnostico mentiria.
- Solo se persisten los **descartes**; el resto se recalcula siempre.
- Los descartes **se pueden ver y restaurar** desde la misma pantalla (no se filtran en el servidor).
- El veredicto `generica` (palabra que es producto y captura clientes distintos) **no ofrece crear
  local**. Lo destape el caso `(ACEITE)` y 30 refs.
- **"Agregar variante" es solo un link** al formulario del local con la variante ya escrita, no un
  endpoint ni un guardado automatico. Sin previsualizacion de impacto en fase 1.
- "Crear local" prellena como maximo 60 caracteres, por construccion (limite de la solapa de candidatos).
- Los nombres de ruta `lupita.locales*` no se renombran.
- Clientes **sin borrado** (mismo criterio que locales: no se puede dejar huerfano lo que apunta).
- Ubicaciones queda `role:admin`, igual que locales hoy.
- Se entrega en 3 fases con la 1 primero.

**Abierto para vos (una sola pregunta):**

- Las **88 refs que ya resolverian** no necesitan local nuevo: necesitan que alguien corra el SP,
  que en esta base no esta instalado. O sea que **crear los locales que faltan no va a mover el
  contador de 691**. ¿Esta bien que fase 1 solo lo **muestre** (bloque "no necesita local nuevo",
  sin acciones), y el reproceso se trate despues como trabajo aparte? ¿O queres que fase 1 incluya
  un boton "reprocesar estas refs", que implica instalar el SP y escribir en `rutas`/`referencias`
  (y hoy dispararia el problema de `(ACEITE)`)? Mi recomendacion: **solo mostrarlo en fase 1**.

## Lo que queda a criterio del que implementa

- Nombres exactos de las columnas en pantalla y el ancho de las tablas.
- Si el drill-down de "Ver títulos" es un modal, un acordeon o una pantalla aparte filtrada.
- La lista inicial de `palabras_ignoradas` (parte de la que ya use para el diagnostico previo:
  `DE, DEL, LA, EL, LOS, LAS, Y, A, EN, CON, POR, PARA, PT, PTA, PLANTA, CARGA, DESCARGA, ENVIO,
  DESPACHO, RETORNO, RECOJO, INSUMOS, MULTIPARADA, EIRL, SAC, SRL, SA, PH, GPS, SCTR, UTCON,
  CARRETA, CORTINERO, ESTIBAS, UT, KM`).
- Paginacion o no: con 155 fragmentos y unos cientos de candidatos, una sola pagina filtrada en
  cliente alcanza; si crece, se pagina despues.
- El diseno visual fino de la caja de pegado del link.

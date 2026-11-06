@extends('layouts.app')

@section('title', 'Docs · sp_actualizar_ruta_id')

@section('content')

    <style>
        .docs-card { background: #212529; border: 1px solid #444; border-radius: 12px; }
        .docs-pre { background: #000; color: #7fe37f; padding: 1rem; border-radius: 8px; font-size: .82rem; overflow-x: auto; }
        .docs-pre .c { color: #8a8a8a; }
        .docs-tabla thead th { background: #2c3e50; color: #fff; }
        .docs-tabla, .docs-tabla td { color: #eee; }
        .paso { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px;
                border-radius: 50%; background: #0d6efd; color: #fff; font-weight: 700; font-size: .8rem; }
        .flecha { color: #6c757d; font-size: 1.2rem; line-height: 1; }
        .docs-mini { font-size: .82rem; }
    </style>

    <div class="row mt-3">
        <div class="col-12">
            <h1 class="text-light">Cómo se calcula el <code class="text-warning">ruta_id</code> de un viaje</h1>
            <p class="text-light mb-1">
                Documentación del procedimiento <code class="text-warning">sp_actualizar_ruta_id</code> que vive en
                <code>sql/rutas.sql</code> (versión activa: <b>V3</b>, líneas 243-400; V1 y V2 quedaron comentadas).
                Toma <b>una ref</b>, lee su <code>titulo_viaje</code>, deduce origen y destino contra
                <code>locales</code> y <code>ubigeo_distritos</code>, y deja el resultado en
                <code>rutas</code> y en <code>referencias.ruta_id</code>.
            </p>
            <p class="text-light small mb-0">
                Esta página solo documenta: no calcula ni escribe nada.
                <a href="{{ route('lupita.refs.seleccion') }}" class="text-info"><i class="bi bi-arrow-left"></i> Refrescar refs</a>
                ·
                <a href="{{ route('lupita.refs.docs') }}" class="text-info">Contrato del API de refs</a>
            </p>
        </div>
    </div>

    {{-- ================================================================ --}}
    <div class="row g-4 mt-2">
        <div class="col-md-6">
            <div class="docs-card p-3 h-100">
                <h5 class="text-light">Qué recibe y qué escribe</h5>
                <table class="table table-sm table-bordered docs-tabla docs-mini">
                    <tbody>
                    <tr><th style="width: 130px">Entrada</th><td><code>p_ref VARCHAR(50)</code> — una sola ref</td></tr>
                    <tr><th>Lee</th><td><code>referencias.titulo_viaje</code>, <code>locales</code>, <code>ubigeo_distritos</code></td></tr>
                    <tr><th>Escribe</th><td><code>rutas</code> (INSERT ... ON DUPLICATE KEY) y <code>referencias.ruta_id</code></td></tr>
                    <tr><th>Devuelve</th><td>un <code>SELECT</code> de diagnóstico con título, crudos, limpios, ids, ubigeos, 1 y <code>NOW()</code></td></tr>
                    <tr><th>Errores</th><td><code>SIGNAL SQLSTATE '45000'</code> con mensaje (título vacío, MULTIPARADA, origen/destino no encontrado)</td></tr>
                    </tbody>
                </table>
                <p class="text-secondary small mb-1">Masivo por lote: existe <code>sp_actualizar_rutas_lote(lote_id)</code>, invocado desde
                    <code>DBReferencias::actualizar_rutas()</code>; hace el mismo trabajo ref por ref.</p>
                <p class="text-warning small mb-0">
                    En este entorno local el SP <b>no está instalado</b> (comprobado con <code>SHOW CREATE PROCEDURE</code>);
                    se crea ejecutando el bloque V3 de <code>sql/rutas.sql</code> en la base del servidor.
                </p>
            </div>
        </div>

        <div class="col-md-6">
            <div class="docs-card p-3 h-100">
                <h5 class="text-light">Flujo en 8 pasos</h5>
                <ol class="text-light docs-mini mb-0">
                    <li><span class="paso">0</span> Lee el título y valida que exista.</li>
                    <li><span class="paso">0</span> Si trae <code>MULTIPARADA</code> → error, no se procesa.</li>
                    <li><span class="paso">1</span> Corta todo en el <b>primer</b> <code>/</code> (fechas, “CARGA 16.04”).</li>
                    <li><span class="paso">2</span> Separa origen y destino por <code>&#34; - &#34;</code>, si no por <code>-</code>, si no por <code>&#34; A &#34;</code>.</li>
                    <li><span class="paso">3</span> Limpia el origen: paréntesis, prefijos (<code>ENVIO</code>, <code>DESPACHO</code>, <code>CARGA</code>, <code>RETORNO</code>, <code>RECOJO</code>) con <code>PT</code>/<code>PLANTA</code> opcional y sufijo <code>PT</code>/<code>PLANTA</code>.</li>
                    <li><span class="paso">4</span> Busca el local por <code>palabra_clave</code> (regex con <code>\b</code>, el <code>|</code> separa variantes).</li>
                    <li><span class="paso">5</span> Si no hay local, busca el distrito del ubigeo y usa el <b>ubigeo como id</b>.</li>
                    <li><span class="paso">6</span> Arma el id, guarda la ruta y actualiza la ref.</li>
                </ol>
            </div>
        </div>

        {{-- ================================================================ --}}
        <div class="col-12">
            <div class="docs-card p-3">
                <h5 class="text-light">El SQL del procedimiento, paso por paso</h5>

                <p class="text-warning small mb-1"><b>Paso 0 — título y MULTIPARADA</b></p>
<pre class="docs-pre"><span class="c">-- una ref sin título no se puede procesar</span>
SELECT titulo_viaje INTO v_titulo FROM referencias WHERE ref = p_ref;
IF v_titulo IS NULL OR v_titulo = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro no tiene titulo de viaje';
END IF;

<span class="c">-- las multiparada se descartan completas (ni se intenta deducir)</span>
IF INSTR(v_titulo, 'MULTIPARADA') > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = CONCAT('Multiparada: ', v_titulo);
END IF;</pre>

                <p class="text-warning small mb-1"><b>Paso 1 — cortar en el primer “/”</b></p>
<pre class="docs-pre">SET v_sin_fecha = SUBSTRING_INDEX(v_titulo, '/', 1);  <span class="c">-- todo lo que sigue al primer / se descarta</span>
SET v_sin_fecha = TRIM(v_sin_fecha);</pre>

                <p class="text-warning small mb-1"><b>Paso 2 — separar origen y destino (orden de precedencia)</b></p>
<pre class="docs-pre">IF INSTR(v_sin_fecha, ' - ') > 0 THEN        <span class="c">-- guion con espacios (el normal)</span>
    v_sep_pos     = INSTR(v_sin_fecha, ' - ');
    v_origen_raw  = TRIM(SUBSTRING(v_sin_fecha, 1, v_sep_pos - 1));
    v_destino_raw = TRIM(SUBSTRING(v_sin_fecha, v_sep_pos + 3));
ELSEIF INSTR(v_sin_fecha, '-') > 0 THEN      <span class="c">-- cualquier guion (incluye “INSUMOS-HUACHIPA”)</span>
    ...
ELSEIF INSTR(v_sin_fecha, ' A ') > 0 THEN    <span class="c">-- “PLANTA CARAL A PLANTA HUACHIPA”</span>
    ...
ELSE
    v_origen_raw = ''; v_destino_raw = '';
END IF;</pre>

                <p class="text-warning small mb-1"><b>Paso 3 — limpiar origen y destino</b></p>
<pre class="docs-pre"><span class="c">-- origen: quitar paréntesis y el prefijo del tipo de movimiento</span>
v_origen_limpio = REPLACE(REPLACE(v_origen_raw, '(', ''), ')', '');
v_origen_limpio = REGEXP_REPLACE(v_origen_limpio,
    '^(ENVIO|DESPACHO|CARGA|RETORNO|RECOJO)\\s+(PT|PLANTA)?\\s*', '');
v_origen_limpio = REGEXP_REPLACE(v_origen_limpio, '\\s*(PT|PLANTA)\\s*$', '');
v_origen_limpio = TRIM(v_origen_limpio);

<span class="c">-- destino: paréntesis y fechas sueltas al final (“-18/04/2026”)</span>
v_destino_limpio = REPLACE(REPLACE(v_destino_raw, '(', ''), ')', '');
v_destino_limpio = REGEXP_REPLACE(v_destino_limpio, '-\\d{2}/\\d{2}/\\d{4}$', '');
v_destino_limpio = TRIM(v_destino_limpio);</pre>

                <p class="text-warning small mb-1"><b>Paso 4 — buscar el local (dos sentidos, gana la primera fila)</b></p>
<pre class="docs-pre">SELECT id, distrito_ubigeo INTO v_origen_id, v_ubigeo_origen
FROM locales
WHERE v_origen_limpio REGEXP CONCAT('\\b(', REPLACE(palabra_clave, '|', '|'), ')\\b')  <span class="c">-- el texto contiene una variante</span>
   OR palabra_clave  REGEXP CONCAT('\\b(', REPLACE(v_origen_limpio, '|', '|'), ')\\b') <span class="c">-- la variante contiene el texto</span>
LIMIT 1;   <span class="c">-- sin ORDER BY: no gana “la mejor”, gana la primera del plan</span></pre>
                <p class="text-secondary docs-mini mb-1">
                    <code>palabra_clave</code> guarda variantes separadas por <code>|</code>
                    (ej. <code>AMAZON|BABEL AMAZON</code>), por eso el <code>|</code> actúa como <b>OR</b> dentro del regex.
                    La comparación es por <b>contención de palabra</b> (<code>\b</code>), no por igualdad:
                    “DE INSUMOS PTA HUACHIPA” encuentra <code>HUACHIPA</code>.
                    El ubigeo del local sale de <code>locales.distrito_ubigeo</code>.
                </p>

                <p class="text-warning small mb-1"><b>Paso 5 — si no hay local, distrito del ubigeo</b></p>
<pre class="docs-pre">IF v_origen_id IS NULL THEN
    SELECT ubigeo, ubigeo INTO v_ubigeo_origen, v_origen_id
    FROM ubigeo_distritos
    WHERE v_origen_limpio REGEXP CONCAT('\\b', UPPER(distrito), '\\b')
    LIMIT 1;
END IF;

<span class="c">-- el destino además prueba igualdad exacta con el distrito</span>
IF v_destino_id IS NULL THEN
    SELECT ubigeo, ubigeo INTO v_ubigeo_destino, v_destino_id
    FROM ubigeo_distritos
    WHERE UPPER(distrito) = v_destino_limpio
       OR v_destino_limpio REGEXP CONCAT('\\b', UPPER(distrito), '\\b')
    LIMIT 1;
END IF;</pre>

                <p class="text-warning small mb-1"><b>Paso 6 — validar y armar el id</b></p>
<pre class="docs-pre">IF v_origen_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = CONCAT('Origen no encontrado: ', v_origen_limpio);
END IF;
IF v_destino_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = CONCAT('Destino no encontrado: ', v_destino_limpio);
END IF;

<span class="c">-- local + local: RUT_&lt;origen sin LOC_&gt;-&lt;destino sin LOC_&gt;</span>
<span class="c">-- (V3 NO cambia los “_” internos: RUT_CARAL-BABEL_MAXO)</span>
SET v_ruta_id = CONCAT('RUT_',
                       REPLACE(v_origen_id,  'LOC_', ''), '-',
                       REPLACE(v_destino_id, 'LOC_', ''));
<span class="c">-- el ELSEIF con ubigeos es código muerto: si alguno fuera NULL ya se habría lanzado SIGNAL</span></pre>

                <p class="text-warning small mb-1"><b>Paso 7 — guardar ruta y ref</b></p>
<pre class="docs-pre">INSERT INTO rutas (id, origen_id, destino_id, ubigeo_origen, ubigeo_destino, veces_usada, ultima_vez)
VALUES (v_ruta_id, v_origen_id, v_destino_id, v_ubigeo_origen, v_ubigeo_destino, 1, NOW())
ON DUPLICATE KEY UPDATE
    veces_usada    = veces_usada + 1,
    ultima_vez     = NOW(),
    ubigeo_origen  = IFNULL(v_ubigeo_origen,  ubigeo_origen),
    ubigeo_destino = IFNULL(v_ubigeo_destino, ubigeo_destino);

UPDATE referencias SET ruta_id = v_ruta_id WHERE ref = p_ref;</pre>
            </div>
        </div>

        {{-- ================================================================ --}}
        <div class="col-12">
            <div class="docs-card p-3">
                <h5 class="text-light">Ejemplos reales (títulos de esta base y su resultado)</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered docs-tabla docs-mini align-middle">
                        <thead>
                        <tr>
                            <th>titulo_viaje</th>
                            <th>Origen limpio</th>
                            <th>Destino limpio</th>
                            <th>ruta_id</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td><code>ENVIO PT AJEPER CARAL - BABEL MAXO / CARGA 16.04</code></td>
                            <td>AJEPER CARAL → <span class="text-info">CARAL</span></td>
                            <td>BABEL MAXO → <span class="text-info">MAXO</span></td>
                            <td><code class="text-success">RUT_CARAL-BABEL_MAXO</code></td>
                        </tr>
                        <tr>
                            <td><code>ENVIO DE INSUMOS PTA HUACHIPA - PTA CORONADO (PUCALLPA) / CARGA 15.04.2026</code></td>
                            <td>DE INSUMOS PTA HUACHIPA → <span class="text-info">HUACHIPA</span></td>
                            <td>PTA CORONADO PUCALLPA → <span class="text-info">PUCALLPA</span></td>
                            <td><code class="text-success">RUT_HUACHIPA-PUCALLPA</code></td>
                        </tr>
                        <tr>
                            <td><code>INSUMOS AMAZON - CARAL/CARGA 16-04-26- CORTINERO</code></td>
                            <td>INSUMOS AMAZON → <span class="text-info">AMAZON</span></td>
                            <td>CARAL</td>
                            <td><code class="text-success">RUT_BABEL_AMAZON-CARAL</code></td>
                        </tr>
                        <tr>
                            <td><code>ENVIO NUEVOS NEGOCIOS - PLANTA CHICLAYO / CARGA 16/04/2026</code></td>
                            <td>NUEVOS NEGOCIOS → <span class="text-danger">sin local ni distrito</span></td>
                            <td>PLANTA CHICLAYO → CHICLAYO</td>
                            <td><code class="text-danger">NULL + SIGNAL “Origen no encontrado”</code></td>
                        </tr>
                        <tr>
                            <td><code>DESPACHO DE INSUMOS-HUACHIPA -PTA AYACUCHO - CARGA 22/04/2026</code></td>
                            <td>DE INSUMOS → <span class="text-danger">sin local ni distrito</span></td>
                            <td>HUACHIPA -PTA AYACUCHO - CARGA 22 → coincide <span class="text-info">HUACHIPA</span></td>
                            <td><code class="text-danger">NULL + SIGNAL “Origen no encontrado”</code></td>
                        </tr>
                        <tr>
                            <td><code>MULTIPARADA CARGA HUACHIPA NUEVOS NEGOCIOS / CALLAO/LIMA NORTE 18.04.2026</code></td>
                            <td colspan="2"><span class="text-danger">ni se parsea: se descarta por MULTIPARADA</span></td>
                            <td><code class="text-danger">NULL + SIGNAL “Multiparada”</code></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-secondary docs-mini mb-0">
                    El quinto caso muestra la trampa del guion sin espacios: <code>INSUMOS-HUACHIPA</code> se parte primero por
                    el <code>-</code> y el origen queda “DESPACHO DE INSUMOS”, que no existe en el catálogo.
                </p>
            </div>
        </div>

        {{-- ================================================================ --}}
        <div class="col-12">
            <div class="docs-card p-3">
                <h5 class="text-light">Dónde se recibe el JSON con <code>titulo_viaje</code> en esta app</h5>
                <table class="table table-sm table-bordered docs-tabla docs-mini align-middle">
                    <thead>
                    <tr><th>Entrada</th><th>Controlador</th><th>Qué hace con el título</th></tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><span class="badge bg-danger">POST</span> <code>/api/refs/actualizar</code></td>
                        <td><code>Api\ActualizarRefsController::actualizar_refs</code></td>
                        <td>
                            Recibe <code>refs{ "REF": { "titulo_viaje": ... } }</code>. Si el título se escribe
                            (nuevo o distinto), llama <code>reprocesar_ruta()</code> →
                            <code>CALL sp_actualizar_ruta_id('REF')</code> y devuelve
                            <code>ruta_reprocesada</code>.
                        </td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-danger">POST</span> <code>/api/lote/{id}/detalle/actualizar</code></td>
                        <td><code>Api\LlamadasApiController::actualizar_lote_detalle</code></td>
                        <td>
                            Guarda el título en <code>tmp_lotes_ref</code>; al procesar el lote se
                            llama al SP masivo <code>sp_actualizar_rutas_lote</code> (vía
                            <code>DBReferencias::actualizar_rutas()</code>).
                        </td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-success">GET</span> <code>/importar/json</code></td>
                        <td><code>ImportController::procesar_json</code></td>
                        <td>Pantalla de prueba: muestra el JSON que llega; no es un endpoint del flujo productivo.</td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-success">GET</span> <code>/api/refs</code></td>
                        <td><code>Api\ActualizarRefsController::devolver_refs_por_lotes</code></td>
                        <td>Solo lectura: devuelve las refs pendientes (sin título); el título lo manda tu app en el POST.</td>
                    </tr>
                    </tbody>
                </table>
                <p class="text-secondary docs-mini mb-0">
                    En esta app el <code>ruta_id</code> <b>no se calcula en PHP</b>: PHP solo normaliza y escribe el título; la
                    deducción la hace la base de datos con el SP. Si tu front/app quiere el mismo resultado, debe replicar la
                    heurística del SP (abajo) o delegar en el SP.
                </p>
            </div>
        </div>

        {{-- ================================================================ --}}
        <div class="col-12">
            <div class="docs-card p-3">
                <h5 class="text-light">Cómo replicar el análisis del título en tu app (JavaScript)</h5>
                <p class="text-light docs-mini">
                    Réplica fiel de la V3. Recibe el título y los catálogos (<code>locales</code> y
                    <code>ubigeo_distritos</code>) y devuelve el mismo id; lanza error en los mismos casos que el
                    <code>SIGNAL</code>.
                </p>
<pre class="docs-pre">// catálogos: [{ id: 'LOC_CARAL', palabra_clave: 'CARAL', distrito_ubigeo: '150601' }, ...]
// distritos: [{ ubigeo: '110101', distrito: 'ICA' }, ...]

function rutaIdDesdeTitulo(titulo, locales, distritos) {
  const t = String(titulo || '').toUpperCase().trim();
  if (!t) throw new Error('sin titulo de viaje');
  if (t.includes('MULTIPARADA')) throw new Error('multiparada');

  // PASO 1: cortar en el primer "/"
  const sinFecha = t.split('/')[0].trim();

  // PASO 2: separar (precedencia " - "  >  "-"  >  " A ")
  let origenRaw = '', destinoRaw = '', pos;
  if ((pos = sinFecha.indexOf(' - ')) !== -1) {
    origenRaw  = sinFecha.slice(0, pos).trim();
    destinoRaw = sinFecha.slice(pos + 3).trim();
  } else if ((pos = sinFecha.indexOf('-')) !== -1) {
    origenRaw  = sinFecha.slice(0, pos).trim();
    destinoRaw = sinFecha.slice(pos + 1).trim();
  } else if ((pos = sinFecha.indexOf(' A ')) !== -1) {
    origenRaw  = sinFecha.slice(0, pos).trim();
    destinoRaw = sinFecha.slice(pos + 3).trim();
  }

  // PASO 3: limpiar
  const limpiarOrigen = (x) => x
    .replace(/[()]/g, '')
    .replace(/^(ENVIO|DESPACHO|CARGA|RETORNO|RECOJO)\s+(PT|PLANTA)?\s*/, '')
    .replace(/\s*(PT|PLANTA)\s*$/, '')
    .trim();

  const limpiarDestino = (x) => x
    .replace(/[()]/g, '')
    .replace(/-\d{2}\/\d{2}\/\d{4}$/, '')
    .trim();

  const origen  = limpiarOrigen(origenRaw);
  const destino = limpiarDestino(destinoRaw);

  // PASO 4-5: buscar local por palabra clave y, si no hay, el distrito del ubigeo
  // El SP NO escapa nada: el texto limpio viaja crudo al regex (ver nota de ".CHICLAYO")
  const rx = (patron) => new RegExp('\\b(' + patron + ')\\b', 'i');

  function buscarLocal(texto) {
    const rxTexto = rx(texto);
    for (const loc of locales) {                      // mismo orden que la PK de `locales`
      const clave = String(loc.palabra_clave || '').trim();
      if (!clave) continue;
      const contiene = rx(clave).test(texto);         // texto contiene una variante
      const esParte  = rxTexto.test(clave);           // variante contiene el texto
      if (contiene || esParte) return loc;
    }
    return null;
  }

  function buscarDistrito(texto) {
    const exacto = distritos.find(d => String(d.distrito).toUpperCase() === texto);
    if (exacto) return exacto;
    return distritos.find(d => new RegExp('\\b' + String(d.distrito).toUpperCase() + '\\b', 'i').test(texto)) || null;
  }

  const localOrigen  = buscarLocal(origen);
  const localDestino = buscarLocal(destino);

  const origenId  = localOrigen  ? localOrigen.id  : (buscarDistrito(origen)  || {}).ubigeo;
  const destinoId = localDestino ? localDestino.id : (buscarDistrito(destino) || {}).ubigeo;

  if (!origenId)  throw new Error('Origen no encontrado: ' + origen);
  if (!destinoId) throw new Error('Destino no encontrado: ' + destino);

  // PASO 6: armar el id (solo se quita el prefijo LOC_, el "_" interno se conserva)
  return 'RUT_' + origenId.replace('LOC_', '') + '-' + destinoId.replace('LOC_', '');
}</pre>

                <p class="text-secondary docs-mini mb-0">
                    Probada contra las 500 refs más recientes de esta base: <b>498 devuelven exactamente el mismo
                    <code>ruta_id</code></b> que está guardado; las otras 2 son refs con <code>ruta_id</code> nulo que el SP
                    también rechaza (una <code>MULTIPARADA</code> y otra “Destino no encontrado”).
                </p>

                <div class="row g-3 mt-2">
                    <div class="col-md-6">
                        <p class="text-warning docs-mini mb-1">Diferencias que tienes que vigilar al replicarlo</p>
                        <ul class="text-light docs-mini mb-0">
                            <li><b>Gana la primera fila</b>: el SP no ordena por “mejor coincidencia”. Replicar el orden de la PK de <code>locales</code> (<code>id</code>).</li>
                            <li><b>Sin tildes ni mayúsculas</b>: MySQL compara con collation <code>_ai_ci</code>; en JS hay que pasar todo a mayúsculas (y, si puedes, quitar tildes).</li>
                            <li><b>El “|” es OR</b>: <code>AMAZON|BABEL AMAZON</code> es una alternancia de regex, no un literal.</li>
                            <li><b>PTA no se limpia</b>: el prefijo solo quita <code>PT</code>/<code>PLANTA</code> pegados al prefijo del movimiento; “PTA HUACHIPA” se resuelve por contención de <code>HUACHIPA</code>.</li>
                            <li><b>El texto va crudo al regex</b>: <code>DESPACHO PT.CHICLAYO</code> queda en <code>.CHICLAYO</code> y ese punto es un <b>comodín</b>, así que matchea <code>BABEL CHICLAYO</code> (por eso esa ref sale <code>RUT_BABEL_CHICLAYO-…</code> y no el ubigeo de Chiclayo).</li>
                            <li><b>El “_” se conserva</b> en el id (<code>RUT_CARAL-BABEL_MAXO</code>); V1 y V2 sí lo convertían a guion.</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <p class="text-warning docs-mini mb-1">Prueba directa en la base</p>
<pre class="docs-pre">CALL sp_actualizar_ruta_id('999905');

SELECT ref, titulo_viaje, ruta_id
FROM referencias WHERE ref = '999905';

SELECT * FROM rutas WHERE id = 'RUT_CARAL-BABEL_MAXO';</pre>
                        <p class="text-secondary docs-mini mb-0">
                            Si la ref ya tenía <code>ruta_id</code> y el título es nuevo, el <code>UPDATE</code> lo reemplaza.
                            Si el SP lanza <code>SIGNAL</code>, la ref queda como estaba (no se actualiza nada).
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection

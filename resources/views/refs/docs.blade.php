@extends('layouts.app')

@section('title', 'Docs API · Actualizar Referencias')

@section('content')

    @php
        $url_get = url('/api/refs');
        $url_post = url('/api/refs/actualizar');
    @endphp

    <style>
        .docs-card { background: #212529; border: 1px solid #444; border-radius: 12px; }
        .docs-pre { background: #000; color: #7fe37f; padding: 1rem; border-radius: 8px; font-size: .85rem; overflow-x: auto; }
        .docs-pre .k { color: #6db3f2; }
        .docs-tabla thead th { background: #2c3e50; color: #fff; }
        .docs-tabla, .docs-tabla td { color: #eee; }
        .metodo { font-family: monospace; font-size: 1.05rem; }
    </style>

    <div class="row mt-3">
        <div class="col-12">
            <h1 class="text-light">API Actualizar Referencias <small class="text-secondary fs-6">contrato para tceflexia generalMilestonesRoute</small></h1>
            <p class="text-light">
                Escritura <b>directa</b> en la tabla <code class="text-warning">referencias</code>, sin pasar por tmp ni reprocesar llamadas.
                Sin token: rutas públicas del grupo <code>api</code> (como las de lotes).
                <a href="{{ route('lupita.refs.seleccion') }}" class="text-info"><i class="bi bi-arrow-left"></i> Volver a la selección de lotes</a>
            </p>
        </div>
    </div>

    {{-- ================================================================ --}}
    <div class="row g-4">
        <div class="col-12">
            <div class="docs-card p-3">
                <h4 class="text-light"><span class="badge bg-success metodo">GET</span> {{ $url_get }}</h4>
                <p class="text-light mb-1">Devuelve las <b>refs únicas</b> de los lotes indicados que <b>aún no tienen <code>fin_descargue</code></b> (los viajes completos nunca se envían).</p>
                <table class="table table-sm table-bordered docs-tabla mt-3">
                    <thead><tr><th>Parámetro</th><th>Tipo</th><th>Descripción</th></tr></thead>
                    <tbody>
                    <tr><td><code>lotes</code></td><td>string</td><td>lista de lote_id separados por coma (el mismo valor del link de tceflexia). Máx. 200 lotes.</td></tr>
                    </tbody>
                </table>
                <p class="text-secondary small mb-1">Ejemplo:</p>
<pre class="docs-pre">GET {{ $url_get }}?lotes=202606011200001234,202606020930005678</pre>
                <p class="text-secondary small mb-1">Respuesta (200):</p>
<pre class="docs-pre">{
  "refs": [ "26100345", "26100346", "26100401" ],
  "total": 3
}</pre>
                <p class="text-secondary small mb-1">Si <code>lotes</code> llega vacío o con valores no numéricos:</p>
<pre class="docs-pre">{ "success": false, "error": "Parametro \"lotes\" vacio o invalido. Usar: ?lotes=ID1,ID2" }  // HTTP 422</pre>
            </div>
        </div>

        {{-- ================================================================ --}}
        <div class="col-12">
            <div class="docs-card p-3">
                <h4 class="text-light"><span class="badge bg-danger metodo">POST</span> {{ $url_post }}</h4>
                <p class="text-light mb-1">
                    Solo JSON (<code>Content-Type: application/json</code>; responde 415 si no).<br>
                    Valida <b>toda</b> la data antes de escribir; las refs inválidas no frenan a las demás.<br>
                    Máx. {{ \App\Http\Controllers\Api\ActualizarRefsController::MAX_REFS_POR_ENVIO }} refs por envío.
                </p>

                <h5 class="text-light mt-4">Cuerpo recibido</h5>
<pre class="docs-pre">{
  "referencias_de_lotes": true,
  "refs": {
    "26100345": {
      "titulo_viaje": "LIMA - HUARAZ / CARGA 03-10",
      "placa": "S1AB954",
      "compromiso_carga":  "03/10/2026 12:00",
      "presenta_para_carga": "03/10/2026 14:05",
      "inicio_de_carga":   "03/10/2026 15:20",
      "fin_de_carga":      "03/10/2026 18:40",
      "inicio_ruta":       "03/10/2026 19:00",
      "qr_llegada_destino":"04/10/2026 06:15",
      "inicio_descargue":  "04/10/2026 07:30",
      "fin_descargue":     ""
    },
    "26100346": { "placa": "P3ER102", "inicio_ruta": "04/10/2026 20:10:00" }
  }
}</pre>
                <p class="text-secondary small">
                    También se acepta <code>"refs": [ {"ref":"26100345", ...}, ... ]</code> (lista de objetos con campo <code>ref</code>).
                    Los campos que no llegan, o llegan como <code>""</code> / <code>null</code>, se tratan como <b>"no enviado"</b>: conservan el valor actual, nunca se pisa a NULL.
                    <b class="text-warning">fecha_despachador está obsoleta</b>: aunque llegue, se ignora.
                </p>

                <h5 class="text-light">Formatos de fecha aceptados</h5>
                <table class="table table-sm table-bordered docs-tabla">
                    <thead><tr><th>Formato</th><th>Ejemplo</th></tr></thead>
                    <tbody>
                    <tr><td>dd/mm/aaaa hh:mm</td><td><code>03/10/2026 14:05</code></td></tr>
                    <tr><td>dd/mm/aaaa hh:mm:ss</td><td><code>03/10/2026 14:05:30</code></td></tr>
                    <tr><td>dd/mm/aaaa (sin hora → 00:00:00)</td><td><code>03/10/2026</code></td></tr>
                    <tr><td>aaaa-mm-dd hh:mm[:ss] (ya normalizada)</td><td><code>2026-10-03 14:05:00</code></td></tr>
                    </tbody>
                </table>
                <p class="text-secondary small">Cualquier otro formato invalida <b>esa ref</b> con el error en <code>resultados[].errores</code> (no frena el resto del envío).</p>

                <h5 class="text-light">Reglas de escritura</h5>
                <table class="table table-sm table-bordered docs-tabla">
                    <thead>
                    <tr><th>Campo</th><th>referencias_de_lotes = true</th><th>referencias_de_lotes = false</th></tr>
                    </thead>
                    <tbody>
                    <tr><td>Ref inexistente</td><td><b>NO se inserta</b> → estado <code>no_encontrada</code></td><td>se crea con <code>INSERT</code> → estado <code>nueva</code> (trt/conductor quedan NULL)</td></tr>
                    <tr><td>Ref con fin_descargue</td><td>se ignora → estado <code>completa</code></td><td>se actualiza normal</td></tr>
                    <tr><td>titulo_viaje</td><td>solo si <b>no tenia</b> (estaba vacío)</td><td>reemplaza si viene distinto</td></tr>
                    <tr><td>ruta_id</td><td colspan="2">se reprocesa (SP <code>sp_actualizar_ruta_id</code>) <b>solo</b> si se escribió un título nuevo; el resultado lo dice <code>ruta_reprocesada</code></td></tr>
                    <tr><td>placa</td><td colspan="2">se <b>remplaza</b> siempre que venga data nueva (máx. 10 chars, se recorta antes de "/")</td></tr>
                    <tr><td>eventos (8 fechas)</td><td colspan="2">se <b>remplazan</b> siempre con los nuevos; vacío/null = no enviado</td></tr>
                    <tr><td>fecha_despachador</td><td colspan="2">obsoleta — se ignora siempre</td></tr>
                    </tbody>
                </table>
                <p class="text-secondary small">Nota: <code>ruta_reprocesada: false</code> solo avisa que el SP no pudo resolver la ruta (título sin origen/destino en <code>locales</code> o SP no instalado en ese entorno); los demás campos sí quedaron guardados.</p>

                <h5 class="text-light mt-4">Respuesta devuelta (200)</h5>
<pre class="docs-pre">{
  "success": true,
  "referencias_de_lotes": true,
  "resumen": {
    "recibidas": 120, "actualizadas": 100, "nuevas": 0,
    "completas": 5, "no_encontradas": 4, "sin_cambios": 8, "invalidas": 3
  },
  "resultados": [
    { "ref": "26100345", "estado": "actualizada",
      "campos": ["placa", "inicio_ruta", "qr_llegada_destino"],
      "ruta_reprocesada": false },
    { "ref": "26100401", "estado": "actualizada",
      "campos": ["titulo_viaje", "placa"],
      "ruta_reprocesada": true },
    { "ref": "26109999", "estado": "completa" },
    { "ref": "26108888", "estado": "no_encontrada" },
    { "ref": "26107777", "estado": "sin_cambios" },
    { "ref": "MALA-REF-XX", "estado": "invalida",
      "errores": ["ref: formato invalido (3-10 caracteres alfanumericos)"] },
    { "ref": "26106666", "estado": "invalida",
      "errores": ["inicio_ruta: fecha invalida \"31/02/2026 10:00\" (esperado dd/mm/aaaa hh:mm)"] }
  ]
}</pre>

                <h5 class="text-light">Estados posibles</h5>
                <table class="table table-sm table-bordered docs-tabla">
                    <thead><tr><th>estado</th><th>significado</th></tr></thead>
                    <tbody>
                    <tr><td><span class="badge bg-success">actualizada</span></td><td>UPDATE ejecutado; <code>campos</code> lista lo escrito</td></tr>
                    <tr><td><span class="badge bg-info text-dark">nueva</span></td><td>INSERT ejecutado (solo con referencias_de_lotes = false)</td></tr>
                    <tr><td><span class="badge bg-secondary">sin_cambios</span></td><td>no había nada nuevo que escribir</td></tr>
                    <tr><td><span class="badge bg-warning text-dark">completa</span></td><td>la ref ya tiene fin_descargue y el flag de lotes manda ignorarla</td></tr>
                    <tr><td><span class="badge bg-secondary">no_encontrada</span></td><td>la ref no existe en <code>referencias</code> y el flag de lotes prohíbe insertar</td></tr>
                    <tr><td><span class="badge bg-danger">invalida</span></td><td>data rechazada en la validación; ver <code>errores</code></td></tr>
                    </tbody>
                </table>

                <h5 class="text-light">Ejemplo de llamada desde la página</h5>
<pre class="docs-pre">const r = await fetch('{{ $url_post }}', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ referencias_de_lotes: true, refs: mapaDeRefs })
});
const json = await r.json();
console.log(json.resumen);</pre>

                <p class="text-secondary small mb-0">
                    Códigos de estado del envío completo: <b>200</b> proceso terminado (aunque haya refs inválidas en resultados),
                    <b>415</b> body no es JSON, <b>422</b> falta <code>refs</code>, viene vacía o supera el máximo.
                </p>
            </div>
        </div>

        {{-- ================================================================ --}}
        <div class="col-12">
            <div class="docs-card p-3">
                <h5 class="text-light">Flujo completo</h5>
                <ol class="text-light">
                    <li>En <a href="{{ route('lupita.refs.seleccion') }}" class="text-info">Actualizar Refs</a> se eligen los lotes por rango y se abre
                        <code>tceflexia.com/generalMilestonesRoute?api=lupita_lotes&lotes=ID1,ID2</code>.</li>
                    <li>La página pide <b>GET /api/refs?lotes=...</b> → recibe solo refs pendientes y únicas.</li>
                    <li>Con esa lista levanta la data fresca del despachador (milestones).</li>
                    <li>Envía <b>POST /api/refs/actualizar</b> con <code>referencias_de_lotes: true</code> → se escribe directo en <code>referencias</code>.</li>
                    <li>Para refs nuevas que no pertenecen a lotes (aumentar data), mismo POST con <code>referencias_de_lotes: false</code>.</li>
                    <li>La respuesta trae el resumen y el estado por ref para mostrarlo al usuario.</li>
                </ol>
            </div>
        </div>
    </div>

@endsection

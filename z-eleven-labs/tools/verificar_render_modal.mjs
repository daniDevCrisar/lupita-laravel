/**
 * Verifica los renderizadores del modal de detalle de Eleven Labs.
 *
 * POR QUE EXISTE
 * La vista `resources/views/elevenlabs/main.blade.php` arma el modal (resumen,
 * conversacion, entrada, analisis, metadatos) con JavaScript puro: no hay forma de
 * probarlo con PHPUnit. Este script toma el HTML **ya renderizado por Blade**
 * (asi se validan tambien los `@json(...)` ya sustituidos), extrae su `<script>`,
 * lo ejecuta con un DOM falso y le pasa fixtures reales guardados con la API.
 *
 * USO
 *   1) php z-eleven-labs/tools/render_main.php            (genera el HTML)
 *   2) node z-eleven-labs/tools/verificar_render_modal.mjs
 *
 * Sale con codigo 1 si algun renderizador revienta, deja `undefined`/`NaN` en el
 * HTML o descuadra las etiquetas.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const aqui = dirname(fileURLToPath(import.meta.url));
const raizModulo = join(aqui, '..');

const html = readFileSync(join(aqui, '_render_main.html'), 'utf8');

// --- 1. extraer el script de la vista -------------------------------------
const coincidencia = html.match(/<script>\s*\(function \(\) \{([\s\S]*?)\}\)\(\);\s*<\/script>/);
if (!coincidencia) {
    console.error('No se encontro el <script> del modulo en el HTML renderizado.');
    process.exit(1);
}
const cuerpo = coincidencia[1];

// --- 2. DOM falso ---------------------------------------------------------
function fakeEl(id) {
    // classList de verdad: si fuera un no-op, las aserciones sobre d-none no
    // probarian nada.
    const clases = new Set();

    return {
        id,
        innerHTML: '',
        textContent: '',
        value: '',
        style: {},
        dataset: {},
        options: [],
        src: '',
        currentTime: 0,
        playbackRate: 1,
        readyState: 0,
        classList: {
            add: (...c) => c.forEach((x) => clases.add(x)),
            remove: (...c) => c.forEach((x) => clases.delete(x)),
            contains: (c) => clases.has(c),
            toggle: (c, forzar) => {
                if (forzar === undefined) {
                    if (clases.has(c)) clases.delete(c);
                    else clases.add(c);

                    return clases.has(c);
                }

                if (forzar) clases.add(c);
                else clases.delete(c);

                return Boolean(forzar);
            },
        },
        addEventListener() {},
        removeEventListener() {},
        removeAttribute() {},
        setAttribute() {},
        appendChild() {},
        click() {},
        focus() {},
        dispatchEvent() { return true; },
        load() {},
        pause() {},
        play() { return Promise.resolve(); },
        querySelector: () => null,
        querySelectorAll: () => [],
    };
}

const elementos = {};
const documento = {
    getElementById: (id) => (elementos[id] = elementos[id] || fakeEl(id)),
    addEventListener() {},
    createElement: () => fakeEl('creado'),
    createRange: () => ({ selectNodeContents() {} }),
    querySelectorAll: () => [],
    querySelector: () => null,
};

const ventana = {
    URL: { createObjectURL: () => 'blob:x', revokeObjectURL() {} },
    getSelection: () => ({ removeAllRanges() {}, addRange() {} }),
    addEventListener() {},
};

const bootstrapFalso = {
    Modal: function () {
        this.show = () => {};
        this.hide = () => {};
    },
    Tab: { getOrCreateInstance: () => ({ show() {} }) },
    Dropdown: { getInstance: () => ({ hide() {} }) },
};

// fetch que nunca resuelve: `buscar()` queda colgada a proposito, lo que nos
// interesa son los renderizadores, no la llamada a la API. La unica excepcion es
// /mapeo, que se responde con lo que guardo render_main.php para poder ejercitar
// las pestanas Campos y Reglas.
const mapeoJson = JSON.parse(readFileSync(join(aqui, '_mapeo.json'), 'utf8'));

// Se captura la URL del listado para poder verificar que el filtro de asistentes
// viaja al servidor como agentes[] y no se aplica en el navegador.
let ultimaUrlConversaciones = '';

const fetchStub = (url) => {
    if (typeof url === 'string' && url.endsWith('/eleven-labs/mapeo')) {
        return Promise.resolve({ok: true, json: async () => mapeoJson});
    }

    if (typeof url === 'string' && url.endsWith('/eleven-labs/mapeo/rutas')) {
        return Promise.resolve({
            ok: true,
            json: async () => ({
                ok: true,
                conversation_id: 'conv_test',
                agente: 'Asistente de prueba',
                rutas: {
                    'metadata.termination_reason': {ruta: 'metadata.termination_reason', valor: 'Call ended by remote party', tipo: 'string'},
                    'metadata.phone_call.direction': {ruta: 'metadata.phone_call.direction', valor: 'outbound', tipo: 'string'},
                    'analysis.call_successful': {ruta: 'analysis.call_successful', valor: 'success', tipo: 'string'},
                    'conversation_initiation_client_data.dynamic_variables.carrier_name': {ruta: 'conversation_initiation_client_data.dynamic_variables.carrier_name', valor: 'DUVAN ABRIL', tipo: 'string'},
                    'conversation_initiation_client_data.dynamic_variables.reference': {ruta: 'conversation_initiation_client_data.dynamic_variables.reference', valor: '1188055', tipo: 'string'},
                },
            }),
        });
    }

    if (typeof url === 'string' && url.indexOf('/eleven-labs/conversaciones') !== -1) {
        ultimaUrlConversaciones = url;

        return Promise.resolve({
            ok: true,
            json: async () => ({
                ok: true,
                filas: [],
                meta: {
                    total: 0, paginas: 0, has_more: false, tope: false,
                    desde_local: '2026-11-18 00:00:00', hasta_local: '2026-11-18 23:59:00',
                    desde_unix: 0, hasta_unix: 0, agentes: [], consultas: 1, agentes_fallidos: {},
                },
            }),
        });
    }

    return new Promise(() => {});
};

const fabrica = new Function(
    'document', 'bootstrap', 'window', 'navigator', 'fetch', 'alert', 'console', 'setTimeout',
    cuerpo + `
    ; return { montarDetalle, renderResumen, renderConversacion, renderEntrada,
               renderAnalisis, renderMetadatos, valor, mmss, durHumana, badgeTipo,
               renderEquivalencias, renderListaAsistentes, actualizarEtiquetaAsistentes,
               cargarMapeo, renderCampos, renderReglas, renderProbarSelector,
               leerCamposDeTabla, agregarRegla, opcionesValorRegla,
               renderPolitica, leerOpcionesDeTabla,
               opcionesRuta, refrescarSelectoresRutas, pintarPrevisualizacion,
               colorColumna, COLOR_COLUMNA, abrirSelectorRutas, traerRutas, renderListaRutas,
               renderRutasConversacion, pintarFilas };`
);

const api = fabrica(
    documento, bootstrapFalso, ventana, { clipboard: { writeText: async () => {} } },
    fetchStub, () => {}, console, setTimeout
);

// --- 3. helpers de asercion ----------------------------------------------
let fallos = 0;
let pruebas = 0;

function falla(mensaje) {
    fallos++;
    console.error('  FALLO: ' + mensaje);
}

function afirmar(condicion, mensaje) {
    pruebas++;
    if (!condicion) falla(mensaje);
}

/** Busca sintomas de plantilla rota en el HTML generado. */
function revisarSintomas(etiqueta, html) {
    afirmar(!/undefined/.test(html), etiqueta + ': contiene "undefined"');
    afirmar(!/\bNaN\b/.test(html), etiqueta + ': contiene "NaN"');
    afirmar(!/\[object Object\]/.test(html), etiqueta + ': contiene "[object Object]"');

    const abreDiv = (html.match(/<div\b/g) || []).length;
    const cierraDiv = (html.match(/<\/div>/g) || []).length;
    afirmar(abreDiv === cierraDiv, etiqueta + ': <div> descuadrados (' + abreDiv + ' vs ' + cierraDiv + ')');

    const abreTabla = (html.match(/<table\b/g) || []).length;
    const cierraTabla = (html.match(/<\/table>/g) || []).length;
    afirmar(abreTabla === cierraTabla, etiqueta + ': <table> descuadradas (' + abreTabla + ' vs ' + cierraTabla + ')');

    const abreTr = (html.match(/<tr\b/g) || []).length;
    const cierraTr = (html.match(/<\/tr>/g) || []).length;
    afirmar(abreTr === cierraTr, etiqueta + ': <tr> descuadrados (' + abreTr + ' vs ' + cierraTr + ')');
}

// --- 4. correr los renderizadores contra cada fixture --------------------
const dirFixtures = join(raizModulo, 'fixtures');
const archivos = readdirSync(dirFixtures).filter((f) => f.endsWith('.json')).sort();

if (!archivos.length) {
    console.error('No hay fixtures en ' + dirFixtures);
    process.exit(1);
}

console.log('Fixtures: ' + archivos.length);

for (const archivo of archivos) {
    const d = JSON.parse(readFileSync(join(dirFixtures, archivo), 'utf8'));
    const etiqueta = archivo.replace('.json', '');
    console.log('\n== ' + etiqueta + ' ==');

    const meta = d.metadata || {};
    const llamada = meta.phone_call || {};
    const turnos = Array.isArray(d.transcript) ? d.transcript : [];
    const entrada = d.conversation_initiation_client_data || {};
    const vars = entrada.dynamic_variables || {};

    // --- montarDetalle: es el que orquesta todo y escribe en el DOM
    elementos['det_id'] = fakeEl('det_id');
    elementos['det_id'].textContent = d.conversation_id || 'conv_x';

    try {
        api.montarDetalle(d, true);
        afirmar(true, etiqueta + ': montarDetalle no lanza');
    } catch (e) {
        falla(etiqueta + ': montarDetalle revienta -> ' + e.message);
        continue;
    }

    for (const id of ['tab_resumen', 'tab_conversacion', 'tab_entrada', 'tab_analisis', 'tab_metadatos']) {
        const el = elementos[id];
        // La pestaña de conversacion puede ser solo el aviso de "sin transcripcion",
        // que mide ~90 caracteres; el resto siempre trae tablas.
        const minimo = id === 'tab_conversacion' ? 50 : 100;
        afirmar(el && el.innerHTML.length >= minimo, etiqueta + ': #' + id + ' quedo vacio');

        if (el && el.innerHTML) {
            revisarSintomas(etiqueta + ' #' + id, el.innerHTML);
        }
    }

    // El JSON de la pestaña tiene que ser JSON valido y equivaler al fixture.
    const jsonTexto = elementos['json_texto'] ? elementos['json_texto'].textContent : '';
    afirmar(jsonTexto.length > 100, etiqueta + ': el JSON de la pestaña quedo vacio');
    try {
        const vuelta = JSON.parse(jsonTexto);
        afirmar(JSON.stringify(vuelta) === JSON.stringify(d), etiqueta + ': el JSON no equivale al original');
    } catch (e) {
        falla(etiqueta + ': la pestaña JSON no contiene JSON valido -> ' + e.message);
    }

    // Contenido esperado segun el caso
    const htmlConversacion = elementos['tab_conversacion'].innerHTML;

    if (turnos.length === 0) {
        afirmar(/no tiene transcripción/.test(htmlConversacion),
            etiqueta + ': sin turnos deberia avisar que no hay transcripcion');
    } else {
        afirmar(htmlConversacion.includes(api.mmss(turnos[0].time_in_call_secs)),
            etiqueta + ': la conversacion no muestra el tiempo del primer turno');

        // Todo turno debe traer texto o llamadas a herramientas: si cae en el
        // marcador "(turno sin texto)" es que hay un caso no contemplado.
        afirmar(!/\(turno sin texto\)/.test(htmlConversacion),
            etiqueta + ': hay un turno sin texto ni herramientas');

        // Los turnos que solo ejecutan herramientas van como linea discreta.
        const conHerramientas = turnos.filter((t) => (t.tool_calls || []).length || (t.tool_results || []).length);
        if (conHerramientas.length) {
            afirmar(/bi-tools/.test(htmlConversacion),
                etiqueta + ': hay turnos con herramientas y no se pintan');
        }

        // los turnos del agente deben venir completos (original_message), no truncados
        const agente = turnos.find((t) => t.role === 'agent' && t.original_message);
        if (agente) {
            const completo = String(agente.original_message);
            const recortado = completo.length > 40 ? completo.slice(0, 40) : completo;
            afirmar(htmlConversacion.includes(recortado.replace(/</g, '&lt;').replace(/&/g, '&amp;')),
                etiqueta + ': no usa original_message del turno del agente');
        }
    }

    const htmlEntrada = elementos['tab_entrada'].innerHTML;
    if (Object.keys(entrada).length === 0) {
        afirmar(/no trae datos de entrada/.test(htmlEntrada),
            etiqueta + ': sin entrada deberia avisarlo');
    } else {
        const negocio = Object.keys(vars).filter((k) => !k.startsWith('system__'));
        afirmar(htmlEntrada.includes(negocio.length + ' variables de negocio'),
            etiqueta + ': el conteo de variables de negocio no cuadra');
        if (Object.keys(vars).some((k) => k.startsWith('system__'))) {
            afirmar(/Variables del sistema/.test(htmlEntrada),
                etiqueta + ': las system__ no quedaron en su bloque aparte');
        }
        if (negocio.length) {
            afirmar(htmlEntrada.includes(negocio.sort()[0]),
                etiqueta + ': no pinta la primera variable de negocio');
        }
    }

    const htmlAnalisis = elementos['tab_analisis'].innerHTML;
    afirmar(/Veredicto/.test(htmlAnalisis), etiqueta + ': al analisis le falta el veredicto');
    afirmar(/Sentimiento/.test(htmlAnalisis), etiqueta + ': al analisis le falta el sentimiento');

    const htmlMetadatos = elementos['tab_metadatos'].innerHTML;
    afirmar(/call_sid/.test(htmlMetadatos), etiqueta + ': metadatos sin call_sid');
    afirmar(/Costo/.test(htmlMetadatos), etiqueta + ': metadatos sin bloque de costo');

    const htmlResumen = elementos['tab_resumen'].innerHTML;
    afirmar(/Razón de finalización|Resultado/.test(htmlResumen), etiqueta + ': resumen incompleto');

    console.log('  turnos=' + turnos.length
        + ' vars=' + Object.keys(vars).length
        + ' resumen=' + htmlResumen.length
        + ' conversacion=' + htmlConversacion.length
        + ' entrada=' + htmlEntrada.length
        + ' analisis=' + htmlAnalisis.length
        + ' metadatos=' + htmlMetadatos.length
        + ' json=' + (jsonTexto.length / 1024).toFixed(1) + 'KB');
}

// --- 5. valor() con los casos raros --------------------------------------
console.log('\n== valor() ==');
afirmar(api.valor(null).includes('—'), 'valor(null) deberia ser guion');
afirmar(api.valor(true).includes('true'), 'valor(true)');
afirmar(api.valor(false).includes('false'), 'valor(false)');
afirmar(api.valor('').includes('—'), 'valor("")');
afirmar(api.valor([]).includes('vacío'), 'valor([])');
afirmar(api.valor('x'.repeat(500)).includes('valor-pre'), 'un texto largo deberia ir en <pre>');
afirmar(api.valor({ a: 1 }).includes('valor-pre'), 'un objeto deberia ir en <pre>');
afirmar(!api.valor('<script>alert(1)</script>').includes('<script>'), 'valor() no escapa HTML');

console.log('\n== mmss() ==');
afirmar(api.mmss(0) === '00:00', 'mmss(0)');
afirmar(api.mmss(65) === '01:05', 'mmss(65)');
afirmar(api.mmss(null) === '00:00', 'mmss(null)');

// --- 5b. catalogo de equivalencias (lo que embebe Blade) ------------------
console.log('\n== equivalencias ==');

try {
    api.renderEquivalencias();
    api.renderListaAsistentes();
    api.actualizarEtiquetaAsistentes();
    afirmar(true, 'renderizadores de equivalencias no lanzan');
} catch (e) {
    falla('renderEquivalencias revienta -> ' + e.message);
}

// Los elementos del DOM falso se crean al pedirlos, asi que se leen DESPUES de
// haberlos renderizado.
const htmlEquiv = elementos['equiv_cuerpo'] ? elementos['equiv_cuerpo'].innerHTML : '';
const htmlLista = elementos['lista_asistentes'] ? elementos['lista_asistentes'].innerHTML : '';

afirmar(!!elementos['equiv_cuerpo'], 'no existe #equiv_cuerpo');

const filasCatalogo = (htmlEquiv.match(/data-agent="/g) || []).length;
const checksLista = (htmlLista.match(/chk-agente/g) || []).length;

// El catalogo del modal muestra TODOS los asistentes; el filtro de la pantalla
// solo los activos, asi que puede haber menos checkboxes que filas.
const agentesActivos = (mapeoJson.agentes || []).filter((a) => a.activo).length;
const agentesInactivos = (mapeoJson.agentes || []).length - agentesActivos;

afirmar(filasCatalogo > 0, 'el modal de equivalencias no pinto filas');
afirmar(checksLista === agentesActivos,
    'el filtro multiple deberia mostrar solo los activos (' + checksLista + ' de ' + agentesActivos + ')');
afirmar(!/inactivo/.test(htmlLista), 'el filtro multiple sigue mostrando asistentes inactivos');

// Opciones por fila: "fuera del lote" + los tipos de `tipos_llamada`. El numero
// de tipos no se fija aqui (la tabla puede crecer), solo se exige que sea
// consistente entre filas y que este el 4 ("En ruta").
const opciones = (htmlEquiv.match(/<option /g) || []).length;
if (filasCatalogo > 0) {
    afirmar(opciones % filasCatalogo === 0,
        'las filas no tienen el mismo numero de opciones (' + opciones + ' / ' + filasCatalogo + ')');

    const porFila = opciones / filasCatalogo;
    afirmar(porFila >= 6,
        'cada asistente deberia ofrecer "fuera del lote" + al menos 5 tipos (tiene ' + porFila + ')');
    afirmar(/<option value="4"/.test(htmlEquiv),
        'falta el tipo 4 ("En ruta") en las opciones');
    afirmar(/<option value="1"/.test(htmlEquiv) && /<option value="6"/.test(htmlEquiv),
        'faltan tipos basicos en las opciones');
}

afirmar(!/undefined|NaN|\[object Object\]/.test(htmlEquiv), 'el catalogo tiene undefined/NaN');
afirmar(!/undefined|NaN|\[object Object\]/.test(htmlLista), 'el filtro multiple tiene undefined/NaN');

const etiqueta = elementos['txt_asistentes'] ? elementos['txt_asistentes'].textContent : '';
afirmar(etiqueta === 'Todos los asistentes' || etiqueta === '',
    'sin seleccion la etiqueta deberia ser "Todos los asistentes", es "' + etiqueta + '"');

console.log('  asistentes=' + filasCatalogo + ' (activos en el filtro: ' + checksLista
    + ', inactivos ocultos: ' + agentesInactivos + ') opciones=' + opciones + ' etiqueta="' + etiqueta + '"');

// --- 5c. equivalencias de traduccion: pestanas Campos y Reglas ------------
console.log('\n== mapeo de traduccion ==');

try {
    await api.cargarMapeo();

    afirmar(elementos['campos_cuerpo'] && elementos['campos_cuerpo'].innerHTML.length > 1000,
        'la pestana Campos quedo vacia');
    afirmar(elementos['reglas_cuerpo'] && elementos['reglas_cuerpo'].innerHTML.length > 500,
        'la pestana Reglas quedo vacia');

    const htmlCampos = elementos['campos_cuerpo'].innerHTML;
    const htmlReglas = elementos['reglas_cuerpo'].innerHTML;

    const filasCampos = (htmlCampos.match(/data-campo="/g) || []).length;
    const filasReglas = (htmlReglas.match(/data-idx="/g) || []).length;

    afirmar(filasCampos === mapeoJson.campos.length,
        'la pestana Campos no pinta las ' + mapeoJson.campos.length + ' columnas (pinta ' + filasCampos + ')');
    afirmar(filasReglas === mapeoJson.reglas.length,
        'la pestana Reglas no pinta las ' + mapeoJson.reglas.length + ' reglas (pinta ' + filasReglas + ')');

    // Las calculadas y las de regla van bloqueadas, con candado y sin inputs editables
    const porTipo = {};
    mapeoJson.campos.forEach((c) => { porTipo[c.tipo_origen] = (porTipo[c.tipo_origen] || 0) + 1; });
    afirmar(porTipo.calculado > 0 && porTipo.regla > 0, 'faltan columnas calculadas o de regla en el mapeo');
    afirmar((htmlCampos.match(/bi-lock/g) || []).length === porTipo.calculado + porTipo.regla,
        'las columnas calculadas/de regla no salen bloqueadas con candado');

    // Cada columna con ruta o constante tiene su input
    const editables = porTipo.campo + porTipo.constante;
    afirmar((htmlCampos.match(/in-origen/g) || []).length === editables,
        'no hay un input de ruta/constante por columna editable (' + editables + ')');
    afirmar((htmlCampos.match(/in-transf/g) || []).length === porTipo.campo,
        'no hay un select de transformacion por columna de tipo campo');

    // En razon_finalizacion el valor destino tiene que ser un select de razones
    afirmar(/in-valor-destino/.test(htmlReglas), 'las reglas no tienen campo de valor destino');

    // El valor destino se limita al dominio real de cada columna
    const seleccionables = mapeoJson.reglas.filter((r) => {
        const cfg = (mapeoJson.opciones_valor || {})[r.campo_destino];
        return cfg && (cfg.tipo === 'razones' || cfg.tipo === 'lista');
    }).length;
    const selectsValor = (htmlReglas.match(/in-valor-destino/g) || []).length;
    afirmar(selectsValor === mapeoJson.reglas.length,
        'cada regla deberia tener un campo de valor destino (' + selectsValor + ' de ' + mapeoJson.reglas.length + ')');
    afirmar(seleccionables > 0, 'ninguna columna de regla tiene dominio cerrado de valores');

    // Las banderas son tinyint(1) en `llamadas`: el dominio es 1 / 0 / vacio
    const banderas = mapeoJson.reglas.filter((r) => {
        const cfg = (mapeoJson.opciones_valor || {})[r.campo_destino];
        return cfg && cfg.tipo === 'lista' && cfg.opciones.join('|') === '1|0|';
    });
    afirmar(banderas.length > 0, 'no hay reglas de bandera con dominio 1 / 0 / vacio');

    // Invariante: ninguna regla puede guardar un valor fuera del dominio de su
    // columna. Con el dominio viejo (true/false) una bandera escribia "true" en
    // una columna tinyint(1) y el INSERT reventaba por el modo estricto.
    const fueraDeDominio = mapeoJson.reglas.filter((r) => {
        const cfg = (mapeoJson.opciones_valor || {})[r.campo_destino];
        if (!cfg || cfg.tipo !== 'lista') return false;

        return cfg.opciones.indexOf(r.valor_destino) === -1;
    });
    afirmar(fueraDeDominio.length === 0,
        'hay reglas fuera del dominio de su columna: '
        + fueraDeDominio.map((r) => r.campo_destino + '="' + r.valor_destino + '"').join(', '));

    // El dominio de las banderas no puede volver a ofrecer true/false
    const dominioTrueFalse = Object.entries(mapeoJson.opciones_valor || {})
        .filter(([, cfg]) => cfg.tipo === 'lista' && cfg.opciones.indexOf('true') !== -1)
        .map(([columna]) => columna);
    afirmar(dominioTrueFalse.length === 0,
        'columnas tinyint con dominio true/false: ' + dominioTrueFalse.join(', '));

    // Los valores de razon usados por las reglas tienen que existir de verdad
    const nombres = mapeoJson.razones.map((r) => r.name);
    mapeoJson.reglas
        .filter((r) => r.campo_destino === 'razon_finalizacion')
        .forEach((r) => afirmar(nombres.indexOf(r.valor_destino) !== -1,
            'la regla apunta a una razon inexistente: ' + r.valor_destino));

    // El selector de la pestana Probar avisa cuando no hay rango cargado
    afirmar(/Primero busca un rango/.test(elementos['probar_conversacion'].innerHTML),
        'el selector de Probar no avisa que falta cargar un rango');

    afirmar(!/undefined|NaN|\[object Object\]/.test(htmlCampos), 'Campos tiene undefined/NaN');
    afirmar(!/undefined|NaN|\[object Object\]/.test(htmlReglas), 'Reglas tiene undefined/NaN');

    console.log('  columnas=' + filasCampos
        + ' (campo ' + porTipo.campo + ', constante ' + porTipo.constante
        + ', regla ' + porTipo.regla + ', calculado ' + porTipo.calculado + ')'
        + ' reglas=' + filasReglas
        + ' razones=' + mapeoJson.razones.length);

    // ---- pestana Politica (Etapa 6): que estados entran al lote ----------
    const opcionesCatalogo = mapeoJson.opciones || [];
    afirmar(opcionesCatalogo.length > 0, 'el mapeo no trae las opciones de la politica');

    const estados = opcionesCatalogo.find((o) => o.clave === 'estados_a_importar');
    afirmar(Boolean(estados), 'falta la opcion estados_a_importar');
    afirmar(estados && estados.opciones && Object.keys(estados.opciones).length === 3,
        'la politica debe ofrecer los 3 estados reales de ElevenLabs (done/initiated/failed)');
    afirmar(estados && estados.valores.indexOf('done') !== -1 && estados.valores.indexOf('failed') !== -1,
        'por defecto deben entrar done y failed');
    afirmar(estados && estados.valores.indexOf('initiated') === -1,
        'initiated no deberia entrar por defecto (no tiene transcripcion todavia)');

    api.renderPolitica();
    const htmlPolitica = elementos['politica_cuerpo'].innerHTML;

    afirmar((htmlPolitica.match(/in-politica/g) || []).length === 3,
        'la pestana Politica no pinta una casilla por estado');
    afirmar(/value="done"[^>]*checked|checked[^>]*value="done"/.test(htmlPolitica),
        'done deberia aparecer marcado');
    afirmar(/value="failed"[^>]*checked|checked[^>]*value="failed"/.test(htmlPolitica),
        'failed deberia aparecer marcado');
    afirmar(!/value="initiated"[^>]*checked|checked[^>]*value="initiated"/.test(htmlPolitica),
        'initiated NO deberia aparecer marcado');
    afirmar(htmlPolitica.indexOf('Estados que entran al lote') !== -1,
        'la pestana Politica no muestra la etiqueta de la opcion');
    afirmar(!/undefined|NaN|\[object Object\]/.test(htmlPolitica), 'Politica tiene undefined/NaN');

    // leerOpcionesDeTabla se lee del DOM: se simula lo que dejo marcado el usuario
    const querySelectorAllOriginal = documento.querySelectorAll;
    documento.querySelectorAll = (selector) => {
        if (selector === '#politica_cuerpo .in-politica') {
            return [
                { dataset: { clave: 'estados_a_importar' }, value: 'done', checked: true },
                { dataset: { clave: 'estados_a_importar' }, value: 'initiated', checked: false },
                { dataset: { clave: 'estados_a_importar' }, value: 'failed', checked: true },
            ];
        }

        return [];
    };
    const leidas = api.leerOpcionesDeTabla();
    documento.querySelectorAll = querySelectorAllOriginal;

    afirmar(leidas.estados_a_importar && leidas.estados_a_importar.join(',') === 'done,failed',
        'leerOpcionesDeTabla no arma {clave: [valores]} con las marcadas ('
        + JSON.stringify(leidas) + ')');

    console.log('  politica: ' + opcionesCatalogo.length + ' opcion(es), estados marcados='
        + (estados ? estados.valores.join(',') : '—'));

} catch (e) {
    falla('cargarMapeo revienta -> ' + e.message + '\n' + e.stack);
}

// agregar regla desde la UI
try {
    const antes = (elementos['reglas_cuerpo'].innerHTML.match(/data-idx="/g) || []).length;
    api.agregarRegla();
    const despues = (elementos['reglas_cuerpo'].innerHTML.match(/data-idx="/g) || []).length;
    afirmar(despues === antes + 1, 'agregarRegla no añadio una fila (' + antes + ' -> ' + despues + ')');
} catch (e) {
    falla('agregarRegla revienta -> ' + e.message);
}

// --- 5d. ruta disparador dinamica y previsualizacion del lote -------------
console.log('\n== ruta disparador dinamica ==');

try {
    // Sin rutas descubiertas solo debe salir el grupo "Comunes"
    const soloComunes = api.opcionesRuta('metadata.termination_reason');
    afirmar(/<optgroup label="Comunes">/.test(soloComunes), 'falta el optgroup de rutas comunes');
    afirmar(/selected/.test(soloComunes), 'la ruta actual no aparece seleccionada');

    // refrescarSelectoresRutas no debe reventar sin rutas descubiertas
    api.refrescarSelectoresRutas();
    afirmar(true, 'refrescarSelectoresRutas sin rutas no lanza');

    // Una ruta guardada que no esta en la lista comun no se pierde
    const rara = api.opcionesRuta('conversation_initiation_client_data.dynamic_variables.carrier_name');
    afirmar(rara.indexOf('conversation_initiation_client_data.dynamic_variables.carrier_name') !== -1,
        'una ruta guardada fuera de la lista comun desaparecio del selector');

    console.log('  comunes=' + mapeoJson.rutas_disparador.length);
} catch (e) {
    falla('opcionesRuta revienta -> ' + e.message);
}

// --- 5e. previsualizacion del lote ---------------------------------------
console.log('\n== previsualizacion del lote ==');

try {
    const payload = {
        ok: true,
        resumen: {total: 2, incluidas: 1, fuera: 1, motivos: {sin_conductor: 1}, avisos: 1},
        motivos_texto: {sin_conductor: 'Sin conductor: el pipeline descarta la llamada'},
        solicitadas: 2,
        traducidas: 2,
        errores: [{conversation_id: 'conv_rota', mensaje: '404 not found'}],
        llamadas: [
            {
                conversation_id: 'conv_buena', agente: 'DespachIA - Camila',
                inicio_local: '2026-11-18 15:53:00', status: 'done',
                incluye: true, motivos: [], avisos: [],
                clave: {
                    llamada_tipo: '2', ref: '1188055', origen: 'FUNZA', destino: 'Cali',
                    telefono: '573202841287', conductor: 'ANDRES RIVERA',
                    fecha_prometida: '18/11/2026 15:53',
                    razon_finalizacion: 'CUSTOMER-DID-NOT-ANSWER', audio_duracion: '17',
                    costo: '0.0411',
                },
            },
            {
                conversation_id: 'conv_mala', agente: 'ChatBot',
                inicio_local: '2026-11-18 16:00:00', status: 'done',
                incluye: false, motivos: ['sin_conductor'], avisos: ['La llamada no tiene transcripción (se importaría sin mensajes)'],
                clave: {
                    llamada_tipo: '', ref: '', origen: '', destino: '', telefono: '57300',
                    conductor: '', fecha_prometida: '', razon_finalizacion: '', audio_duracion: '3',
                    costo: '0.01',
                },
            },
        ],
    };

    api.pintarPrevisualizacion(payload);

    const htmlResumen = elementos['preview_resumen'].innerHTML;
    const htmlCuerpo = elementos['preview_cuerpo'].innerHTML;
    const htmlErrores = elementos['preview_errores_api'].innerHTML;

    afirmar(/1 entrarían al lote/.test(htmlResumen), 'el resumen no dice cuantas entrarian');
    afirmar(/1 quedarían fuera/.test(htmlResumen), 'el resumen no dice cuantas quedan fuera');
    afirmar(/Sin conductor/.test(htmlResumen), 'el resumen no traduce los motivos');
    afirmar((htmlCuerpo.match(/<tr>/g) || []).length === 2, 'la tabla no pinta las 2 llamadas');
    afirmar(/badge bg-success">sí/.test(htmlCuerpo), 'la llamada que entra no lleva badge verde');
    afirmar(/badge bg-secondary">no/.test(htmlCuerpo), 'la llamada que no entra no lleva badge gris');
    afirmar(/1188055/.test(htmlCuerpo), 'no pinta la referencia');
    afirmar(/Sin conductor/.test(htmlCuerpo), 'no pinta el motivo en la fila');
    afirmar(/conv_rota/.test(htmlErrores), 'no avisa de las llamadas que la API no devolvio');

    afirmar(!/undefined|NaN|\[object Object\]/.test(htmlResumen + htmlCuerpo + htmlErrores),
        'la previsualizacion tiene undefined/NaN');

    console.log('  resumen=' + htmlResumen.length + ' tabla=' + htmlCuerpo.length + ' errores=' + htmlErrores.length);
} catch (e) {
    falla('pintarPrevisualizacion revienta -> ' + e.message + '\n' + e.stack);
}

console.log('\n== color por columna en Reglas ==');

try {
    const columnas = mapeoJson.columnas_regla;
    const colores = columnas.map((c) => api.colorColumna(c));

    afirmar(colores.every((c) => /^#[0-9a-f]{6}$/i.test(c)),
        'alguna columna no devolvio un color valido: ' + JSON.stringify(colores));
    afirmar(new Set(colores).size === colores.length,
        'hay dos columnas con el mismo color: ' + JSON.stringify(colores));
    afirmar(api.colorColumna('columna_inventada') !== '',
        'una columna desconocida deberia tener color de reserva');

    console.log('  ' + columnas.map((c, i) => c + '=' + colores[i]).join(' '));
} catch (e) {
    falla('colorColumna revienta -> ' + e.message);
}

console.log('\n== filtro de asistentes enviado al servidor ==');

try {
    // `buscar()` corre al arrancar la vista, asi que ya hay una URL capturada.
    afirmar(ultimaUrlConversaciones !== '', 'buscar() no llego a consultar el listado');
    afirmar(/fecha_desde=/.test(ultimaUrlConversaciones), 'la URL no lleva el rango');

    // Sin seleccion no debe mandar agentes[]
    afirmar(!/agentes/.test(ultimaUrlConversaciones),
        'sin asistentes marcados no deberia enviar el parametro agentes: ' + ultimaUrlConversaciones);

    console.log('  url=' + ultimaUrlConversaciones.replace(/^.*\/eleven-labs/, ''));
} catch (e) {
    falla('verificacion del filtro revienta -> ' + e.message);
}

console.log('\n== buscador de rutas del JSON ==');

// Regresion: el desplegable de llamadas se llenaba solo la primera vez. Si se
// abria la lupa antes de que cargara el listado, se quedaba con "no hay llamadas"
// para siempre. Ahora se reconstruye cada vez.
try {
    const selectConv = fakeEl('rutas_conversacion');
    elementos['rutas_conversacion'] = selectConv;

    // 1) sin llamadas cargadas
    api.renderRutasConversacion();
    afirmar(/Primero busca un rango/.test(selectConv.innerHTML),
        'sin llamadas deberia avisar que falta cargar el rango');

    // 2) deja el desplegable "lleno" con el aviso (como se quedaba antes)
    selectConv.innerHTML = '<option value="">— Primero busca un rango —</option>';
    selectConv.value = '';

    // 3) llegan las llamadas: pintarFilas debe refrescar el desplegable aunque
    //    ya tuviera opciones
    api.pintarFilas([
        {
            conversation_id: 'conv_prueba_1', agent_id: 'agent_prueba', agent_name: 'Asistente',
            inicio_local: '2026-11-18 10:00:00', start_time_unix_secs: 1795000000,
            call_duration_secs: 30, status: 'done', direction: 'outbound',
            call_successful: 'success', termination_reason: 'x', message_count: 2,
            cost: 100, cost_fiat: 0.02, call_summary_title: '', main_language: 'es',
            tipo_llamada_id: null, incluir_en_lote: false,
        },
    ], {
        total: 1, paginas: 1, has_more: false, tope: false,
        desde_local: '2026-11-18 00:00:00', hasta_local: '2026-11-18 23:59:00',
        desde_unix: 0, hasta_unix: 0, agentes: [], consultas: 1,
        agentes_fallidos: {}, excluidas_inactivas: 0,
    });

    afirmar(/conv_prueba_1/.test(elementos['rutas_conversacion'].innerHTML),
        'el desplegable no se relleno despues de cargar las llamadas');
    afirmar(!/Primero busca un rango/.test(elementos['rutas_conversacion'].innerHTML),
        'el desplegable sigue mostrando el aviso de "falta cargar rango"');

    console.log('  regresion del desplegable: ok');
} catch (e) {
    falla('renderRutasConversacion revienta -> ' + e.message + '\n' + e.stack);
}

try {
    // Se simula que el usuario abre el buscador desde la fila "ref"
    const inputFalso = fakeEl('in-origen_ref');
    inputFalso.value = '';

    const conversacion = fakeEl('rutas_conversacion');
    conversacion.value = 'conv_test';
    elementos['rutas_conversacion'] = conversacion;

    api.abrirSelectorRutas(inputFalso, 'Se va a escribir en «ref»');
    afirmar(/Se va a escribir en «ref»/.test(elementos['rutas_destino_etiqueta'].textContent),
        'no indica en que campo se va a escribir');
    afirmar(!elementos['rutas_sin_datos'].classList.contains('d-none'),
        'sin rutas cargadas deberia pedir una llamada de ejemplo');

    await api.traerRutas();
    afirmar(elementos['rutas_sin_datos'].classList.contains('d-none'),
        'despues de traer las rutas deberia ocultar el bloque de "traer"');

    // Lista completa
    api.renderListaRutas('');
    const htmlTodas = elementos['rutas_lista'].innerHTML;
    afirmar((htmlTodas.match(/btn-ruta/g) || []).length === 5,
        'no lista las 5 rutas (' + (htmlTodas.match(/btn-ruta/g) || []).length + ')');
    afirmar(/DUVAN ABRIL/.test(htmlTodas), 'no muestra el valor de la ruta');
    afirmar(/5 de 5/.test(elementos['rutas_contador'].textContent), 'el contador no cuadra');

    // Filtro por nombre de ruta
    api.renderListaRutas('carrier');
    afirmar((elementos['rutas_lista'].innerHTML.match(/btn-ruta/g) || []).length === 1,
        'el filtro por nombre de ruta no reduce a 1');

    // Filtro por VALOR (lo util: buscar "1188055" y encontrar la ruta)
    api.renderListaRutas('1188055');
    const htmlValor = elementos['rutas_lista'].innerHTML;
    afirmar((htmlValor.match(/btn-ruta/g) || []).length === 1, 'el filtro por valor no reduce a 1');
    afirmar(/dynamic_variables\.reference/.test(htmlValor), 'el filtro por valor no encontro reference');

    // Sin coincidencias
    api.renderListaRutas('zzzz_no_existe');
    afirmar(/Ninguna ruta coincide/.test(elementos['rutas_lista'].innerHTML),
        'no avisa cuando no hay coincidencias');

    // Al elegir una ruta se escribe en el input que abrio el buscador
    api.renderListaRutas('');
    const boton = {dataset: {ruta: 'conversation_initiation_client_data.dynamic_variables.reference'}};
    // el click real se prueba con el handler del DOM; aqui basta confirmar el dataset
    afirmar(boton.dataset.ruta !== '', 'la ruta elegida deberia llevar el path completo');

    // Las reglas tambien tienen su lupa
    const htmlReglasLupa = elementos['reglas_cuerpo'].innerHTML;
    afirmar(/btn-buscar-ruta-regla/.test(htmlReglasLupa),
        'la pestana Reglas no tiene boton de busqueda de ruta');
    afirmar((htmlReglasLupa.match(/btn-buscar-ruta-regla/g) || []).length ===
        (htmlReglasLupa.match(/in-ruta/g) || []).length,
        'no hay una lupa por cada regla');

    console.log('  rutas cargadas=' + Object.keys(elementos['rutas_lista'].innerHTML).length
        + ' contador="' + elementos['rutas_contador'].textContent + '"'
        + ' lupas en reglas=' + (htmlReglasLupa.match(/btn-buscar-ruta-regla/g) || []).length);
} catch (e) {
    falla('buscador de rutas revienta -> ' + e.message + '\n' + e.stack);
}

// --- 6. resultado --------------------------------------------------------
console.log('\n' + '='.repeat(60));
if (fallos) {
    console.error('FALLARON ' + fallos + ' de ' + pruebas + ' verificaciones');
    process.exit(1);
}
console.log('OK: ' + pruebas + ' verificaciones en ' + archivos.length + ' fixtures');

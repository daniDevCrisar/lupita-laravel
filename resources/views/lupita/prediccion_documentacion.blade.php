@extends('layouts.app')

@section('title', 'Documentación del API de predicción de carga')

@php
    $ejemplo = <<<'JSON'
{
  "success": true,
  "ref": "1182661",
  "ahora": "2026-11-11 09:00:00",
  "placa": "C0E912",
  "ruta_nombre": "PLANTA CARICUA → PLANTA CHICLAYO",
  "planta_carga_nombre": "PLANTA CHICLAYO (Chiclayo, Chiclayo, Lambayeque)",
  "presenta_rapido": "2026-11-14 21:33:00",
  "presenta": "2026-11-15 09:27:00",
  "presenta_tardado": "2026-11-16 18:44:00",
  "presenta_probable": "2026-11-15 12:41:00",
  "presenta_consenso": "2026-11-15 06:34:00",
  "sesgo": { "clase": "parcial|largo", "min": -194, "aplicado": true },
  "presentaciones": [
    { "clave": "rapido",   "etiqueta": "Rápido",        "fecha": "2026-11-14 21:33:00", "desde_ahora": "60:33",
      "delta_normal_min": -714, "construccion": "normal − 11:54 · banda medida (cubre 70 %)" },
    { "clave": "consenso", "etiqueta": "Consenso",      "fecha": "2026-11-15 06:34:00", "desde_ahora": "93:34",
      "delta_normal_min": -173, "construccion": "promedio de rápido (60:33), normal (96:27) y base ajustada (99:41) desde el corte = 93:34 · normal − 2:53" },
    { "clave": "normal",   "etiqueta": "Normal",        "fecha": "2026-11-15 09:27:00", "desde_ahora": "96:27",
      "delta_normal_min": 0, "construccion": "la suma de los pasos de abajo (ancla + traslado + descansos + esperas)" },
    { "clave": "probable", "etiqueta": "Base ajustada", "fecha": "2026-11-15 12:41:00", "desde_ahora": "99:41",
      "delta_normal_min": 194, "construccion": "normal + 3:14 (sesgo de la clase parcial|largo: -194 min)" },
    { "clave": "tardado",  "etiqueta": "Tardado",       "fecha": "2026-11-16 18:44:00", "desde_ahora": "129:44",
      "delta_normal_min": 1997, "construccion": "normal + 33:17 · banda medida (cubre 70 %)" }
  ],
  "inicio_carga": "2026-11-15 11:16:00",
  "espera_hhmm": "1:49",
  "duracion_total_hhmm": "96:27",
  "evidencia": "parcial",
  "confianza": 35,
  "confianza_motivo": "dispersión alta (IQR 4755 min), horizonte de más de 24 h",
  "ya_presento": false,
  "atrasado": false,
  "estado": { "clave": "regresando", "etiqueta": "Libre: vuelve a cargar", "motivo": "último hito: fin_descargue" },
  "pasos": [
    { "n": 1, "concepto": "ancla",   "titulo": "Punto de partida", "duracion": "0:00", "detalle": "Viaje 1180352 · fin de descargue del 11/11 09:00" },
    { "n": 2, "concepto": "traslado", "titulo": "Regreso hasta PLANTA CHICLAYO (...)", "duracion": "12:54",
      "detalle": "PLANTA CARAL (...) → PLANTA CHICLAYO (...) · 712 km · fuente: ruta_medida_capado_geoapify" },
    { "n": 3, "concepto": "tiempo_muerto", "titulo": "Espera y descanso del conductor", "duracion": "83:33",
      "detalle": "diferencia entre el hueco real medido y el traslado estimado" },
    { "n": 4, "concepto": "presenta", "titulo": "Se presenta en PLANTA CHICLAYO (...)", "duracion": "96:27", "fecha": "2026-11-15 09:27:00" },
    { "n": 5, "concepto": "espera_carga", "titulo": "Espera para cargar", "duracion": "1:49" },
    { "n": 6, "concepto": "inicio_carga", "titulo": "Empieza a cargar", "duracion": "98:16", "fecha": "2026-11-15 11:16:00" }
  ],
  "advertencias": [],
  "historia": { "pares": 16, "nivel": "parcial", "n": 12, "grupo": "placa", "calibrador_usado": true, "geoapify": true }
}
JSON;
@endphp

@section('content')
    <div class="row mb-3">
        <div class="col-12">
            <h1><i class="bi bi-braces"></i> API de predicción de carga</h1>
            <p class="text-muted mb-1">
                Predice a qué hora un camión se va a <strong>presentar para cargar</strong> y a qué hora
                <strong>empieza a cargar</strong>. Es pública (sin token), como el resto de <code>/api</code>.
                Devuelve <strong>cinco fechas</strong> y los pasos con los que se armó cada hora. Si hay que
                elegir una, el orden de prioridad por precisión es:
                <span class="badge bg-light text-dark">rápido</span> &gt;
                <span class="badge bg-secondary">consenso</span> &gt;
                <span class="badge bg-primary">normal</span> &gt;
                <span class="badge bg-info text-dark">base ajustada</span> &gt;
                <span class="badge bg-warning text-dark">tardado</span>.
                El detalle y las cuentas están en el punto 2.
            </p>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-primary" href="{{ route('lupita.prediccion.presenta') }}">
                    <i class="bi bi-calculator"></i> Probar en la calculadora
                </a>
                <a class="btn btn-sm btn-outline-success" href="{{ route('lupita.prediccion.aciertos') }}">
                    <i class="bi bi-bullseye"></i> Ver el % de éxito real
                </a>
                <a class="btn btn-sm btn-outline-secondary" href="#tiempos-muertos">
                    <i class="bi bi-moon-stars"></i> Cómo se calculan descansos y almuerzos
                </a>
            </div>
        </div>
    </div>

    {{-- ================= ENDPOINTS ================= --}}
    <div class="card mb-3">
        <div class="card-header bg-primary text-white"><strong>1. Endpoints</strong></div>
        <div class="card-body">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                <tr><th>Método</th><th>URL</th><th>Para qué</th></tr>
                </thead>
                <tbody>
                <tr>
                    <td><span class="badge bg-success">GET</span></td>
                    <td><code>/api/prediccion/presenta?ref=1182661</code></td>
                    <td>Predicción rápida con lo que ya está en la base.</td>
                </tr>
                <tr>
                    <td><span class="badge bg-warning text-dark">POST</span></td>
                    <td><code>/api/prediccion/presenta</code></td>
                    <td>Igual, pero acepta el <em>viaje</em> y la <em>historia</em> en el cuerpo y, con
                        <code>guardar: true</code>, las escribe antes de predecir.</td>
                </tr>
                <tr>
                    <td><span class="badge bg-success">GET</span></td>
                    <td><code>/api/prediccion/aciertos?desde=&amp;hasta=</code></td>
                    <td>Tabla de aciertos del modelo contra las referencias ya cerradas.</td>
                </tr>
                </tbody>
            </table>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="fw-bold">Parámetros del GET</div>
                    <table class="table table-sm mb-0">
                        <tbody>
                        <tr><td><code>ref</code></td><td>obligatorio, 3–10 caracteres</td></tr>
                        <tr><td><code>ahora</code></td><td>corte opcional (<code>YYYY-MM-DD HH:MM</code>); sirve para simular</td></tr>
                        <tr><td><code>ancla</code></td><td>ref del viaje que se usa como punto de partida</td></tr>
                        <tr><td><code>ubicacion_tipo</code></td><td><code>auto</code> · <code>local</code> · <code>ubigeo</code> · <code>indeterminado</code></td></tr>
                        <tr><td><code>ubicacion_id</code></td><td>id del local (<code>LOC_CARAL</code>) o ubigeo de 6 dígitos (<code>140101</code>)</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <div class="fw-bold">Cuerpo del POST</div>
                    <pre class="small p-2 bg-dark text-light rounded mb-0">{
  "ref": "1182661",
  "ahora": "2026-11-11 09:00",
  "ubicacion": { "tipo": "local", "id": "LOC_CARAL" },
  "guardar": false,
  "viaje":    { "ref": "1180352", "fin_descargue": "11/11/2026 15:30" },
  "historia": [ { "ref": "1175763", "presenta_para_carga": "05/11/2026 08:00" } ]
}</pre>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= LAS CINCO FECHAS ================= --}}
    <div class="card mb-3">
        <div class="card-header bg-success text-white"><strong>2. Las cinco fechas</strong></div>
        <div class="card-body">
            {{-- PRIORIDAD POR PRECISIÓN: es el orden en que se leen y se publican --}}
            <div class="alert alert-success py-2 mb-3">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <strong>Cuál usar, en orden de prioridad por precisión:</strong>
                    <span class="badge bg-success">1. Rápido</span>
                    <span class="badge bg-primary">2. Consenso</span>
                    <span class="badge bg-secondary">3. Normal</span>
                    <span class="badge bg-info text-dark">4. Base ajustada</span>
                    <span class="badge bg-warning text-dark">5. Tardado</span>
                </div>
                <div class="small mb-0">
                    Ése es el orden con el que se publican y se comparan las cinco fechas. Lo medido sobre
                    <strong>3.085 presentaciones reales</strong>, de las filas en que alguna fecha entra en el
                    margen, el porcentaje que se lleva cada una:
                    <div class="table-responsive mt-1">
                        <table class="table table-sm table-borderless mb-0" style="font-size:.78rem">
                            <thead>
                            <tr>
                                <th>margen</th>
                                <th class="text-end text-success">Rápido</th>
                                <th class="text-end text-primary">Consenso</th>
                                <th class="text-end text-secondary">Normal</th>
                                <th class="text-end text-info">Base ajustada</th>
                                <th class="text-end text-warning">Tardado</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach([
                                ['±30 min', 14, 37, 26, 21, 2],
                                ['±1 h', 11, 34, 27, 24, 4],
                                ['±2 h', 10, 33, 28, 24, 5],
                                ['±3 h', 12, 31, 27, 24, 6],
                                ['±6 h', 13, 27, 26, 25, 10],
                                ['±12 h', 11, 24, 23, 27, 15],
                            ] as [$margen, $rapido, $consenso, $normal, $probable, $tardado])
                                <tr>
                                    <td class="text-muted">{{ $margen }}</td>
                                    <td class="text-end text-success">{{ $rapido }} %</td>
                                    <td class="text-end fw-bold text-primary">{{ $consenso }} %</td>
                                    <td class="text-end text-secondary">{{ $normal }} %</td>
                                    <td class="text-end text-info">{{ $probable }} %</td>
                                    <td class="text-end text-warning">{{ $tardado }} %</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-1">
                        En MAE el orden es otro: <strong>base ajustada (19 h 51) &lt; consenso (20 h 10) &lt;
                        normal (22 h 06) &lt; rápido (23 h 45) &lt; tardado (32 h 07)</strong>. O sea: la
                        <strong>base ajustada</strong> es la de menor error promedio y la única con la mediana
                        centrada (−16 min), el <strong>consenso</strong> es el que más veces cae dentro del margen y
                        el <strong>tardado</strong> nunca sirve para un margen corto (2-6 %): es el techo de la
                        banda, no una hora para prometer.
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card h-100 border-success">
                        <div class="card-body py-2">
                            <div class="fw-bold text-success">1. Rápido — el extremo temprano</div>
                            <div class="small text-muted">
                                Si el camión vuelve como sus mejores viajes: el cuantil 25 del hueco real
                                (o −30 % de la cadena cuando no hay historia). Nunca es menor que el
                                traslado medido —y tampoco antes de que el camión quede libre del viaje
                                en curso y vuelva a cargar: si el adelanto medido se pasa de ahí, se acota
                                y se avisa con <code>banda_acotada</code>. Es la que más veces entra en el
                                margen en los cortes donde se mide.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 border-primary">
                        <div class="card-body py-2">
                            <div class="fw-bold text-primary">2. Consenso — la que mejor promedia</div>
                            <div class="small text-muted">
                                El <strong>promedio</strong> de la rápida, la normal y la base ajustada.
                                Promediar baja la varianza: gana el acierto por tolerancia
                                (±30m 37 % · ±1h 34 % · ±3h 31 %) más seguido que cualquiera de las
                                tres por separado, a costa de casi nunca ser la más cercana.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 border-secondary">
                        <div class="card-body py-2">
                            <div class="fw-bold text-secondary">3. Normal — la de siempre</div>
                            <div class="small text-muted">
                                La mediana del hueco. Es la que se compara en la tabla de aciertos y
                                la que se guarda en <code>prediccion_resultados</code>.
                                Acierta 26-28 % dentro del margen; su mediana está 4 h 25 adelantada.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 border-info">
                        <div class="card-body py-2">
                            <div class="fw-bold text-info">4. Base ajustada (4.ª) — la mejor centrada</div>
                            <div class="small text-muted">
                                La normal <strong>corregida</strong> con la mediana del error de su clase
                                (evidencia × tramo del horizonte). Tiene el mejor MAE (19 h 51) y la
                                mediana en −16 min: es la hora "esperada". Se mide con el botón
                                <em>Medir los sesgos</em>; con el sesgo apagado es igual a la normal.
                                En el JSON la clave sigue siendo <code>probable</code>.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 border-warning">
                        <div class="card-body py-2">
                            <div class="fw-bold text-warning">5. Tardado — el extremo tardío</div>
                            <div class="small text-muted">
                                Si se le cruza un descanso largo, una cola en planta o un viaje que se
                                estira: el cuantil 75 (o +30 % de la cadena). Con el rápido forma la
                                <strong>banda de la promesa</strong>; solo acierta 2-6 % dentro del
                                margen, y su mediana está 23 h 42 atrasada.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="alert alert-light border mt-3 mb-0 small">
                El <strong>rápido nunca es menor que el traslado medido</strong>: el camión no puede llegar antes de lo
                que tarda el viaje. Si el cálculo cae dentro de una franja en la que el camión nunca hace nada
                (su descanso), las fechas se corren al final de esa franja.
            </div>
        </div>
    </div>

    {{-- ================= TIEMPOS MUERTOS ================= --}}
    <div class="card mb-3" id="tiempos-muertos">
        <div class="card-header bg-dark text-white">
            <strong>3. Tiempos muertos: descanso, dormir y almuerzo</strong>
        </div>
        <div class="card-body">
            <p class="small">
                Entre que el camión queda libre (fin de descargue) y se presenta para cargar de nuevo hay horas
                que no son viaje: <strong>descanso nocturno, siesta, almuerzo, colas y trámites</strong>. El modelo
                no las adivina con una constante: las separa en dos partes y las mide con los viajes del propio camión.
            </p>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="fw-bold">a) El hueco real (lo que de verdad tardó)</div>
                    <p class="small mb-1">
                        Para cada placa se toman sus últimos <strong>20 pares</strong>
                        <code>fin de descargue → presenta siguiente</code>. La mediana de ese hueco es la base:
                        incluye el regreso vacío, la espera, el descanso y el almuerzo, tal como pasaron.
                        Se corrige por la hora a la que quedó libre: un camión liberado a las 23:00 no vuelve a las 03:00.
                    </p>
                    <div class="alert alert-secondary py-1 small mb-0">
                        Ejemplo real: el hueco mediano de la flota es de <strong>19 h</strong>; el 25 % de las vueltas
                        tarda menos de 6 h y el 75 % menos de 2 días.
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="fw-bold">b) El tiempo muerto explícito</div>
                    <p class="small mb-1">
                        El hueco se parte en dos en el JSON y en la pantalla:
                    </p>
                    <ul class="small mb-1">
                        <li><strong>traslado_regreso</strong>: el viaje de vuelta hasta la planta de carga. El
                            tiempo de esa ruta se calcula <strong>igual que el tramo que le falta al camión</strong>:
                            lo medido de la ruta, mezclado con lo que esa placa tarda en ella y con el compilado
                            por día y hora de salida. La vuelta va <strong>sin carga</strong>, así que si la única
                            ruta medida del par es la de ida su promedio se corrige con
                            <code>vacio_factor</code> (medido: 0,83). Sin ningún promedio para el par, el traslado
                            sale de las <strong>coordenadas de los locales</strong> (distancia recta × el ajuste
                            <code>base + pendiente × km</code>), y sólo después la mediana por departamentos y el
                            manejo real de Geoapify.</li>
                        <li><strong>tiempo_muerto</strong>: <code>hueco − traslado</code>. Es exactamente lo que el
                            camión estuvo parado: <em>descanso, dormir, almuerzo, cola</em>.</li>
                    </ul>
                    <div class="alert alert-secondary py-1 small mb-0">
                        Ejemplo real del JSON de abajo: 5787 min de hueco total, 774 min de traslado y
                        <strong>5013 min (83:33) de tiempo muerto</strong>.
                    </div>
                </div>
            </div>

            <div class="fw-bold mt-3">c) Las franjas en las que el camión nunca hace nada</div>
            <p class="small mb-1">
                Además de la parte cuantitativa, el modelo aprende <strong>a qué horas del día el camión no tiene
                ningún hito</strong>. Una franja de 30 minutos con menos de 2 marcas en todo el período de estudio es
                una franja muerta: ahí está su descanso nocturno o su almuerzo. Cuando la hora calculada cae adentro,
                se corre al final de la franja y ese corrimiento se informa como <code>espera_ventana</code>
                (y aparece como paso propio si supera los 0 minutos).
            </p>
            <ul class="small mb-0">
                <li>Si el camión tiene menos de 5 días de historia, o su perfil sale absurdo (más del 60 % del día
                    quieto), se usan franjas por defecto 22:00–06:00: en la respuesta se ve en
                    <code>historia.ventanas_origen</code>.</li>
                <li>Las franjas detectadas se listan en <code>historia.ventanas</code> en minutos del día,
                    y la pantalla las muestra como <code>22:00–24:00 · 00:00–06:00</code>.</li>
            </ul>
        </div>
    </div>

    {{-- ================= RESPUESTA ================= --}}
    <div class="card mb-3">
        <div class="card-header bg-info text-white"><strong>4. La respuesta (ejemplo real)</strong></div>
        <div class="card-body">
            <pre class="small p-2 bg-dark text-light rounded mb-0" style="max-height:520px; overflow:auto">{{ $ejemplo }}</pre>
        </div>
    </div>

    {{-- ================= CAMPOS Y PASOS ================= --}}
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><strong>5. Campos importantes</strong></div>
                <div class="card-body">
                    <table class="table table-sm mb-0 small">
                        <tbody>
                        <tr><td><code>presenta_rapido</code> / <code>presenta</code> / <code>presenta_tardado</code></td><td>la banda: la temprana, la normal y la tardía</td></tr>
                        <tr><td><code>presenta_probable</code></td><td>la <strong>cuarta</strong>, que se publica como <strong>base ajustada</strong>: la normal corregida con la mediana del error de su clase. Con el sesgo apagado es igual a <code>presenta</code></td></tr>
                        <tr><td><code>presenta_consenso</code></td><td>la <strong>quinta</strong>: el promedio de la rápida, la normal y la base ajustada. Gana por tolerancia (±30m/±1h/±3h); no siempre gana en "quién quedó más cerca"</td></tr>
                        <tr><td><code>sesgo</code></td><td><code>clase</code> (evidencia × tramo del horizonte), <code>min</code> (cuánto se corrió; negativo = se adelantó) y <code>aplicado</code></td></tr>
                        <tr><td><code>presentaciones</code></td><td>las cinco fechas con etiqueta y <code>desde_ahora</code> en <code>h:mm</code>, EN ORDEN DE PRIORIDAD POR PRECISIÓN: <code>rapido</code>, <code>consenso</code>, <code>normal</code>, <code>probable</code> (etiqueta «Base ajustada»), <code>tardado</code>. Cada una trae además <code>delta_normal_min</code> (minutos respecto de la normal; negativo = antes) y <code>construccion</code> (de dónde sale ESA fecha, en una línea)</td></tr>
                        <tr><td><code>inicio_carga</code></td><td>presenta normal + espera en planta</td></tr>
                        <tr><td><code>espera_hhmm</code></td><td>la espera en planta, en <code>h:mm</code></td></tr>
                        <tr><td><code>duracion_total_hhmm</code></td><td>del ancla a la presentación normal (puede pasar de 24 h: <code>96:27</code>)</td></tr>
                        <tr><td><code>evidencia</code></td><td><code>medido</code> (3+ pares del mismo par de rutas) · <code>parcial</code> (de la placa) · <code>cadena</code> (sin historia) · <code>sin_datos</code></td></tr>
                        <tr><td><code>confianza</code></td><td>0–100. ≥60 planificar · 30–59 mostrar avisando · &lt;30 no comprometer</td></tr>
                        <tr><td><code>pasos</code></td><td>los pasos en orden, cada uno con su <code>fecha</code>,
                            <code>acumulado_min</code> y <code>h:mm</code>; la suma de duraciones (con
                            <code>ajuste</code> si la hora se recortó) da <code>presenta − ancla.fecha</code></td></tr>
                        <tr><td><code>atrasado</code></td><td>el cálculo cayó antes de <code>ahora</code>: ya debería haber presentado</td></tr>
                        <tr><td><code>historia</code></td><td>cuántos pares se usaron, con quién se calibró
                            (<code>grupo</code> = <code>placa</code> o <code>conductor</code>), las franjas y si
                            Geoapify estaba disponible. <code>viaje_cruce</code> y <code>traslado_cruce</code>
                            dicen cómo se mezcló el compilado de la ruta con lo que hace esta placa;
                            <code>viaje_horario</code> y <code>traslado_horario</code>, cómo entró el compilado
                            por día y hora. El traslado de regreso se calcula con la misma cadena que el tramo
                            que le falta al camión.</td></tr>
                        <tr><td><code>geoapify</code></td><td><code>usado</code>, <code>min</code>, <code>km</code>, <code>tramo</code>,
                            <code>motivo</code>, <code>llamadas</code> (lo que gastó esta predicción) y
                            <code>uso_hoy</code> (llamadas del día en toda la app)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><strong>6. Advertencias frecuentes</strong></div>
                <div class="card-body small">
                    <p class="mb-1">Vienen como <code>"clave: explicación"</code>; se puede filtrar por la clave.</p>
                    <ul class="mb-0">
                        <li><code>ya_presento</code> — la ref ya tiene presentación, no hay nada que predecir.</li>
                        <li><code>atrasado</code> — el cálculo cae antes del corte (viene <code>presenta_crudo</code>).</li>
                        <li><code>ancla_declarada</code> — no hay viaje en curso: se calculó desde la ubicación declarada.</li>
                        <li><code>ancla_no_encontrada</code> — la ref que mandaste en <code>ancla</code> no es un viaje de
                            esa placa anterior al corte (typo o fecha mal): no se predice desde otro lado.</li>
                        <li><code>ancla_vieja</code> — el último hito tiene más de 7 días: <code>sin_datos</code>.</li>
                        <li><code>traslado_geoapify</code> — no hay tránsito medido: se usó el manejo real de Geoapify.</li>
                        <li><code>etapa_vencida</code> — el viaje en curso ya consumió su etapa sin hitos nuevos.</li>
                        <li><code>espera_ventana</code> (paso) — la hora cayó en su franja de descanso o almuerzo.</li>
                        <li><code>hueco_menor_que_traslado</code> — el hueco medido es menor que el traslado: manda el medido.</li>
                        <li><code>banda_acotada</code> — la banda dejaba el rápido antes de lo físicamente posible
                            (el camión todavía tiene que quedar libre y volver): se acotó al piso.</li>
                        <li><code>sesgo_acotado</code> — la corrección por clase mandaba la cuarta fecha antes del
                            piso físico: se acotó.</li>
                        <li><code>banda_invertida</code> — la predicción quedó atrasada (la normal se recortó a
                            <code>ahora</code>) y el rápido, que respeta el piso físico, cae después: la banda
                            rápida–tardada no contiene a la normal. Se avisa en vez de recortar el rápido contra ella
                            (eso publicaría una hora físicamente imposible).</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= ERRORES Y BUENAS PRACTICAS ================= --}}
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><strong>7. Errores</strong></div>
                <div class="card-body small">
                    <ul class="mb-0">
                        <li><strong>404</strong> — la ref no existe (<code>{{ '{' }}"success": false, "error": "Ref no encontrada"{{ '}' }}</code>).</li>
                        <li><strong>409</strong> — el estado de la ref no permite predecir: <strong>no tiene ruta
                            asignada</strong> (sin planta de carga no hay traslado ni espera), o
                            <strong>ya está en curso</strong> (tiene <code>inicio_de_carga</code>/<code>inicio_ruta</code>
                            pero le falta <code>presenta_para_carga</code>: el camión ya presentó). No es un problema del
                            request.</li>
                        <li><strong>415</strong> — POST sin <code>Content-Type: application/json</code>.</li>
                        <li><strong>422</strong> — parámetro inválido, local inexistente, ubigeo que no es de 6 dígitos,
                            o <code>guardar</code> sin viaje/historia. Trae <code>detalle</code>.</li>
                        <li><strong>422 · “Ubicación ambigua”</strong> — <code>ubicacion_texto</code> coincidió con varias
                            ubicaciones (<code>candidatos</code> con <code>{id, texto}</code>): repetir con
                            <code>ubicacion_id</code> o con el nombre más completo.</li>
                        <li><strong>500</strong> — falla del servidor: reintentar con espera.</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><strong>8. Buenas prácticas</strong></div>
                <div class="card-body small">
                    <ul class="mb-0">
                        <li>La hora cambia cuando entra un hito nuevo, no cada minuto: cachear ~5 minutos por ref.</li>
                        <li>Reintentar solo en 500 (1s, 2s, 4s).</li>
                        <li>Usar <code>presentaciones[].desde_ahora</code> para mostrar "en 3:15" sin dividir minutos.</li>
                        <li>Mostrar las fechas publicadas y la confianza: una sola hora sin contexto se malinterpreta.
                            Si tenés que elegir una, el orden de prioridad por precisión es
                            <strong>rápido → consenso → normal → base ajustada → tardado</strong>; la
                            <strong>base ajustada</strong> es la que mejor MAE da y el <strong>consenso</strong> el que
                            más veces cae dentro de ±1h / ±3h.</li>
                        <li>Comparar solo filas que tengan TODAS las fechas: si una no está, esa fila no entra
                            (así ninguna gana por tener más casos fáciles).</li>
                        <li>Para predecir sin viaje en curso, mandar <code>ubicacion</code> (local o ubigeo).</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>9. Calibrar el motor</strong>
            <span class="small text-muted">— qué se puede mover y cómo se elige</span>
        </div>
        <div class="card-body small">
            <div class="row g-3">
                <div class="col-lg-6">
                    <p class="mb-1"><strong>Parámetros, sin tocar código.</strong> Las 17 claves de
                        <code>config/prediccion.php</code> se pisan con una fila en <code>prediccion_config</code>
                        (clave/valor). La pantalla de parámetros edita ocho; el resto se cambia insertando la fila
                        o con <code>--aplicar</code>.</p>
                    <ul class="mb-0">
                        <li><code>pares_recientes_max</code> — con cuántos huecos recientes de la placa se calibra.</li>
                        <li><code>min_pares_ruta</code> / <code>min_pares_entidad</code> — mínimos para “medido” y “parcial”.</li>
                        <li><code>bins_horarios</code> — franjas del día de la corrección horaria del hueco.</li>
                        <li><code>spread_cadena</code> — ancho de rápido/tardado cuando no hay pares medidos.</li>
                        <li><code>lookback_dias</code> — cuánta historia se mira.</li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <p class="mb-1"><strong>Calibración automática.</strong> Un comando corre la misma tabla de
                        aciertos con una rejilla de combinaciones sobre un tramo de <em>entrenamiento</em> y elige la
                        mejor medida sobre el tramo final de <em>validación</em> (que no se usó para elegir):</p>
                    <pre class="small p-2 bg-dark text-light rounded mb-1" style="white-space:pre-wrap">php artisan prediccion:calibrar
php artisan prediccion:calibrar --metrica=pct_3h
php artisan prediccion:calibrar --rejilla='{"spread_cadena":[0.2,0.3,0.5]}'
php artisan prediccion:calibrar --aplicar   # guarda en prediccion_config</pre>
                    <p class="mb-0 text-muted">
                        Antes de calibrar conviene <code>php artisan prediccion:diagnostico</code>: si faltan hitos o
                        refs sin ruta, el problema son los datos, no los parámetros.
                    </p>
                </div>
            </div>
            <hr>
            <p class="mb-0">
                <strong>Cerca de la planta.</strong> Si el camión descargó en el <em>mismo local</em> o en el
                <em>mismo distrito</em> de la planta donde vuelve a cargar, carga enseguida mucho más seguido
                (flota: mediana <strong>138 min</strong> y 54 % en ≤3 h, contra <strong>1.020 min</strong> y 7 % cuando
                está lejos). En ese caso el motor calibra el hueco con los viajes en que también quedó cerca; si la placa
                no tiene muestra propia usa el régimen medido en la flota (por chofer si ese chofer tiene pares). Se
                publica en <code>historia.cerca</code>, <code>historia.calibrado_cerca</code> y
                <code>historia.cerca_regimen</code>.
                <br>
                <strong>Placa o conductor.</strong> El hueco del descanso se aprende del historial de la
                <em>placa</em>, salvo que el conductor de la referencia concentre <strong>menos de la mitad</strong> de
                los viajes de esa placa en el período mirado (<code>lookback_dias</code>): ahí manda el
                <em>comportamiento del conductor</em>, con sus propios pares. Con menos de
                <code>min_pares_entidad</code> (3) pares de la entidad elegida se cae al mismo par de rutas y después a
                la mediana global. La respuesta lo dice en <code>historia.grupo</code>
                (<code>placa</code> | <code>conductor</code>), la predicción lo muestra en pantalla y la tabla de
                aciertos trae la columna <strong>Calibra con</strong>.
                <br>
                <strong>Cómo leer las dos columnas:</strong> <em>Calibra con</em> = de quién salió el hueco y cuántos
                pares reales lo sostienen (0 pares = no hubo historia suficiente); <em>Evidencia</em> = nivel final
                (<code>medido</code> 3+ pares del mismo par de rutas · <code>parcial</code> la placa/chofer o la
                mediana de la flota · <code>cadena</code> sin pares: traslado + tiempo muerto por defecto) con la
                confianza 0-100 debajo. Regla práctica: pares &gt; 0 con evidencia <code>medido</code>/<code>parcial</code>
                = hora con historia real; <code>cadena</code> con 0 pares = estimación gruesa, no comprometer.
                <br>
                <strong>De dónde sale cada número (cruce de las APIs compiladas).</strong> El <em>viaje al destino</em>
                y el <em>traslado de regreso</em> salen del compilado de la ruta
                (<code>/api/tiempos/promedio</code>: <code>mediana_min</code>) mezclado con lo que <em>esa placa</em>
                tarda en la misma ruta, con peso <code>w = n/(n+k)</code>
                (<code>cruce_pares_k</code>, k=3: 1 viaje propio pesa 25 %, 3 pesan 50 %, 10 pesan 77 %), y después
                con el compilado <strong>por día y hora de salida</strong> (<code>/api/tiempos/promedio/horario</code>,
                <code>horario_viajes_k</code>) con el mismo peso por muestra. La
                <em>cola en destino</em> y el <em>descargue</em> salen del compilado por planta
                (<code>/api/tiempos/promedio/plantas</code>: <code>prom_fuera_descarga_min</code> +
                <code>prom_dentro_descarga_min</code>); la <em>espera en la planta de carga</em>, de
                <code>prom_fuera_carga_min</code> + <code>prom_dentro_carga_min</code>. El
                <em>tiempo muerto/descanso</em> es el único que no existe en esas APIs: sale de los pares de la
                placa/chofer. Todo queda en <code>historia.viaje_cruce</code>, <code>historia.viaje_horario</code>,
                <code>historia.traslado_cruce</code>, <code>historia.traslado_horario</code>,
                <code>historia.traslado_sentido</code> (<code>vacio</code>/<code>cargado</code>),
                <code>historia.traslado_factor_vacio</code> y <code>historia.traslado_fuente</code>. Cuando el
                traslado sale de las coordenadas, <code>historia.traslado_km</code> y
                <code>historia.traslado_ajuste</code> dicen la distancia recta y qué ajuste se usó
                (<code>par</code> de departamentos, <code>global</code> o <code>config</code>).
            </p>
            </div>
        </div>
    </div>

    <div class="alert alert-secondary small">
        ¿Dónde quedan los resultados? Cada predicción (de la pantalla o del API) se guarda en la tabla
        <code>prediccion_resultados</code> con la normal, la rápida y la tardada; la <strong>base ajustada</strong> y
        el <strong>consenso</strong> viajan dentro del campo <code>desglose</code> (no hay columnas nuevas: en
        producción no se puede migrar). Cuando la referencia consigue su presentación real, se compara contra lo
        guardado y el porcentaje de éxito aparece en
        <a href="{{ route('lupita.prediccion.aciertos') }}">la tabla de aciertos</a>.
    </div>

    <div class="alert alert-info small">
        <strong>¿Cuántas veces se usó Geoapify?</strong> Cada petición al API se cuenta (también las que fallan) en la
        tabla <code>geoapify_uso</code>: hoy, ayer, últimos 7 y 30 días y el detalle día por día están en
        <a href="{{ route('lupita.prediccion.geoapify') }}">Uso de Geoapify</a>.
        Es el mismo número que publica <code>geoapify.llamadas</code> / <code>geoapify.uso_hoy</code> en cada predicción.
    </div>
@endsection

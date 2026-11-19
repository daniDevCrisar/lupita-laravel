# Ubicaciones · Fase 1 (diagnóstico de títulos y catálogo) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Un grupo "Ubicaciones" en el panel que muestre por qué 688 refs quedaron sin `ruta_id`, convierta eso en un ranking clasificado de palabras candidatas a local, permita descartarlas y cree/actualice locales, y permita reprocesar refs llamando a `sp_actualizar_ruta_id`.

**Architecture:** La lógica de parseo del SP se reimplementa en PHP puro (`App\Ubicaciones\*`) para poder correr sobre texto hipotético sin escribir en la base; el SP sigue siendo el único que escribe. `AnalisisTitulos` orquesta: trae las refs sin ruta de `DBUbicaciones`, agrupa por título normalizado, analiza, extrae candidatos y los clasifica contra el catálogo. Los controladores solo arman la vista; la única tabla nueva (`ubicaciones_descartes`) guarda solo decisiones humanas.

**Tech Stack:** Laravel 10 · Blade · Bootstrap 5 (tema oscuro) · MySQL 8 · PHPUnit · `sp_actualizar_ruta_id` (V3, `sql/rutas.sql:243-400`, ya instalado en esta base).

**Spec:** `docs/superpowers/specs/2026-11-19-ubicaciones-design.md`

## Global Constraints

- Código y comentarios de este módulo **en español, sin tildes en los comentarios** (el resto del proyecto lo hace así). Los textos de pantalla sí llevan tildes.
- Todo el grupo queda bajo `middleware('role:admin')`, igual que `/lupita/locales` hoy.
- No se agregan dependencias de Composer ni de npm. Nada de frameworks JS.
- Layout `@extends('layouts.app')`, contenido en `@section('content')`, JS en `@section('scripts')`.
- El `id` de un local y el `id` de un cliente **no se editan nunca**.
- La comparación de textos contra el catálogo usa el texto real (con tildes y mayúsculas), pero **insensible a mayúsculas**, igual que el collation `_ai_ci` de MySQL.
- `palabra_clave` es un `VARCHAR(100)`; la validación de locales es `max:100`. Ningún prellenado puede pasarse de 100 caracteres.
- Valores medidos en esta base (referencia para entender el tamaño, **no** para asserts): 694 refs sin `ruta_id` al empezar (691 con título), 508 títulos distintos, 155 fragmentos sin resolver. Los tests usan **fixtures propias**; nunca estos números.
- PHPUnit: `php artisan test --filter=<Clase>`. Los tests **no** pueden depender de que el SP exista.

## Review Focus

Las entradas que el diseño no cubre y que más probablemente rompan el software:

1. **`palabra_clave` que es un regex inválido** (`CORP. CHRISTIAN` tiene un punto; `(ACEITE)` un paréntesis; un `|` suelto rompe todo): el SP los mete crudos al regex y MySQL no falla, pero `preg_match` en PHP sí emite warning y devuelve `false`. El analizador debe silenciar el warning y tratar el patrón inválido como "no matchea", nunca propagar el error.
2. **Título con `MULTIPARADA` en minúsculas o con acentos**: el SP compara con `INSTR` bajo collation `_ai_ci`, así que `multiparada` cuenta. Un `!== 'MULTIPARADA'` en PHP no lo detectaría.
3. **Título con más de un `/`** (`MULTIPARADA ... / CALLAO/LIMA NORTE 18.04.2026`): el corte es solo en el **primer** `/`, y de ahí sale el destino con otro `/` adentro.
4. **Fragmento más largo que 100 caracteres** usado como `palabra_clave` prellenada: se truncaría y la validación lo rechazaría con un error críptico. La solapa de candidatos no puede ofrecer frases de más de 60.
5. **Descartes con tildes o espacios distintos** (`RECOJO  ACEITE` vs `RECOJO ACEITE`): la clave del descarte se calcula sobre el texto normalizado, o descartar el mismo candidato dos veces no funciona.
6. **Reprocesar la misma ref dos veces**: el SP incrementa `veces_usada` en cada llamada. El servidor debe ignorar refs que ya tienen `ruta_id`, aunque el cliente las mande.

Cada uno tiene su test en la tarea que le corresponde (marcados `[ReviewFocus N]`).

---

## Estructura de archivos

| archivo | responsabilidad |
|---|---|
| `config/ubicaciones.php` | palabras ignoradas, topes de n-grama y de longitud, umbrales de parecido |
| `app/Ubicaciones/AnalizadorTitulos.php` | réplica V3 del SP: título → origen/destino limpios + resolución + `ruta_id`. Sin escrituras |
| `app/Ubicaciones/ExtractorCandidatos.php` | fragmento → palabras y n-gramas; normalización y clave de descarte |
| `app/Ubicaciones/Clasificador.php` | frase + contexto → veredicto y local sugerido |
| `app/Ubicaciones/AnalisisTitulos.php` | orquestación y agregación: refs sin ruta → candidatos y fragmentos |
| `app/Database/DBUbicaciones.php` | consultas: refs sin ruta, catálogos, descartes |
| `app/Database/DBReprocesar.php` | detección del SP y llamada ref por ref con reporte |
| `app/Http/Controllers/Ubicaciones/TitulosController.php` | pantalla "Títulos sin ruta" |
| `app/Http/Controllers/Ubicaciones/PalabrasController.php` | pantalla "Palabras repetidas" + descartar/restaurar/exportar |
| `app/Http/Controllers/Ubicaciones/ReprocesarController.php` | estado del SP y ejecución del reproceso |
| `resources/views/lupita/ubicaciones/titulos.blade.php` | tabla de títulos agrupados |
| `resources/views/lupita/ubicaciones/palabras.blade.php` | ranking con solapas y acciones |
| `resources/views/lupita/ubicaciones/reporte.blade.php` | reporte del reproceso |
| `database/migrations/*_create_ubicaciones_descartes.php` | tabla de descartes |
| `sql/ubicaciones.sql` | el DDL documentado, como el resto de `sql/` |

---

### Task 1: Configuración + analizador de títulos

**Files:**
- Create: `config/ubicaciones.php`
- Create: `app/Ubicaciones/AnalizadorTitulos.php`
- Test: `tests/Feature/Ubicaciones/AnalizadorTitulosTest.php`

**Interfaces:**
- Produces: `App\Ubicaciones\AnalizadorTitulos`
  - `__construct(array $locales, array $distritos)` — `$locales`: filas con `id`, `palabra_clave`; `$distritos`: filas con `ubigeo`, `distrito`.
  - `static desdeBase(): self` — carga catálogos desde `DBUbicaciones::catalogos()` y memoiza por request.
  - `analizar(?string $titulo): array` con las claves:
    `motivo` (`ok|sin_titulo|multiparada`), `titulo`, `origen_raw`, `destino_raw`, `origen`, `destino`, `origen_resuelto`, `destino_resuelto`, `ruta_id` (`?string`), `falla` (`null|origen|destino|ambos`), `ya_resolveria` (`bool`).
    `origen_resuelto`/`destino_resuelto` es `null` o `['tipo' => 'local'|'distrito', 'id' => string, 'nombre' => ?string]`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Ubicaciones/AnalizadorTitulosTest.php`. Los 6 casos salen de `resources/views/refs/ruta_id_docs.blade.php` y son el contrato de fidelidad con el SP.

```php
<?php

namespace Tests\Feature\Ubicaciones;

use App\Ubicaciones\AnalizadorTitulos;
use Tests\TestCase;

class AnalizadorTitulosTest extends TestCase
{
    private function analizador(): AnalizadorTitulos
    {
        return new AnalizadorTitulos(
            [
                ['id' => 'LOC_AJEPER_GRUPO_PACIFIC_OIL', 'palabra_clave' => 'GRUPO PACIFIC OIL|(ACEITE)'],
                ['id' => 'LOC_CARAL', 'palabra_clave' => 'CARAL'],
                ['id' => 'LOC_HUACHIPA', 'palabra_clave' => 'HUACHIPA'],
                ['id' => 'LOC_BABEL_AMAZON', 'palabra_clave' => 'AMAZON|BABEL AMAZON'],
                ['id' => 'LOC_BABEL_MAXO', 'palabra_clave' => 'MAXO'],
                ['id' => 'LOC_BABEL_CHICLAYO', 'palabra_clave' => 'BABEL CHICLAYO'],
                ['id' => 'LOC_CHICLAYO', 'palabra_clave' => 'CHICLAYO'],
                ['id' => 'LOC_PUCALLPA', 'palabra_clave' => 'PUCALLPA'],
                ['id' => 'LOC_PTE_PIEDRA', 'palabra_clave' => 'PTE PIEDRA'],
            ],
            [
                ['ubigeo' => '150601', 'distrito' => 'SUPE PUERTO'],
                ['ubigeo' => '110101', 'distrito' => 'ICA'],
            ]
        );
    }

    public function test_resuelve_local_a_local(): void
    {
        $r = $this->analizador()->analizar('ENVIO PT AJEPER CARAL - BABEL MAXO / CARGA 16.04');

        $this->assertSame('ok', $r['motivo']);
        $this->assertSame('AJEPER CARAL', $r['origen']);
        $this->assertSame('BABEL MAXO', $r['destino']);
        $this->assertSame('RUT_CARAL-BABEL_MAXO', $r['ruta_id']);
        $this->assertNull($r['falla']);
        $this->assertTrue($r['ya_resolveria']);
    }

    public function test_el_guion_sin_espacios_parte_mal_el_origen(): void
    {
        // fidelidad: el SP corta por "-" y deja "DESPACHO DE INSUMOS" como origen
        $r = $this->analizador()->analizar('DESPACHO DE INSUMOS-HUACHIPA -PTA AYACUCHO - CARGA 22/04/2026');

        $this->assertSame('DE INSUMOS', $r['origen']);
        $this->assertNull($r['origen_resuelto']);
        $this->assertSame('origen', $r['falla']);
        $this->assertNull($r['ruta_id']);
        $this->assertFalse($r['ya_resolveria']);
    }

    public function test_los_parentesis_pegan_las_palabras(): void
    {
        // fidelidad: PUCALLPA(CORONADO) queda PUCALLPACORONADO y ya no matchea PUCALLPA
        $r = $this->analizador()->analizar('ENVIO DE PT PLANTA PUCALLPA(CORONADO) - CEDI TINGO MARIA');
        $this->assertSame('DE PT PLANTA PUCALLPACORONADO', $r['origen']);
        $this->assertNull($r['origen_resuelto']);
    }

    public function test_multiparada_se_rechaza_antes_de_partir_el_titulo(): void
    {
        $r = $this->analizador()->analizar('MULTIPARADA CARGA HUACHIPA NUEVOS NEGOCIOS / CALLAO/LIMA NORTE');
        $this->assertSame('multiparada', $r['motivo']);
        $this->assertNull($r['ruta_id']);
        $this->assertSame('', $r['origen']);
    }

    public function test_titulo_vacio(): void
    {
        $this->assertSame('sin_titulo', $this->analizador()->analizar(null)['motivo']);
        $this->assertSame('sin_titulo', $this->analizador()->analizar('   ')['motivo']);
    }

    public function test_si_no_hay_local_usa_el_ubigeo_del_distrito(): void
    {
        $r = $this->analizador()->analizar('ENVIO PT PLANTA CARAL - ICA');
        $this->assertSame('distrito', $r['destino_resuelto']['tipo']);
        $this->assertSame('110101', $r['destino_resuelto']['id']);
        $this->assertSame('RUT_CARAL-110101', $r['ruta_id']);
    }

    // [ReviewFocus 2]
    public function test_multiparada_en_minusculas_tambien_se_rechaza(): void
    {
        // MySQL compara con collation _ai_ci: INSTR encuentra "multiparada"
        $this->assertSame('multiparada', $this->analizador()->analizar('multiparada carga huachipa')['motivo']);
    }

    // [ReviewFocus 3]
    public function test_corta_solo_en_el_primer_slash(): void
    {
        $r = $this->analizador()->analizar('CARGA CARAL - BABEL MAXO / CALLAO/LIMA NORTE 18.04.2026');
        $this->assertSame('BABEL MAXO', $r['destino']);
    }

    // [ReviewFocus 1]
    public function test_palabra_clave_que_es_regex_invalido_no_revienta(): void
    {
        $a = new AnalizadorTitulos([['id' => 'LOC_MALA', 'palabra_clave' => 'CORP. (CHRISTIAN']], []);
        $r = $a->analizar('ENVIO PT CARAL - ALGO');
        $this->assertNull($r['destino_resuelto']);   // no matchea, no lanza
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AnalizadorTitulosTest`
Expected: FAIL con `Class "App\Ubicaciones\AnalizadorTitulos" not found`.

- [ ] **Step 3: Create `config/ubicaciones.php` and implement `AnalizadorTitulos`**

`config/ubicaciones.php` devuelve el array con `palabras_ignoradas` (lista del spec + `LOS`, `UN`, `REQUIERE`, `MERCADERIA`, `VALIDAR`, `PALETS`, `PALLETS`, `CON`, `SIN`), `minimo_caracteres => 3`, `maximo_ngrama => 3`, `maximo_fragmento => 120`, `maximo_candidato => 60`, `parecido_porcentaje => 85`, `parecido_distancia => 2`.

`AnalizadorTitulos::analizar()` implementa, **en este orden y sin "mejorarlo"**:

1. `sin_titulo` si `trim()` es `''`.
2. `multiparada` si `mb_stripos($titulo, 'MULTIPARADA') !== false` (acepta minúsculas, imita `_ai_ci`).
3. `$sinFecha = trim(explode('/', $titulo)[0])`.
4. Separar con `' - '`, si no con `'-'`, si no con `' A '` (`mb_strpos`). El **primero** que aparezca, en ese orden.
5. Limpiar origen: quitar `(` y `)`, aplicar `/^(ENVIO|DESPACHO|CARGA|RETORNO|RECOJO)\s+(PT|PLANTA)?\s*/iu` y `/\\s*(PT|PLANTA)\\s*$/iu`, `trim`.
6. Limpiar destino: quitar `(` y `)`, aplicar `/-\d{2}\/\d{2}\/\d{4}$/u`, `trim`.
7. Resolver cada lado: recorrer `$locales` **en el orden recibido** y devolver el primero cuyo `palabra_clave` cumpla `/\b(<clave>)\b/iu` contra el texto **o** `/\b(<texto>)\b/iu` contra la clave. Los regex se envuelven en `@preg_match` para que un patrón inválido devuelva `false` en vez de warning. Si no hay local, buscar distrito: primero igualdad exacta (`mb_strcasecmp` contra `UPPER(distrito)`), después contención con `preg_quote`.
8. `ruta_id` = `'RUT_' . str_replace('LOC_','',$origenId) . '-' . str_replace('LOC_','',$destinoId)`; `falla` según qué lado quedó nulo; `ya_resolveria` = `falla === null`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AnalizadorTitulosTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Fidelity check against the real database (no commit of this script)**

Correr un script temporal que replique el analizador sobre las refs **con** `ruta_id` y compare con lo guardado: el mismatch debe ser **0**. Es la prueba de que el analizador no miente. Borrar el script después.

- [ ] **Step 6: Commit**

```bash
git add config/ubicaciones.php app/Ubicaciones/AnalizadorTitulos.php tests/Feature/Ubicaciones/AnalizadorTitulosTest.php
git commit -m "feat(ubicaciones): analizador de titulos de viaje, replica del sp_actualizar_ruta_id"
```

---

### Task 2: Extractor de candidatos + clasificador

**Files:**
- Create: `app/Ubicaciones/ExtractorCandidatos.php`
- Create: `app/Ubicaciones/Clasificador.php`
- Test: `tests/Feature/Ubicaciones/ExtractorCandidatosTest.php`
- Test: `tests/Feature/Ubicaciones/ClasificadorTest.php`

**Interfaces:**
- Consumes: `AnalizadorTitulos` (Task 1), `config('ubicaciones')`.
- Produces:
  - `ExtractorCandidatos::__construct(?array $config = null)`
  - `->desdeFragmento(string $fragmento): array` — lista de frases únicas, en orden de aparición, cada una de hasta `maximo_candidato` caracteres.
  - `static normalizar(string $texto): string` — mayúsculas, sin tildes, espacios colapsados.
  - `static clave(string $texto): string` — `md5(normalizar($texto))`, 32 hex.
  - `Clasificador::__construct(AnalizadorTitulos $analizador, ?array $config = null)`
  - `->clasificar(string $frase, array $contexto): array` con `['veredicto' => string, 'local_id' => ?string, 'detalle' => ?string]`. `$contexto` = `['clientes' => string[], 'refs' => int]`.
  - Veredictos, en este orden: `basura`, `generica`, `familia`, `cubierto_parseo`, `typo`, `nuevo`.

- [ ] **Step 1: Write the failing tests**

`ExtractorCandidatosTest` — casos:

```php
public function test_saca_palabras_y_ngramas(): void
{
    $e = new ExtractorCandidatos(['palabras_ignoradas' => ['DE', 'PT', 'PLANTA'],
        'minimo_caracteres' => 3, 'maximo_ngrama' => 3, 'maximo_candidato' => 60]);
    $frases = $e->desdeFragmento('DE PT MAQUILA AGUA');

    $this->assertContains('MAQUILA', $frases);
    $this->assertContains('AGUA', $frases);
    $this->assertContains('MAQUILA AGUA', $frases);
    $this->assertNotContains('DE', $frases);
    $this->assertNotContains('PT', $frases);
    $this->assertNotContains('PLANTA', $frases);
}

// [ReviewFocus 4]
public function test_no_devuelve_frases_de_mas_de_maximo_candidato(): void
{
    $e = new ExtractorCandidatos(['palabras_ignoradas' => [], 'minimo_caracteres' => 3,
        'maximo_ngrama' => 3, 'maximo_candidato' => 20]);
    foreach ($e->desdeFragmento('MAKRO ATEUT CON GPS REQUIERE UN AUXILIAR') as $frase) {
        $this->assertLessThanOrEqual(20, mb_strlen($frase));
    }
}

// [ReviewFocus 5]
public function test_normalizar_y_clave_ignoran_tildes_mayusculas_y_espacios(): void
{
    $this->assertSame(ExtractorCandidatos::clave('RECOJO  ACEITE'),
                       ExtractorCandidatos::clave('recojo aceite'));
    $this->assertSame(ExtractorCandidatos::clave('CAÑETE'),
                       ExtractorCandidatos::clave('canete'));
}
```

`ClasificadorTest` — con un analizador armado con `LOC_MAKRO_CAÑETE|MAKRO CA¥ETE`, `LOC_MAKRO_PIURA|MAKRO PIURA`, `LOC_PUCALLPA|PUCALLPA`, `LOC_HUACHIPA|HUACHIPA`, `LOC_AJEPER_GRUPO_PACIFIC_OIL|GRUPO PACIFIC OIL|(ACEITE)`:

```php
public function test_frase_nueva(): void
{
    $r = $this->clasificador()->clasificar('MAQUILA AGUA', ['clientes' => ['CLI_CODISAL'], 'refs' => 92]);
    $this->assertSame('nuevo', $r['veredicto']);
}

public function test_marca_cubierto_cuando_el_local_existe(): void
{
    $r = $this->clasificador()->clasificar('PUCALLPA', ['clientes' => ['CLI_PRADERAS'], 'refs' => 78]);
    $this->assertSame('cubierto_parseo', $r['veredicto']);
    $this->assertSame('LOC_PUCALLPA', $r['local_id']);
}

public function test_marca_typo(): void
{
    $r = $this->clasificador()->clasificar('HUCHIPA', ['clientes' => ['CLI_AJEPER'], 'refs' => 9]);
    $this->assertSame('typo', $r['veredicto']);
    $this->assertSame('LOC_HUACHIPA', $r['local_id']);
}

public function test_marca_familia_cuando_varios_locales_empiezan_igual(): void
{
    $r = $this->clasificador()->clasificar('MAKRO', ['clientes' => ['CLI_BABEL'], 'refs' => 103]);
    $this->assertSame('familia', $r['veredicto']);
}

public function test_marca_generica_cuando_son_varios_clientes(): void
{
    $r = $this->clasificador()->clasificar('INSUMOS', ['clientes' => ['CLI_AJEPER', 'CLI_CODISAL'], 'refs' => 30]);
    $this->assertSame('generica', $r['veredicto']);
}

public function test_aceite_es_generica_por_venir_de_un_parentesis(): void
{
    $r = $this->clasificador()->clasificar('ACEITE', ['clientes' => ['CLI_AJEPER'], 'refs' => 30]);
    $this->assertSame('generica', $r['veredicto']);
    $this->assertStringContainsString('paréntesis', $r['detalle']);
}

public function test_marca_basura(): void
{
    $this->assertSame('basura', $this->clasificador()->clasificar('----', ['clientes' => [], 'refs' => 15])['veredicto']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="ExtractorCandidatosTest|ClasificadorTest"`
Expected: FAIL, clases no encontradas.

- [ ] **Step 3: Implement both classes**

`ExtractorCandidatos::desdeFragmento()`: normaliza espacios, parte en palabras con `preg_split('/[^\p{L}\p{N}]+/u')`, descarta las de menos de `minimo_caracteres`, numéricas, y las que estén en `palabras_ignoradas` (comparando en mayúsculas sin tildes). Genera 1-gramas y n-gramas hasta `maximo_ngrama` con las palabras **restantes y en su orden**, conservando el texto original de cada palabra; descarta las frases de más de `maximo_candidato` caracteres; de-duplica por `clave()` conservando el orden.

`Clasificador::clasificar()`:
1. `basura` si `preg_replace('/[^\p{L}\p{N}]+/u', '', $frase) === ''`.
2. `generica` si `count(array_unique($contexto['clientes'])) > 1`, **o** si la única forma en que el catálogo cubre la frase es una variante entre paréntesis de alguna `palabra_clave` (detectarla: la frase matchea `\b(<variante>)\b` para una variante entre `(...)`, y no matchea ninguna variante sin paréntesis). `detalle`: "coincide con la variante «(ACEITE)» de LOC_…; parece un producto y no un cliente".
3. `familia` si hay **2 o más** locales cuya `palabra_clave` (alguna variante) **empieza** con `$frase . ' '` (comparación insensible a mayúsculas y tildes).
4. `cubierto_parseo` si algún local cubre la frase (misma regla de dos sentidos del analizador).
5. `typo` si ningún local la cubre y algún local tiene `similar_text()` ≥ `parecido_porcentaje` o `levenshtein()` ≤ `parecido_distancia` contra la frase, comparando en normalizado.
6. `nuevo` en cualquier otro caso.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="ExtractorCandidatosTest|ClasificadorTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Ubicaciones/ExtractorCandidatos.php app/Ubicaciones/Clasificador.php tests/Feature/Ubicaciones
git commit -m "feat(ubicaciones): extractor de candidatos y clasificador contra el catalogo"
```

---

### Task 3: Tabla de descartes + capa de datos

**Files:**
- Create: `database/migrations/2026_11_19_120000_create_ubicaciones_descartes.php`
- Create: `sql/ubicaciones.sql`
- Create: `app/Database/DBUbicaciones.php`
- Test: `tests/Feature/Ubicaciones/DBUbicacionesTest.php`

**Interfaces:**
- Produces: `App\Database\DBUbicaciones`
  - `refsSinRuta(): array` — `[['ref' => string, 'titulo' => string], ...]`; incluye las refs con título vacío (con `titulo` = `''`).
  - `catalogos(): array` — `['locales' => [...], 'distritos' => [...]]` con las columnas que consume `AnalizadorTitulos` (`id`, `palabra_clave`) **más `cliente_id` en `locales`**, que `AnalisisTitulos` necesita para el contexto del clasificador.
  - `descartes(string $tipo = 'palabra'): array` — `[clave => ['texto' => string, 'motivo' => ?string]]`.
  - `descartar(string $tipo, string $clave, string $texto, ?string $motivo, ?int $usuarioId): void`
  - `restaurar(string $tipo, string $clave): void`
  - `refsConRuta(array $refs): array` — subconjunto de `$refs` que ya tienen `ruta_id`.

- [ ] **Step 1: Write the failing test**

`DBUbicacionesTest` usa `RefreshDatabase` y tablas mínimas propias (no las de producción): crea `locales`, `ubigeo_distritos` y `referencias` con `Schema::create` en el `setUp` si no existen, inserta 3 refs (una con `ruta_id`, una sin, una con título vacío) y verifica:

```php
public function test_refs_sin_ruta_incluye_las_de_titulo_vacio(): void
{
    $refs = collect(DBUbicaciones::refsSinRuta())->pluck('ref')->all();
    $this->assertContains('SIN_RUTA', $refs);
    $this->assertContains('VACIA', $refs);
    $this->assertNotContains('CON_RUTA', $refs);
}

public function test_descartar_y_restaurar(): void
{
    DBUbicaciones::descartar('palabra', 'abc123', 'MAQUILA AGUA', 'no es un local', 1);
    $this->assertArrayHasKey('abc123', DBUbicaciones::descartes('palabra'));
    DBUbicaciones::restaurar('palabra', 'abc123');
    $this->assertArrayNotHasKey('abc123', DBUbicaciones::descartes('palabra'));
}

public function test_descartar_dos_veces_no_duplica(): void
{
    DBUbicaciones::descartar('palabra', 'abc123', 'MAQUILA AGUA', null, 1);
    DBUbicaciones::descartar('palabra', 'abc123', 'MAQUILA AGUA', null, 1);
    $this->assertSame(1, DB::table('ubicaciones_descartes')->count());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DBUbicacionesTest`
Expected: FAIL, tabla `ubicaciones_descartes` no existe.

- [ ] **Step 3: Write the migration, the `sql/ubicaciones.sql` DDL and `DBUbicaciones`**

La migración crea exactamente la tabla del spec (§8): `id CHAR(32)` PK, `tipo VARCHAR(20)`, `clave VARCHAR(500)`, `motivo VARCHAR(255) NULL`, `usuario_id BIGINT UNSIGNED NULL`, `created_at TIMESTAMP NULL`, índice por `tipo`. `descartar()` usa `updateOrInsert`. `refsSinRuta()` filtra `(ruta_id IS NULL OR ruta_id = '')` y ordena por `ref`. `catalogos()` devuelve `locales` con **`id`, `palabra_clave` y `cliente_id`** (este último lo consume `AnalisisTitulos` en Task 4) y `distritos` con `ubigeo` y `distrito`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DBUbicacionesTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations sql/ubicaciones.sql app/Database/DBUbicaciones.php tests/Feature/Ubicaciones/DBUbicacionesTest.php
git commit -m "feat(ubicaciones): tabla de descartes y capa de datos del modulo"
```

---

### Task 4: Orquestación del análisis

**Files:**
- Create: `app/Ubicaciones/AnalisisTitulos.php`
- Test: `tests/Feature/Ubicaciones/AnalisisTitulosTest.php`

**Interfaces:**
- Consumes: `AnalizadorTitulos`, `ExtractorCandidatos`, `Clasificador`, `DBUbicaciones`.
- Produces: `App\Ubicaciones\AnalisisTitulos::__construct(AnalizadorTitulos $a, ExtractorCandidatos $e, Clasificador $c)` y `->calcular(array $refs): array` donde `$refs` son filas `['ref','titulo']` (las de `DBUbicaciones::refsSinRuta()`), devolviendo:
  - `refs` (int), `titulos` (int), `fragmentos` (int), `ya_resolverian` (int)
  - `candidatos`: lista de `['frase','clave','refs','titulos','veredicto','local_id','detalle','ejemplo_ref','ejemplo_titulo','lado','descartado']` ordenada por `refs` desc y luego por `frase`.
  - `detalle_fragmentos`: lista de `['fragmento','lado','refs','titulos','ejemplo_ref','ejemplo_titulo']` ordenada alfabéticamente.

- [ ] **Step 1: Write the failing test**

Fixture de 4 refs en memoria (sin base):

```php
private function refs(): array
{
    return [
        ['ref' => 'A1', 'titulo' => 'ENVIO PT MAQUILA AGUA - CODISAL JULIACA / CARGA 01.01'],
        ['ref' => 'A2', 'titulo' => 'ENVIO PT MAQUILA AGUA - CODISAL CUSCO / CARGA 02.01'],
        ['ref' => 'A3', 'titulo' => 'ENVIO DE PT PLANTA PUCALLPA(CORONADO) - CEDI TINGO MARIA'],
        ['ref' => 'A4', 'titulo' => 'ENVIO PT AJEPER CARAL - BABEL MAXO'],
        ['ref' => 'A5', 'titulo' => ''],
    ];
}

public function test_cuenta_cada_grupo(): void
{
    $r = $this->analisis()->calcular($this->refs());
    $this->assertSame(5, $r['refs']);
    $this->assertSame(5, $r['titulos']);
    $this->assertSame(1, $r['ya_resolverian']);          // A4
}

public function test_maquila_agua_es_el_candidato_mas_fuerte_y_es_nuevo(): void
{
    $r = $this->analisis()->calcular($this->refs());
    $primero = $r['candidatos'][0];
    $this->assertSame('MAQUILA AGUA', $primero['frase']);
    $this->assertSame(2, $primero['refs']);
    $this->assertSame('nuevo', $primero['veredicto']);
    $this->assertSame('A1', $primero['ejemplo_ref']);
}

public function test_pucallpa_sale_como_cubierto_por_parseo(): void
{
    $r = $this->analisis()->calcular($this->refs());
    $fila = collect($r['candidatos'])->firstWhere('frase', 'PUCALLPA');
    $this->assertNotNull($fila);
    $this->assertSame('cubierto_parseo', $fila['veredicto']);
    $this->assertSame('LOC_PUCALLPA', $fila['local_id']);
}

public function test_los_fragmentos_no_llevan_numeros_ni_duplicados(): void
{
    $r = $this->analisis()->calcular($this->refs());
    $fragmentos = collect($r['detalle_fragmentos'])->pluck('fragmento')->all();
    $this->assertContains('DE PT MAQUILA AGUA', $fragmentos);
    $this->assertSame(count($fragmentos), count(array_unique($fragmentos)));
}
```

(Cada test arma el `AnalisisTitulos` con catálogos fijos: `LOC_CARAL|CARAL`, `LOC_BABEL_MAXO|MAXO`, `LOC_PUCALLPA|PUCALLPA`, `LOC_CODISAL_JULIACA|CODISAL JULIACA`, `LOC_CODISAL_CUSCO|CODISAL CUSCO` y una ref de descartes vacía.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AnalisisTitulosTest`
Expected: FAIL, clase no encontrada.

- [ ] **Step 3: Implement `AnalisisTitulos::calcular()`**

Pasos: (a) agrupar por título normalizado guardando refs y conteos; (b) por cada título distinto analizar y, si `motivo !== 'ok'`, saltar; (c) por cada lado sin resolver acumular el fragmento y, para cada frase de `desdeFragmento()`, sumar `refs`/`titulos` y —para el contexto del clasificador— resolver **el lado contrario** con el analizador y juntar su `cliente_id` (pidiendo `cliente_id` a los catálogos: por eso `DBUbicaciones::catalogos()` trae `cliente_id` en `locales`); (d) clasificar una sola vez por frase al final; (e) devolver la estructura de `Interfaces`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AnalisisTitulosTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Ubicaciones/AnalisisTitulos.php tests/Feature/Ubicaciones/AnalisisTitulosTest.php
git commit -m "feat(ubicaciones): orquestacion del analisis y agregacion de candidatos"
```

---

### Task 5: Pantalla "Títulos sin ruta" + menú Ubicaciones

**Files:**
- Create: `app/Http/Controllers/Ubicaciones/TitulosController.php`
- Create: `resources/views/lupita/ubicaciones/titulos.blade.php`
- Modify: `routes/web.php` (dentro del grupo `/lupita` con `role:admin`)
- Modify: `resources/views/layouts/app.blade.php:267-275` (desplegable Ubicaciones)
- Test: `tests/Feature/Ubicaciones/TitulosTest.php`

**Interfaces:**
- Consumes: `DBUbicaciones::refsSinRuta()`, `AnalisisTitulos::calcular()`, `DBReprocesar::spDisponible()` (Task 7; hasta entonces devolver `true` fijo en la vista).
- Produces: ruta `GET /lupita/ubicaciones/titulos` → nombre `lupita.ubicaciones.titulos`, y el desplegable del menú.

- [ ] **Step 1: Write the failing test**

```php
public function test_un_admin_ve_la_pantalla(): void
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin)->get(route('lupita.ubicaciones.titulos'))
        ->assertOk()->assertSee('Títulos sin ruta');
}

public function test_un_usuario_sin_rol_no_entra(): void
{
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('lupita.ubicaciones.titulos'))->assertForbidden();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TitulosTest`
Expected: FAIL, ruta no definida.

- [ ] **Step 3: Add the routes and the menu entry**

En `routes/web.php`, junto al grupo de locales, con el **mismo** middleware `role:admin` que usa `/locales`. En `layouts/app.blade.php`, reemplazar la entrada suelta "Locales" por un desplegable **Ubicaciones** con: Títulos sin ruta, Palabras repetidas, Locales, y (en fases siguientes) Clientes y Google Maps.

- [ ] **Step 4: Implement the controller and the view**

`TitulosController@index` calcula el análisis y pasa `familia` filtrada. La vista muestra: cabecera con totales (`refs`, `titulos`, `fragmentos`, `ya_resolverian`), el aviso del SP, y una tabla por **título normalizado** con columnas `Título · Refs · Por qué falla · Origen · Destino · Acciones`. Los bloques `ya_resolveria` van en un acordeón aparte con la etiqueta "no necesita local nuevo". Filtros por motivo y texto en cliente, como `lupita/locales/index.blade.php`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TitulosTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Ubicaciones/TitulosController.php resources/views/lupita/ubicaciones/titulos.blade.php routes/web.php resources/views/layouts/app.blade.php tests/Feature/Ubicaciones/TitulosTest.php
git commit -m "feat(ubicaciones): pantalla de titulos sin ruta y menu Ubicaciones"
```

---

### Task 6: Pantalla "Palabras repetidas"

**Files:**
- Create: `app/Http/Controllers/Ubicaciones/PalabrasController.php`
- Create: `resources/views/lupita/ubicaciones/palabras.blade.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/LocalesController.php:38-47` (`crear()` acepta `nombre` y `palabra_clave` por query)
- Test: `tests/Feature/Ubicaciones/PalabrasTest.php`
- Test: `tests/Feature/Ubicaciones/LocalesPrefillTest.php`

**Interfaces:**
- Consumes: `AnalisisTitulos::calcular()`, `DBUbicaciones::descartes()/descartar()/restaurar()`.
- Produces rutas: `lupita.ubicaciones.palabras` (GET), `lupita.ubicaciones.palabras.exportar` (GET), `lupita.ubicaciones.palabras.descartar` (POST), `lupita.ubicaciones.palabras.restaurar` (POST).

- [ ] **Step 1: Write the failing tests**

```php
// PalabrasTest
public function test_muestra_el_ranking(): void
{
    $admin = $this->admin();
    $this->actingAs($admin)->get(route('lupita.ubicaciones.palabras'))
        ->assertOk()->assertSee('Palabras repetidas');
}

// [ReviewFocus 5]
public function test_descartar_y_restaurar_desde_la_pantalla(): void
{
    $admin = $this->admin();
    $this->actingAs($admin)->post(route('lupita.ubicaciones.palabras.descartar'), [
        'clave' => '0123456789abcdef0123456789abcdef', 'texto' => 'MAQUILA AGUA',
    ])->assertRedirect();
    $this->assertDatabaseHas('ubicaciones_descartes', ['tipo' => 'palabra']);

    $this->actingAs($admin)->post(route('lupita.ubicaciones.palabras.restaurar'), [
        'clave' => '0123456789abcdef0123456789abcdef',
    ])->assertRedirect();
    $this->assertDatabaseMissing('ubicaciones_descartes', ['tipo' => 'palabra']);
}

public function test_el_prellenado_llega_al_formulario_de_local(): void
{
    $admin = $this->admin();
    $resp = $this->actingAs($admin)->get(route('lupita.locales.crear', [
        'nombre' => 'PLANTA MAQUILA AGUA', 'palabra_clave' => 'MAQUILA AGUA',
    ]));
    $resp->assertOk()->assertSee('PLANTA MAQUILA AGUA')->assertSee('MAQUILA AGUA');
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="PalabrasTest|LocalesPrefillTest"`
Expected: FAIL.

- [ ] **Step 3: Implement the controller, the routes and the view**

`index()`: calcula, cruza con `descartes('palabra')` marcando `descartado`, y parte en dos colecciones: `candidatos` (frases ≤ `maximo_candidato`) y `fragmentos` (la otra solapa). `descartar()`/`restaurar()`: validan `clave` como `size:32` y `texto` como `max:500`, y redirigen con flash. `exportar()`: `response()->streamDownload` con CSV (refs, títulos, frase, veredicto, local).

La vista: dos solapas con `nav-tabs` de Bootstrap (sin JS propio), tabla con las columnas del spec, filtros por veredicto y texto, checkbox "ver descartados", botones `Crear`/`Variante`/`Ver`/`Descartar`/`Restaurar`. `Crear` es un link a `route('lupita.locales.crear', ['nombre' => ..., 'palabra_clave' => ...])`. `Variante` es un link a `route('lupita.locales.editar', $local)`, solo cuando `local_id` existe.

`LocalesController::crear()`: leer `nombre` y `palabra_clave` de `$request->query()` y pasarlos como valores por defecto del form (el form ya usa `old('nombre', $local->nombre ?? '')`, así que alcanza con pasar un `$local` parcial); validar que no superen 100 caracteres y truncar si vienen más largos.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="PalabrasTest|LocalesPrefillTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Ubicaciones/PalabrasController.php resources/views/lupita/ubicaciones/palabras.blade.php routes/web.php app/Http/Controllers/LocalesController.php tests/Feature/Ubicaciones
git commit -m "feat(ubicaciones): ranking de palabras con descartes, exportacion y prellenado de local"
```

---

### Task 7: Reprocesar con el stored procedure

**Files:**
- Create: `app/Database/DBReprocesar.php`
- Create: `app/Http/Controllers/Ubicaciones/ReprocesarController.php`
- Create: `resources/views/lupita/ubicaciones/reporte.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/lupita/ubicaciones/titulos.blade.php` (botones)
- Test: `tests/Feature/Ubicaciones/ReprocesarTest.php`

**Interfaces:**
- Consumes: `DBUbicaciones::refsConRuta()`.
- Produces:
  - `DBReprocesar::spDisponible(): bool`
  - `DBReprocesar::procesar(array $refs, ?callable $llamar = null): array` → `['total' => int, 'ok' => int, 'error' => int, 'omitidas' => string[], 'filas' => [['ref' => string, 'ok' => bool, 'ruta_id' => ?string, 'mensaje' => ?string], ...]]`
  - `DBReprocesar::llamador(): callable` — `fn(string $ref): array` que hace `DB::select("CALL sp_actualizar_ruta_id(?)", [$ref])` y devuelve la primera fila.
  - Rutas `GET /lupita/ubicaciones/reprocesar/estado` (JSON) y `POST /lupita/ubicaciones/reprocesar` (HTML del reporte).

- [ ] **Step 1: Write the failing tests**

```php
public function test_ignora_refs_que_ya_tienen_ruta(): void
{
    // fixture: ref CON_RUTA con ruta_id ya puesto
    $llamadas = [];
    $reporte = DBReprocesar::procesar(['CON_RUTA'], function (string $ref) use (&$llamadas) {
        $llamadas[] = $ref;
        return ['v_ruta_id' => 'RUT_X'];
    });

    $this->assertSame([], $llamadas);                 // [ReviewFocus 6]
    $this->assertContains('CON_RUTA', $reporte['omitidas']);
    $this->assertSame(0, $reporte['ok']);
}

public function test_un_error_no_corta_el_lote(): void
{
    $reporte = DBReprocesar::procesar(['R1', 'R2', 'R3'], function (string $ref) {
        if ($ref === 'R2') {
            throw new \RuntimeException('Origen no encontrado: X');
        }
        return ['v_ruta_id' => 'RUT_' . $ref];
    });

    $this->assertSame(2, $reporte['ok']);
    $this->assertSame(1, $reporte['error']);
    $this->assertSame('Origen no encontrado: X', collect($reporte['filas'])->firstWhere('ref', 'R2')['mensaje']);
    $this->assertSame(3, $reporte['total']);
}

// [ReviewFocus 1]
public function test_si_el_sp_no_esta_el_endpoint_lo_avisa_y_no_escribe(): void
{
    // se marca information_schema como sin procedimiento
    $this->mock(DBReprocesar::class, fn ($m) => $m->shouldReceive('spDisponible')->andReturn(false));
    $this->actingAs($this->admin())
        ->post(route('lupita.ubicaciones.reprocesar'), ['refs' => ['R1']])
        ->assertSessionHas('error');
}

public function test_valida_las_refs(): void
{
    $this->actingAs($this->admin())
        ->post(route('lupita.ubicaciones.reprocesar'), ['refs' => ["R1'); DROP TABLE referencias;--"]])
        ->assertSessionHasErrors('refs.0');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ReprocesarTest`
Expected: FAIL.

- [ ] **Step 3: Implement `DBReprocesar`**

`spDisponible()`: `information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_actualizar_ruta_id'`, resultado cacheado por request. `procesar()`: filtra las refs con `refsConRuta()`, llama una por una dentro de `try/catch`, arma el reporte. `llamador()` usa `DB::select` (no `DB::statement`, porque el SP devuelve un result set) y devuelve la primera fila.

- [ ] **Step 4: Implement the controller, routes and views**

`ReprocesarController@estado`: JSON `{disponible, message, pendientes, ya_resolverian}`. `@procesar`: valida `refs` como `array|max:700` y `refs.*` con `regex:/^[A-Z0-9\-]{3,10}$/`; si el SP no está disponible, redirige con `error` y el texto de instalación; si está, `set_time_limit(300)`, procesa y devuelve el reporte. Los botones de `titulos.blade.php` hacen POST con las refs del grupo; si el SP no está, aparecen deshabilitados con el `title` explicando.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=ReprocesarTest`
Expected: PASS.

- [ ] **Step 6: End-to-end verification against the real database**

Con el SP ya instalado: entrar a `/lupita/ubicaciones/titulos`, reprocesar las refs del bloque "ya resolverían", y comprobar que el contador de refs sin ruta baja en la misma cantidad que el reporte dice `ok`. Documentar el antes/después.

- [ ] **Step 7: Commit**

```bash
git add app/Database/DBReprocesar.php app/Http/Controllers/Ubicaciones/ReprocesarController.php resources/views/lupita/ubicaciones routes/web.php tests/Feature/Ubicaciones/ReprocesarTest.php
git commit -m "feat(ubicaciones): reprocesar refs con sp_actualizar_ruta_id y reporte por ref"
```

---

### Task 8: Cierre

**Files:**
- Modify: `docs/superpowers/specs/2026-11-19-ubicaciones-design.md` (§3c: el lote pasa a un solo request, con la medición real)

- [ ] **Step 1: Run the full suite**

Run: `php artisan test`
Expected: PASS. Si algún test preexistente falla, verificar si ya fallaba antes (`git stash` y repetir) antes de tocarlo.

- [ ] **Step 2: Update the spec with the measured decision**

Reemplazar en §3c la frase del lote de 50 por: "un solo request con `set_time_limit(300)`; medido: 6,85 ms por `CALL`, ~4,7 s para las 691 refs".

- [ ] **Step 3: Verify the counters in the UI match the DB**

`SELECT COUNT(*) FROM referencias WHERE ruta_id IS NULL OR ruta_id = ''` contra lo que muestra la cabecera.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-11-19-ubicaciones-design.md
git commit -m "docs(ubicaciones): lote de reproceso en un solo request con medicion real"
```

# Predicción de `presenta_para_carga` — plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Predecir a qué hora se presentará para cargar un camión (y a qué hora empieza a cargar) anclando en su último hito conocido, y medir el acierto contra las referencias ya cerradas.

**Architecture:** Tres capas. `App\Prediccion\*` es cálculo puro sin base de datos ni reloj de sistema (el reloj entra como parámetro), testeable con PHPUnit. `App\Database\DBPrediccion` trae los datos en pocas consultas por request. `App\Prediccion\Servicio` los une y alimenta tres superficies: dos pantallas Blade, la API pública y el comando de diagnóstico. El motor usa la cadena física (etapas por ruta + traslado) y la calibración empírica del hueco real `fin_descargue → presenta`, con ventanas de no-actividad inferidas para el descanso y el almuerzo.

**Tech Stack:** PHP 8.3, Laravel 12, MySQL (sólo lectura para el motor), Blade + Bootstrap 5, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-11-13-prediccion-presenta-carga-design.md`

## Global Constraints

- PHP 8.3 / Laravel 12. Capas de datos estáticas en `app/Database/DB*`, payloads `stdClass`, vistas Blade con Bootstrap 5 (iconos `bi-`), textos y comentarios en español.
- El motor puro (`app/Prediccion/*`) **no** toca `DB::` ni llama `Carbon::now()`: la hora de corte entra como `ahora`.
- Tiempos en minutos enteros; fechas en `Y-m-d H:i:s` (hora local del servidor); `Carbon` para la aritmética.
- Hitos con fecha anterior a `2000-01-01` se descartan (regla del módulo de tiempos, `DBComportamiento::base_sql():291-294`).
- Tope de etapa `2880` min y tope de hueco `10080` min (7 días), copiados de `DBComportamiento::TOPES` y `CICLO_MAX`.
- API pública: `/api/*` sin `auth` ni `permission` (regla del repo, `routes/web.php:48-51`). Pantallas: `auth` + `permission:ver tiempos`.
- Validación inválida en API ⇒ 422 JSON con `success => false`, nunca redirect (`TiemposPromedioApiController::error_validacion():72`).
- Si `prediccion_config` no existe o la conexión falla, el cálculo usa los defaults de `config/prediccion.php` y no revienta (convención de `scoring_config`).
- El servidor no tiene consola: la migración es la fuente de verdad y su DDL se replica a mano en `sql/prediccion.sql`.
- Suites: `vendor\bin\phpunit --testsuite Unit` y `--testsuite Feature` deben quedar verdes. No hay MySQL en este entorno: la base de datos se verifica con `php artisan prediccion:diagnostico` en el servidor, no con PHPUnit.
- Valores fijos de `config/prediccion.php` (16 claves): `lookback_dias=120`, `min_pares_ruta=3`, `min_pares_entidad=3`, `pares_recientes_max=20`, `min_dias_actividad=5`, `min_hitos_bin=2`, `granularidad_min=30`, `bins_horarios=8`, `max_desplazamiento_min=600`, `traslado_defecto_min=240`, `muerto_defecto_min=60`, `espera_carga_defecto_min=90`, `frescura_horizonte_h=24`, `edad_ancla_max_h=72`, `tope_hueco_min=10080`, `tope_etapa_min=2880`.

## Review Focus

1. **Ref sin `ruta_id`, ruta sin `origen_id` o planta sin compilado** — un camión que no puede ubicarse no debe producir una hora silenciosa: la predicción se marca `sin_datos` con advertencia (Task 4, `test_sin_traslado_ni_hueco_no_inventa_hora`).
2. **Hitos viejos o invertidos** (la base tiene hitos de 1998 y pares con `inicio` posterior a `fin`) — los anteriores a `2000-01-01` se descartan antes de anclar (Task 6) y, si el ancla queda a mitad de viaje con la etapa ya vencida, `t_fin` se acota a `ahora` y se advierte `etapa_vencida` (Task 4, `test_etapa_vencida_se_acota_a_ahora_y_advierte`).
3. **Camión detenido más de 24 h** — la predicción nunca puede caer antes de `ahora`: se devuelve `max(prediccion, ahora)` con el aviso `atrasado` (Task 4, `test_prediccion_en_el_pasado_se_marca_atrasada`).
4. **Ref que ya presentó** (`presenta_para_carga` no nulo y ≤ `ahora`) — no se predice: se informa `ya_presento` (Task 4, `test_ref_que_ya_presento`).
5. **Placa con varios choferes o chofer con varias placas** — la calibración por conductor sólo se usa si el chofer concentra más del 50 % de los viajes de la placa (misma regla que `ComportamientoController::chofer_habitual():77`), si no calibra la placa (Task 7, `test_clave_calibracion_respeta_el_50_por_ciento`).

---

## Estructura de archivos

| Archivo | Responsabilidad |
|---|---|
| `config/prediccion.php` | Valores por defecto de todos los parámetros |
| `app/Prediccion/Estadistica.php` | mediana, cuantiles, desviación, bin horario (puro) |
| `app/Prediccion/Calibrador.php` | huecos reales → base por entidad/par de rutas + corrección horaria (puro) |
| `app/Prediccion/Ventanas.php` | intervalos → franjas inactivas; desplazar una fecha fuera de ellas (puro) |
| `app/Prediccion/MotorPresenta.php` | la cadena completa: ancla → `t_fin` → hueco → presenta; estado, evidencia, confianza (puro) |
| `app/Prediccion/Parametros.php` | defaults + overrides de `prediccion_config` con fallback |
| `app/Prediccion/Aciertos.php` | MAE, sesgo, % dentro de tolerancias, resumen y comparación (puro) |
| `app/Prediccion/BaseHistograma.php` | réplica del método actual (`veredicto_carga`) para la línea base (puro) |
| `app/Prediccion/Servicio.php` | orquesta datos + motor: `predecir()`, `backtest()`, `candidatos_viaje()`, catálogos |
| `app/Database/DBPrediccion.php` | todas las consultas del módulo |
| `app/Database/DBRefsActualizar.php` | upsert de `referencias` extraído de `ActualizarRefsController` |
| `app/Http/Controllers/PrediccionController.php` | pantallas calculadora y aciertos |
| `app/Http/Controllers/Api/PrediccionApiController.php` | API pública JSON |
| `resources/views/lupita/prediccion_presenta.blade.php` | calculadora |
| `resources/views/lupita/prediccion_aciertos.blade.php` | tabla de aciertos |
| `resources/views/lupita/partials/prediccion_resultado.blade.php` | tarjeta de resultado compartida |
| `resources/views/lupita/partials/prediccion_parametros.blade.php` | tarjeta de parámetros editables |
| `database/migrations/2026_11_13_120000_create_prediccion_config_table.php` | tabla clave/valor |
| `sql/prediccion.sql` | DDL para aplicar a mano en el servidor |
| `routes/web.php`, `routes/console.php`, `routes` del menú | registro de rutas y comando |
| `resources/views/layouts/app.blade.php` | enlace en el menú |
| `resources/views/lupita/comportamiento_ficha.blade.php` | botón «Predecir carga» |
| `tests/Unit/Prediccion/*.php`, `tests/Feature/Api/*.php` | pruebas |

---

### Task 1: Estadística pura

**Files:**
- Create: `app/Prediccion/Estadistica.php`
- Test: `tests/Unit/Prediccion/EstadisticaTest.php`

**Interfaces:**
- Produces: `App\Prediccion\Estadistica::mediana(array $valores): ?float`, `::cuantil(array $valores, float $p): ?float`, `::desviacion(array $valores): ?float`, `::bin_horario(int $hora, int $bins = 8): int`, `::iqr(array $valores): ?float`. Lista vacía ⇒ `null`. Las funciones ordenan una copia, no mutan el arreglo recibido.

- [ ] **Step 1: Write the failing test**

```php
// tests/Unit/Prediccion/EstadisticaTest.php
namespace Tests\Unit\Prediccion;

use App\Prediccion\Estadistica;
use Tests\TestCase;

class EstadisticaTest extends TestCase
{
    public function test_mediana_impar_y_par(): void
    {
        $this->assertSame(30.0, Estadistica::mediana([40, 10, 30]));
        $this->assertSame(25.0, Estadistica::mediana([10, 20, 30, 40]));
        $this->assertNull(Estadistica::mediana([]));
    }

    public function test_cuantil_interpola_lineal(): void
    {
        $datos = [10, 20, 30, 40, 50];
        $this->assertSame(20.0, Estadistica::cuantil($datos, 0.25));
        $this->assertSame(40.0, Estadistica::cuantil($datos, 0.75));
        $this->assertSame(10.0, Estadistica::cuantil($datos, 0.0));
        $this->assertSame(50.0, Estadistica::cuantil($datos, 1.0));
    }

    public function test_iqr_y_desviacion(): void
    {
        $datos = [10, 20, 30, 40, 50];
        $this->assertSame(20.0, Estadistica::iqr($datos));
        $this->assertEqualsWithDelta(14.14, Estadistica::desviacion($datos), 0.01);
    }

    public function test_bin_horario_en_ocho_franjas_de_tres_horas(): void
    {
        $this->assertSame(0, Estadistica::bin_horario(0));
        $this->assertSame(0, Estadistica::bin_horario(2));
        $this->assertSame(1, Estadistica::bin_horario(3));
        $this->assertSame(7, Estadistica::bin_horario(23));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor\bin\phpunit --testsuite Unit --filter EstadisticaTest`
Expected: FAIL — `Class "App\Prediccion\Estadistica" not found`.

- [ ] **Step 3: Implement `app/Prediccion/Estadistica.php`**

`<?php namespace App\Prediccion;` con `final class Estadistica` y métodos estáticos. `cuantil` usa interpolación lineal sobre una copia ordenada con `sort($copia, SORT_NUMERIC)`; `$p` se acota a `[0,1]`. `desviacion` es la desviación estándar poblacional (`sqrt(sum((x-media)^2)/n)`). `bin_horario` devuelve `intdiv($hora % 24, intdiv(24, $bins))`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor\bin\phpunit --testsuite Unit --filter EstadisticaTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Prediccion/Estadistica.php tests/Unit/Prediccion/EstadisticaTest.php
git commit -m "feat(prediccion): estadistica pura del motor"
```

---

### Task 2: Calibrador del hueco real

**Files:**
- Create: `app/Prediccion/Calibrador.php`
- Test: `tests/Unit/Prediccion/CalibradorTest.php`

**Interfaces:**
- Consumes: `Estadistica::mediana()`, `::cuantil()`, `::bin_horario()`.
- Produces:
  - `Calibrador::calibrar(array $pares, int $min_ruta = 3, int $min_entidad = 3, int $bins = 8): stdClass` — cada par es `(object)['ancla' => Carbon, 'presenta' => Carbon, 'ruta_ancla' => ?string, 'ruta_siguiente' => ?string, 'entidad' => string]`. Devuelve `->par_rutas` (array `"rutaAncla>rutaSig" => ['n','base_min','p25_min','p75_min']`), `->entidad` (array `entidad => ['n','base_min','p25_min','p75_min']`), `->curva` (array de 8 `['n','residual_min']`), `->global` (`['n','base_min','p25_min','p75_min']`).
  - `Calibrador::estimar(stdClass $calibracion, ?string $ruta_ancla, ?string $ruta_siguiente, string $entidad, int $hora_ancla): stdClass` — `->gap_min`, `->p25_min`, `->p75_min`, `->base_min`, `->residual_min`, `->n`, `->nivel` (`medido|parcial|cadena`).
- Regla de nivel: `medido` si el par de rutas tiene n ≥ `$min_ruta`; `parcial` si la entidad tiene n ≥ `$min_entidad` o la base global tiene n ≥ `$min_entidad`; `cadena` si no hay nada (`gap_min = null`).

- [ ] **Step 1: Write the failing test**

```php
// tests/Unit/Prediccion/CalibradorTest.php
namespace Tests\Unit\Prediccion;

use App\Prediccion\Calibrador;
use Carbon\Carbon;
use Tests\TestCase;

class CalibradorTest extends TestCase
{
    private function pares(int $n, int $gapMin, string $rutaAncla = 'R1', string $rutaSig = 'R2',
                           string $horaAncla = '08:00', string $entidad = 'ABC123'): array
    {
        $pares = [];
        for ($i = 0; $i < $n; $i++) {
            $ancla = Carbon::parse("2026-11-0" . ($i + 2) . " $horaAncla");
            $pares[] = (object) [
                'ancla' => $ancla,
                'presenta' => $ancla->copy()->addMinutes($gapMin),
                'ruta_ancla' => $rutaAncla,
                'ruta_siguiente' => $rutaSig,
                'entidad' => $entidad,
            ];
        }

        return $pares;
    }

    public function test_par_de_rutas_con_tres_pares_es_medido(): void
    {
        $cal = Calibrador::calibrar($this->pares(5, 300));
        $est = Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 8);

        $this->assertSame('medido', $est->nivel);
        $this->assertSame(300, $est->gap_min);
        $this->assertSame(300, $est->p25_min);
        $this->assertSame(5, $est->n);
    }

    public function test_menos_de_tres_pares_no_calibra(): void
    {
        $cal = Calibrador::calibrar($this->pares(2, 300));
        $est = Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 8);

        $this->assertSame('cadena', $est->nivel);
        $this->assertNull($est->gap_min);
    }

    public function test_correccion_por_hora_del_ancla(): void
    {
        // misma placa: de día vuelve en 4 h, de noche espera hasta la mañana (+6 h)
        $pares = array_merge(
            $this->pares(4, 240, 'R1', 'R2', '08:00'),
            $this->pares(4, 600, 'R1', 'R2', '21:00')
        );
        $cal = Calibrador::calibrar($pares);

        $deDia = Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 8);
        $deNoche = Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 21);

        $this->assertGreaterThan($deDia->gap_min, $deNoche->gap_min);
        $this->assertSame(240, $deDia->gap_min);
        $this->assertSame(600, $deNoche->gap_min);
    }

    public function test_descarta_huecos_invertidos_o_mayores_a_siete_dias(): void
    {
        $pares = $this->pares(3, 300);
        $pares[] = (object) ['ancla' => Carbon::parse('2026-11-09 08:00'),
            'presenta' => Carbon::parse('2026-11-08 08:00'),
            'ruta_ancla' => 'R1', 'ruta_siguiente' => 'R2', 'entidad' => 'ABC123'];
        $pares[] = (object) ['ancla' => Carbon::parse('2026-11-10 08:00'),
            'presenta' => Carbon::parse('2026-11-30 08:00'),
            'ruta_ancla' => 'R1', 'ruta_siguiente' => 'R2', 'entidad' => 'ABC123'];

        $cal = Calibrador::calibrar($pares);

        $this->assertSame(3, $cal->par_rutas['R1>R2']['n']);
        $this->assertSame(300, Calibrador::estimar($cal, 'R1', 'R2', 'ABC123', 8)->gap_min);
    }

    public function test_entidad_sin_par_de_rutas_calibra_parcial(): void
    {
        $cal = Calibrador::calibrar($this->pares(4, 300, 'R1', 'R2'));
        $est = Calibrador::estimar($cal, 'R7', 'R9', 'ABC123', 8);

        $this->assertSame('parcial', $est->nivel);
        $this->assertSame(300, $est->gap_min);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor\bin\phpunit --testsuite Unit --filter CalibradorTest`
Expected: FAIL — clase no existe.

- [ ] **Step 3: Implement `app/Prediccion/Calibrador.php`**

`calibrar()`: recorre los pares, calcula `hueco = presenta - ancla` en minutos y descarta fuera de `(0, 10080]`. Agrupa por `"$rutaAncla>$rutaSig"` y por `entidad`, y guarda mediana/p25/p75 con `Estadistica`. Luego calcula `base` de cada par de rutas (o la entidad) y la `curva` de 8 bins con `mediana(hueco - base_de_su_grupo)`.

`estimar()`: elige fuente en orden par de rutas → entidad → global; `residual = curva[bin]->residual_min ?? 0`; `gap = base + residual`; rellena `p25/p75` de la fuente; `nivel` según las reglas de arriba.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor\bin\phpunit --testsuite Unit --filter CalibradorTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Prediccion/Calibrador.php tests/Unit/Prediccion/CalibradorTest.php
git commit -m "feat(prediccion): calibrador del hueco real con correccion horaria"
```

---

### Task 3: Ventanas de no-actividad y desplazamiento

**Files:**
- Create: `app/Prediccion/Ventanas.php`
- Test: `tests/Unit/Prediccion/VentanasTest.php`

**Interfaces:**
- Produces:
  - `Ventanas::inactivas(array $intervalos, Carbon $desde, Carbon $hasta, float $umbral = 0.10, int $granularidad = 30): array` — cada intervalo es `(object)['desde' => Carbon, 'fin' => Carbon]`. Devuelve franjas `['desde' => int, 'hasta' => int]` en minutos del día (0..1440); una franja que cruza medianoche se parte en dos (`[1380,1440]` y `[0,360]`).
  - `Ventanas::desplazar(Carbon $t, array $ventanas, int $maxMin = 600): stdClass` — `->fecha` (Carbon), `->min` (minutos desplazados), `->aviso` (`?string`).
- Cobertura de una franja = minutos cubiertos por intervalos / (días × granularidad).

- [ ] **Step 1: Write the failing test**

```php
// tests/Unit/Prediccion/VentanasTest.php
namespace Tests\Unit\Prediccion;

use App\Prediccion\Ventanas;
use Carbon\Carbon;
use Tests\TestCase;

class VentanasTest extends TestCase
{
    /** @return array<int, object> intervalos diarios "06:00->22:00" durante $dias */
    private function jornada(int $dias = 10, string $desde = '06:00', string $fin = '22:00'): array
    {
        $out = [];
        for ($i = 0; $i < $dias; $i++) {
            $dia = Carbon::parse('2026-10-01 00:00')->addDays($i);
            $out[] = (object) ['desde' => $dia->copy()->setTimeFromTimeString($desde),
                               'fin' => $dia->copy()->setTimeFromTimeString($fin)];
        }

        return $out;
    }

    public function test_detecta_la_noche_sin_actividad(): void
    {
        $ventanas = Ventanas::inactivas(
            $this->jornada(),
            Carbon::parse('2026-10-01 00:00'),
            Carbon::parse('2026-10-11 00:00')
        );

        $this->assertContains(['desde' => 1320, 'hasta' => 1440], $ventanas);
        $this->assertContains(['desde' => 0, 'hasta' => 360], $ventanas);
    }

    public function test_detecta_el_almuerzo_cuando_la_jornada_se_parte(): void
    {
        // jornada completa 06-22: el mediodia esta cubierto y no hay franja
        $completa = Ventanas::inactivas($this->jornada(10), Carbon::parse('2026-10-01 00:00'), Carbon::parse('2026-10-11 00:00'));
        $this->assertNotContains(['desde' => 720, 'hasta' => 780], $completa);

        // jornada partida 06-12 y 13-22: 12-13 queda inactivo
        $partida = array_merge($this->jornada(10, '06:00', '12:00'), $this->jornada(10, '13:00', '22:00'));
        $ventanas = Ventanas::inactivas($partida, Carbon::parse('2026-10-01 00:00'), Carbon::parse('2026-10-11 00:00'));

        $this->assertContains(['desde' => 720, 'hasta' => 780], $ventanas);
    }

    public function test_desplaza_una_fecha_dentro_de_la_noche(): void
    {
        $res = Ventanas::desplazar(
            Carbon::parse('2026-11-14 03:10'),
            [['desde' => 1320, 'hasta' => 1440], ['desde' => 0, 'hasta' => 360]]
        );

        $this->assertSame('2026-11-14 06:00', $res->fecha->format('Y-m-d H:i'));
        $this->assertSame(170, $res->min);
        $this->assertNull($res->aviso);
    }

    public function test_no_mueve_una_fecha_fuera_de_las_ventanas(): void
    {
        $t = Carbon::parse('2026-11-14 10:30');
        $res = Ventanas::desplazar($t, [['desde' => 0, 'hasta' => 360]]);

        $this->assertTrue($res->fecha->equalTo($t));
        $this->assertSame(0, $res->min);
    }

    public function test_respeta_el_maximo_y_avisa(): void
    {
        $res = Ventanas::desplazar(
            Carbon::parse('2026-11-14 22:30'),
            [['desde' => 1320, 'hasta' => 1440], ['desde' => 0, 'hasta' => 600]],
            60
        );

        $this->assertSame(60, $res->min);
        $this->assertNotNull($res->aviso);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor\bin\phpunit --testsuite Unit --filter VentanasTest`
Expected: FAIL — clase no existe.

- [ ] **Step 3: Implement `app/Prediccion/Ventanas.php`**

`inactivas()`: arma un arreglo de 1440/granularidad franjas, suma minutos cubiertos por cada intervalo recortado al rango `[desde, hasta]`, divide por `dias * granularidad`, y une franjas consecutivas bajo el umbral. `desplazar()`: itera hasta 3 veces; si `t` cae en una franja, mueve al `hasta` de la franja (con el salto de día si hace falta) acumulando minutos y corta en `maxMin` dejando aviso.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor\bin\phpunit --testsuite Unit --filter VentanasTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Prediccion/Ventanas.php tests/Unit/Prediccion/VentanasTest.php
git commit -m "feat(prediccion): ventanas de no actividad y desplazamiento"
```

---

### Task 4: Motor de la cadena

**Files:**
- Create: `app/Prediccion/MotorPresenta.php`
- Test: `tests/Unit/Prediccion/MotorPresentaTest.php`

**Interfaces:**
- Consumes: `Calibrador::estimar()` (objeto `stdClass`), `Ventanas::desplazar()`.
- Produces: `MotorPresenta::predecir(stdClass $e): stdClass`, `MotorPresenta::faltantes(?string $hito, array $etapas): ?int`, `MotorPresenta::estado(?string $hito, ?Carbon $ancla, Carbon $ahora, array $ventanas, int $edadMax): stdClass`, `MotorPresenta::confianza(stdClass $datos): stdClass`.

Contrato de entrada (`$e`):

```
->ahora Carbon                          corte
->ref string                            ref a predecir (informativa)
->planta_carga (?string)
->ref_presenta (?Carbon)                presenta_para_carga ya registrado de la ref
->ancla (object)->hito string, ->fecha Carbon, ->cerrada bool
->etapas array                          ['e2'=>?int, 'e3'=>?int, 'e4'=>?int, 'e5'=>?int, 'e6'=>?int, 'ciclo'=>?int]
->calibrador (?stdClass)                salida de Calibrador::estimar(); null = sin pares medidos
->traslado (?object)->min int, ->fuente string   null = posicion desconocida
->ventanas array
->espera_carga_min (?int)
->parametros stdClass                   (config/prediccion.php + overrides)
```

Contrato de salida: ver §7.5 del spec (`presenta`, `inicio_carga`, `espera_min`, `ventana`,
`estado`, `ancla`, `desglose`, `supuestos`, `advertencias`, `evidencia`, `confianza`,
`confianza_motivo`, `ya_presento`).

- [ ] **Step 1: Write the failing test**

```php
// tests/Unit/Prediccion/MotorPresentaTest.php
namespace Tests\Unit\Prediccion;

use App\Prediccion\MotorPresenta;
use Carbon\Carbon;
use Tests\TestCase;

class MotorPresentaTest extends TestCase
{
    private function parametros(array $over = []): object
    {
        return (object) array_merge([
            'tope_hueco_min' => 10080, 'tope_etapa_min' => 2880,
            'max_desplazamiento_min' => 600, 'traslado_defecto_min' => 240,
            'muerto_defecto_min' => 60, 'espera_carga_defecto_min' => 90,
            'bins_horarios' => 8, 'edad_ancla_max_h' => 72, 'frescura_horizonte_h' => 24,
        ], $over);
    }

    private function entrada(array $over = []): object
    {
        return (object) array_merge([
            'ahora' => Carbon::parse('2026-11-14 10:00'),
            'ref' => 'REQ1', 'planta_carga' => 'LOC_CARAL',
            'ref_presenta' => null,
            'ancla' => (object) ['hito' => 'fin_descargue', 'cerrada' => true,
                                 'fecha' => Carbon::parse('2026-11-14 09:00')],
            'etapas' => ['e2' => 90, 'e3' => 60, 'e4' => 180, 'e5' => 60, 'e6' => 45, 'ciclo' => 435],
            'calibrador' => (object) ['gap_min' => 300, 'p25_min' => 240, 'p75_min' => 420,
                                      'base_min' => 300, 'residual_min' => 0, 'n' => 6, 'nivel' => 'medido'],
            'traslado' => (object) ['min' => 180, 'fuente' => 'ruta_inversa'],
            'ventanas' => [],
            'espera_carga_min' => 100,
            'parametros' => $this->parametros(),
        ], $over);
    }

    public function test_ancla_cerrada_mas_hueco_calibrado(): void
    {
        $r = MotorPresenta::predecir($this->entrada());

        // 09:00 + hueco 300' = 14:00 ; inicio de carga = 14:00 + espera 100' = 15:40
        $this->assertSame('2026-11-14 14:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertSame('2026-11-14 15:40', $r->inicio_carga->format('Y-m-d H:i'));
        $this->assertSame('medido', $r->evidencia);
        $this->assertSame(100, $r->espera_min);
        // el hueco incluye el regreso: 300 totales, 180 de traslado, 120 de espera y descanso
        $this->assertSame(300, collect($r->desglose)->firstWhere('concepto', 'hueco_total')['min']);
        $this->assertSame(180, collect($r->desglose)->firstWhere('concepto', 'traslado_regreso')['min']);
        $this->assertSame(120, collect($r->desglose)->firstWhere('concepto', 'tiempo_muerto')['min']);
    }

    public function test_ancla_a_mitad_de_viaje_suma_etapas_faltantes(): void
    {
        $r = MotorPresenta::predecir($this->entrada([
            'ancla' => (object) ['hito' => 'inicio_ruta', 'cerrada' => false,
                                 'fecha' => Carbon::parse('2026-11-14 09:00')],
        ]));

        // inicio_ruta -> falta e4+e5+e6 = 180+60+45 = 285; 09:00 + 285' = 13:45; + 300' = 18:45
        $this->assertSame('2026-11-14 18:45', $r->presenta->format('Y-m-d H:i'));
        $this->assertSame(285, collect($r->desglose)->firstWhere('concepto', 'restante_viaje')['min']);
    }

    public function test_sin_pares_usa_la_cadena_y_baja_la_evidencia(): void
    {
        $r = MotorPresenta::predecir($this->entrada(['calibrador' => null]));

        // sin calibracion: t_fin + traslado 180' + muerto por defecto 60' = 09:00 + 240' = 13:00
        $this->assertSame('2026-11-14 13:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertSame('cadena', $r->evidencia);
        $this->assertLessThanOrEqual(45, $r->confianza);
        $this->assertSame(60, collect($r->desglose)->firstWhere('concepto', 'tiempo_muerto')['min']);
    }

    public function test_sin_traslado_ni_hueco_no_inventa_hora(): void
    {
        $r = MotorPresenta::predecir($this->entrada(['calibrador' => null, 'traslado' => null]));

        $this->assertNull($r->presenta);
        $this->assertSame('sin_datos', $r->evidencia);
        $this->assertNotEmpty($r->advertencias);
    }

    public function test_prediccion_en_el_pasado_se_marca_atrasada(): void
    {
        $r = MotorPresenta::predecir($this->entrada([
            'ahora' => Carbon::parse('2026-11-16 10:00'),
            'ancla' => (object) ['hito' => 'fin_descargue', 'cerrada' => true,
                                 'fecha' => Carbon::parse('2026-11-14 09:00')],
        ]));

        $this->assertTrue($r->presenta->greaterThanOrEqualTo(Carbon::parse('2026-11-16 10:00')));
        $this->assertContains('atrasado', array_map(fn ($a) => explode(':', $a)[0], $r->advertencias));
    }

    public function test_ref_que_ya_presento(): void
    {
        $r = MotorPresenta::predecir($this->entrada([
            'ref_presenta' => Carbon::parse('2026-11-14 08:30'),
        ]));

        $this->assertTrue($r->ya_presento);
        $this->assertNull($r->presenta);
    }

    public function test_la_ventana_mueve_la_prediccion_nocturna(): void
    {
        $r = MotorPresenta::predecir($this->entrada([
            'ancla' => (object) ['hito' => 'fin_descargue', 'cerrada' => true,
                                 'fecha' => Carbon::parse('2026-11-14 20:00')],
            'ventanas' => [['desde' => 1320, 'hasta' => 1440], ['desde' => 0, 'hasta' => 360]],
        ]));

        // 20:00 + 300' = 01:00 (dentro de la ventana) -> 06:00
        $this->assertSame('2026-11-15 06:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertSame(300, collect($r->desglose)->firstWhere('concepto', 'espera_ventana')['min']);
    }

    public function test_etapa_vencida_se_acota_a_ahora_y_advierte(): void
    {
        $r = MotorPresenta::predecir($this->entrada([
            'ancla' => (object) ['hito' => 'inicio_ruta', 'cerrada' => false,
                                 'fecha' => Carbon::parse('2026-11-13 09:00')],
        ]));

        // 13-11 09:00 + 285' cae antes de ahora: t_fin = ahora (14-11 10:00) + 300' = 15:00
        $this->assertSame('2026-11-14 15:00', $r->presenta->format('Y-m-d H:i'));
        $this->assertContains('etapa_vencida', array_map(fn ($a) => explode(':', $a)[0], $r->advertencias));
    }

    public function test_estado_del_conductor(): void
    {
        $enRuta = MotorPresenta::estado('inicio_ruta', Carbon::parse('2026-11-14 08:00'),
            Carbon::parse('2026-11-14 10:00'), [], 72);
        $this->assertSame('en_ruta', $enRuta->clave);

        $durmiendo = MotorPresenta::estado('fin_descargue', Carbon::parse('2026-11-14 01:00'),
            Carbon::parse('2026-11-14 03:30'), [['desde' => 0, 'hasta' => 360]], 72);
        $this->assertSame('descansando', $durmiendo->clave);

        $parado = MotorPresenta::estado('fin_descargue', Carbon::parse('2026-11-14 01:00'),
            Carbon::parse('2026-11-14 12:00'), [['desde' => 0, 'hasta' => 360]], 6);
        $this->assertSame('inactivo_extendido', $parado->clave);
    }

    public function test_faltantes_por_hito(): void
    {
        $etapas = ['e2' => 90, 'e3' => 60, 'e4' => 180, 'e5' => 60, 'e6' => 45];

        $this->assertSame(285, MotorPresenta::faltantes('inicio_ruta', $etapas));
        $this->assertSame(105, MotorPresenta::faltantes('qr_llegada_destino', $etapas));
        $this->assertSame(45, MotorPresenta::faltantes('inicio_descargue', $etapas));
        $this->assertSame(435, MotorPresenta::faltantes('presenta_para_carga', $etapas));
        $this->assertNull(MotorPresenta::faltantes('fin_descargue', $etapas));
        $this->assertNull(MotorPresenta::faltantes('inicio_ruta', ['e4' => 180]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor\bin\phpunit --testsuite Unit --filter MotorPresentaTest`
Expected: FAIL — clase no existe.

- [ ] **Step 3: Implement `app/Prediccion/MotorPresenta.php`**

Orden interno de `predecir()`:
1. `ref_presenta !== null && ref_presenta <= ahora` → `ya_presento = true`, `presenta = null`, salida temprana.
2. `t_fin = ancla->cerrada ? ancla->fecha : max(ahora, ancla->fecha + faltantes(ancla->hito, etapas))`. Si el ancla no está cerrada y el cálculo cae antes de `ahora`, se usa `ahora` y se agrega la advertencia `etapa_vencida: …`.
3. `traslado === null && calibrador === null` → `sin_datos` con advertencia, `presenta = null`, salida.
4. `hueco = calibrador->gap_min ?? (traslado->min + muerto_defecto_min)`; `hueco = max(hueco, traslado->min)`; tope `tope_hueco_min`.
5. `t = t_fin + hueco`; `Ventanas::desplazar($t, $ventanas, max_desplazamiento_min)` → `t` y `espera_ventana`.
6. Si `t < ahora` → `t = ahora` y aviso `atrasado: …` (y si el ancla está vencida, no encadenar otro aviso).
7. `inicio_carga = t + (espera_carga_min ?? espera_carga_defecto_min)`.
8. `desglose`: `restante_viaje` (= `faltantes(ancla->hito, etapas)`, sólo si el ancla no está cerrada), `hueco_total` (= `hueco` aplicado), `traslado_regreso` (= `traslado->min`), `tiempo_muerto` (= `hueco_total - traslado_regreso`, o `muerto_defecto_min` cuando el hueco salió de la cadena), `espera_ventana`, `espera_carga`; cada fila con `fuente` (`medido (n=6)`, `ruta API`, `defecto`, `ventana 00:00-06:00`).
9. `estado`, `supuestos`, `advertencias`, `evidencia`, `confianza` y `ventana` (`p25/p75` del calibrador, o ±`desviación` del ciclo cuando no hay pares).

`faltantes()` devuelve `null` si falta cualquier etapa necesaria. `estado()` aplica §7.6 del spec. `confianza()` aplica §7.5 con topes por evidencia.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor\bin\phpunit --testsuite Unit --filter MotorPresentaTest`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Prediccion/MotorPresenta.php tests/Unit/Prediccion/MotorPresentaTest.php
git commit -m "feat(prediccion): motor de la cadena presenta/inicio de carga"
```

---

### Task 5: Parámetros editables

**Files:**
- Create: `config/prediccion.php`
- Create: `app/Prediccion/Parametros.php`
- Create: `database/migrations/2026_11_13_120000_create_prediccion_config_table.php`
- Create: `sql/prediccion.sql`
- Test: `tests/Unit/Prediccion/ParametrosTest.php`

**Interfaces:**
- Produces: `Parametros::por_defecto(): stdClass` (lee `config('prediccion')`), `Parametros::cargar(): stdClass` (defaults + overrides de `prediccion_config`, memoizado por request, `try/catch` ⇒ defaults), `Parametros::guardar(array $valores): void` (upsert clave/valor).
- La tabla es `prediccion_config (clave VARCHAR(32) PRIMARY KEY, valor VARCHAR(32), updated_at)`, igual que `scoring_config`.

- [ ] **Step 1: Write the failing test**

```php
// tests/Unit/Prediccion/ParametrosTest.php
namespace Tests\Unit\Prediccion;

use App\Prediccion\Parametros;
use Tests\TestCase;

class ParametrosTest extends TestCase
{
    public function test_por_defecto_trae_los_valores_del_spec(): void
    {
        $p = Parametros::por_defecto();

        $this->assertSame(120, $p->lookback_dias);
        $this->assertSame(3, $p->min_pares_ruta);
        $this->assertSame(3, $p->min_pares_entidad);
        $this->assertSame(5, $p->min_dias_actividad);
        $this->assertSame(0.10, $p->umbral_inactividad);
        $this->assertSame(30, $p->granularidad_min);
        $this->assertSame(8, $p->bins_horarios);
        $this->assertSame(600, $p->max_desplazamiento_min);
        $this->assertSame(240, $p->traslado_defecto_min);
        $this->assertSame(60, $p->muerto_defecto_min);
        $this->assertSame(90, $p->espera_carga_defecto_min);
        $this->assertSame(24, $p->frescura_horizonte_h);
        $this->assertSame(72, $p->edad_ancla_max_h);
        $this->assertSame(10080, $p->tope_hueco_min);
        $this->assertSame(2880, $p->tope_etapa_min);
    }

    public function test_cargar_sin_tabla_devuelve_los_defaults(): void
    {
        // en la suite la base es sqlite en memoria y no tiene prediccion_config
        $p = Parametros::cargar();

        $this->assertSame(3, $p->min_pares_ruta);
        $this->assertSame(0.10, $p->umbral_inactividad);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor\bin\phpunit --testsuite Unit --filter ParametrosTest`
Expected: FAIL — `config/prediccion.php` no existe / clase no existe.

- [ ] **Step 3: Implement**

`config/prediccion.php` devuelve el arreglo con las 15 claves y los valores del Global Constraints. `Parametros` castea a `int`/`float` según la clave (los floats son `umbral_inactividad`), y `cargar()` cachea en `static $cache`, envuelve el `DB::table('prediccion_config')->pluck('valor','clave')` en `try/catch (\Throwable)` y aplica sólo claves conocidas. La migración crea la tabla e inserta las 15 filas con `now()`; `sql/prediccion.sql` lleva el mismo `CREATE TABLE` + `INSERT` para aplicarlo a mano.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor\bin\phpunit --testsuite Unit --filter ParametrosTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add config/prediccion.php app/Prediccion/Parametros.php tests/Unit/Prediccion/ParametrosTest.php database/migrations/2026_11_13_120000_create_prediccion_config_table.php sql/prediccion.sql
git commit -m "feat(prediccion): parametros con defaults y overrides en base"
```

---

### Task 6: Capa de datos y diagnóstico

**Files:**
- Create: `app/Database/DBPrediccion.php`
- Modify: `routes/console.php` (comando `prediccion:diagnostico`)
- Test: `tests/Unit/Prediccion/DiagnosticoTest.php`

**Interfaces (todas estáticas, sólo lectura):**

```
ref(string $ref): ?object                      // ref + ruta + placa + conductor + los 8 hitos
refs_por_placa(string $placa, ?Carbon $antes, int $limite = 50): array
refs_por_conductor(int $conductor_id, ?Carbon $antes, int $limite = 50): array
pares(?string $excluirRef, Carbon $desde, Carbon $hasta): array   // LAG por placa
etapas_por_ruta(Carbon $desde, Carbon $hasta): array              // ruta_id => e2..e6 + n + ciclo
promedios_ruta(): array                        // ruta_id => fila de DBTiemposPromedioCompilado
promedios_planta(): array                      // planta_id => fila de DBTiemposPlantaCompilado
rutas(): array                                 // id => origen_id, destino_id, ubigeo_*
intervalos_entidad(string $grupo, string $clave, Carbon $desde, Carbon $hasta): array
refs_rango(Carbon $desde, Carbon $hasta, ?string $placa): array
traslado(?string $plantaCarga, ?string $origenPosicion, ?string $ubigeoDestino, ?string $depDestino): ?object
espera_carga(?string $planta, ?string $rutaId): ?int
diagnostico(int $dias): stdClass
```

- [ ] **Step 1: Write the failing test**

El cálculo puro que usa el diagnóstico (distribución de huecos) ya vive en `Estadistica`/`Calibrador`; el test que falta es el de la descripción textual:

```php
// tests/Unit/Prediccion/DiagnosticoTest.php
namespace Tests\Unit\Prediccion;

use App\Database\DBPrediccion;
use Tests\TestCase;

class DiagnosticoTest extends TestCase
{
    public function test_resumen_del_diagnostico_arma_texto_con_los_numeros(): void
    {
        $texto = DBPrediccion::texto_diagnostico((object) [
            'refs' => 1000, 'con_ruta' => 940, 'pares' => 320,
            'hueco_p25' => 180, 'hueco_mediana' => 300, 'hueco_p75' => 720,
            'rutas_con_etapas' => 55, 'plantas_compiladas' => 12,
        ]);

        $this->assertStringContainsString('940/1000', $texto);
        $this->assertStringContainsString('320', $texto);
        $this->assertStringContainsString('300', $texto);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor\bin\phpunit --testsuite Unit --filter DiagnosticoTest`
Expected: FAIL — `DBPrediccion` no existe.

- [ ] **Step 3: Implement `DBPrediccion`**

`pares()` usa `LAG(fin_descargue) OVER (PARTITION BY placa ORDER BY presenta_para_carga)` y devuelve pares con `ruta_ancla` (ruta del viaje cuyo `fin_descargue` es el ancla) y `ruta_siguiente`; excluye `ref = $excluirRef` y cualquier par que lo toque. `etapas_por_ruta()` hace un `GROUP BY ruta_id` con las expresiones de `DBComportamiento::expresiones_etapas():223-227` (`AVG(CASE WHEN etapa >= 0 ...)`), filtrado por `fin_descargue`/`inicio_ruta` dentro del rango y con hitos `>= '2000-01-01'`. `traslado()` implementa la jerarquía §8 del spec. `texto_diagnostico()` es la función pura que arma el texto del comando. Todos los métodos devuelven estructuras vacías ante tablas faltantes (`try/catch`), como `DBTiemposPromedioCompilado::tablas_listas():56`.

- [ ] **Step 4: Registrar el comando**

En `routes/console.php`: `Artisan::command('prediccion:diagnostico {--dias=120}', ...)` que llama `DBPrediccion::diagnostico($dias)`, captura `\Throwable` y `$this->error('Sin base de datos: ' . $e->getMessage())` sin stack trace.

- [ ] **Step 5: Run tests and the command**

Run: `vendor\bin\phpunit --testsuite Unit --filter DiagnosticoTest`
Expected: PASS (1 test).

Run: `php artisan prediccion:diagnostico --dias=120`
Expected aquí (sin MySQL): mensaje `Sin base de datos: ...` y código 0, sin excepción. En el servidor: `refs`, `% con ruta_id`, `pares`, cuartiles del hueco, rutas con etapas y plantas compiladas.

- [ ] **Step 6: Commit**

```bash
git add app/Database/DBPrediccion.php tests/Unit/Prediccion/DiagnosticoTest.php routes/console.php
git commit -m "feat(prediccion): capa de datos y comando de diagnostico"
```

---

### Task 7: Servicio, aciertos y línea base

**Files:**
- Create: `app/Prediccion/Servicio.php`
- Create: `app/Prediccion/Aciertos.php`
- Create: `app/Prediccion/BaseHistograma.php`
- Test: `tests/Unit/Prediccion/AciertosTest.php`, `tests/Unit/Prediccion/BaseHistogramaTest.php`, `tests/Unit/Prediccion/ServicioTest.php`

**Interfaces:**
- `Servicio::predecir(string $ref, array $opciones = []): stdClass` — opciones: `ahora`, `ancla_ref`, `ubicacion_tipo`, `ubicacion_id`, `historia[]`, `viaje`, `forzar` (usar el payload aunque exista en BD). Devuelve el contrato del motor más `->ref`, `->placa`, `->ruta`, `->planta_carga`, `->candidatos_viaje` y `->historia` (n de pares usados).
- `Servicio::backtest(array $filtros): stdClass` — `->filas`, `->resumen_motor`, `->resumen_base`, `->parametros`.
- `Servicio::candidatos_viaje(string $placa, ?Carbon $antes): array` — hasta 20 viajes con `ref`, `ruta`, hitos, `etapa`, `cerrado`, `edad_min`.
- `Servicio::catalogos(): stdClass` — `->rutas`, `->plantas`, `->locales`, `->departamentos`.
- `Servicio::clave_calibracion(?string $placa, ?int $conductorId, ?float $pctHabitual): array` — devuelve `['placa', $placa]` si `$pctHabitual > 50` o no hay conductor; si no `['conductor', (string) $conductorId]`.
- `Aciertos::resumir(array $erroresMin, array $dentroVentana): stdClass` — `->n`, `->mae_min`, `->mediana_error_min`, `->sesgo_min`, `->pct_30`, `->pct_60`, `->pct_120`, `->pct_180`, `->pct_ventana`. `mae_min` y los `pct_*` usan el valor absoluto del error; `mediana_error_min` y `sesgo_min` usan el error con signo (positivo = el motor se quedó corto).
- `Aciertos::comparar(stdClass $motor, stdClass $base): stdClass` — `->delta_mae_min`, `->delta_pct_60`, `->gana` (`motor|base|empate`).
- `BaseHistograma::estimar(array $presentas, Carbon $ahora): ?Carbon` — réplica de `veredicto_carga`: hora más frecuente (con el minuto promedio de esa hora) que todavía no pasó, avanzando día a día.

- [ ] **Step 1: Write the failing tests**

```php
// tests/Unit/Prediccion/AciertosTest.php
namespace Tests\Unit\Prediccion;

use App\Prediccion\Aciertos;
use Tests\TestCase;

class AciertosTest extends TestCase
{
    public function test_resumen_con_errores_conocidos(): void
    {
        $r = Aciertos::resumir([-30, 0, 60, 180, 600], [true, true, true, false, false]);

        $this->assertSame(5, $r->n);
        $this->assertSame(174, $r->mae_min);   // (30+0+60+180+600)/5
        $this->assertSame(60, $r->mediana_error_min);
        $this->assertSame(60, $r->pct_60);     // 30, 0 y 60 estan dentro de +/-60
        $this->assertSame(80, $r->pct_180);    // y 180 tambien
        $this->assertSame(60, $r->pct_ventana);
    }

    public function test_comparar_declara_ganador(): void
    {
        $motor = Aciertos::resumir([10, 20, 30], [true, true, true]);
        $base  = Aciertos::resumir([100, 140, 90], [false, false, false]);

        // MAE motor 20, MAE base 110
        $this->assertSame('motor', Aciertos::comparar($motor, $base)->gana);
        $this->assertSame(-90, Aciertos::comparar($motor, $base)->delta_mae_min);
        $this->assertSame(100, Aciertos::comparar($motor, $base)->delta_pct_60);
    }
}
```

```php
// tests/Unit/Prediccion/BaseHistogramaTest.php
namespace Tests\Unit\Prediccion;

use App\Prediccion\BaseHistograma;
use Carbon\Carbon;
use Tests\TestCase;

class BaseHistogramaTest extends TestCase
{
    public function test_elige_la_hora_futura_mas_frecuente_con_su_minuto(): void
    {
        $presentas = [
            Carbon::parse('2026-11-02 08:30'), Carbon::parse('2026-11-03 08:40'),
            Carbon::parse('2026-11-04 08:35'), Carbon::parse('2026-11-05 17:10'),
        ];

        $estimado = BaseHistograma::estimar($presentas, Carbon::parse('2026-11-13 07:00'));

        $this->assertSame('2026-11-13 08:35', $estimado->format('Y-m-d H:i'));
    }

    public function test_sin_presentas_devuelve_null(): void
    {
        $this->assertNull(BaseHistograma::estimar([], Carbon::parse('2026-11-13 07:00')));
    }
}
```

```php
// tests/Unit/Prediccion/ServicioTest.php
namespace Tests\Unit\Prediccion;

use App\Prediccion\Servicio;
use Tests\TestCase;

class ServicioTest extends TestCase
{
    public function test_clave_calibracion_respeta_el_50_por_ciento(): void
    {
        $this->assertSame(['placa', 'ABC123'], Servicio::clave_calibracion('ABC123', 7, 80.0));
        $this->assertSame(['placa', 'ABC123'], Servicio::clave_calibracion('ABC123', 7, 50.0));
        $this->assertSame(['conductor', '7'], Servicio::clave_calibracion('ABC123', 7, 49.9));
        $this->assertSame(['placa', 'ABC123'], Servicio::clave_calibracion('ABC123', null, null));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor\bin\phpunit --testsuite Unit --filter "AciertosTest|BaseHistogramaTest|ServicioTest"`
Expected: FAIL — clases no existen.

- [ ] **Step 3: Implement**

`Aciertos` opera sobre enteros de minutos; los porcentajes se redondean a entero (`round(100 * $aciertos / $n)`). `comparar()` resta MAE y porcentajes y elige `gana` con `delta_mae_min < -30` ⇒ `motor`, `> 30` ⇒ `base`, si no `empate`.

`BaseHistograma` cuenta por `(día de semana, hora)`, busca la primera hora futura con conteo > 0 empezando hoy (sólo horas > hora actual) y avanzando hasta 7 días, y usa el minuto promedio (redondeado) de esa hora.

`Servicio::backtest()`: trae refs del rango con `presenta_para_carga` y su par (LAG) en una consulta; trae `etapas_por_ruta`, `promedios_ruta`, `promedios_planta`, `rutas`, `pares` del lookback y las ventanas por entidad **una vez**; después itera en PHP construyendo la entrada del motor con `ahora = fin_descargue` del ancla y `pares` filtrados para excluir la ref evaluada. Para la línea base usa `BaseHistograma::estimar()` con las presentas anteriores al corte.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor\bin\phpunit --testsuite Unit --filter "AciertosTest|BaseHistogramaTest|ServicioTest"`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Prediccion/Servicio.php app/Prediccion/Aciertos.php app/Prediccion/BaseHistograma.php tests/Unit/Prediccion/AciertosTest.php tests/Unit/Prediccion/BaseHistogramaTest.php tests/Unit/Prediccion/ServicioTest.php
git commit -m "feat(prediccion): servicio, aciertos y linea base del metodo actual"
```

---

### Task 8: Pantalla calculadora

**Files:**
- Create: `app/Http/Controllers/PrediccionController.php`
- Create: `resources/views/lupita/prediccion_presenta.blade.php`
- Create: `resources/views/lupita/partials/prediccion_resultado.blade.php`
- Create: `resources/views/lupita/partials/prediccion_parametros.blade.php`
- Modify: `routes/web.php` (dentro del grupo `lupita`, junto a comportamiento en la línea ~100)
- Modify: `resources/views/layouts/app.blade.php` (enlace en el menú, junto a Comportamiento en la línea ~131)
- Modify: `resources/views/lupita/comportamiento_ficha.blade.php` (botón «Predecir carga» junto al de volver, línea ~52)

**Interfaces:**
- `PrediccionController::presenta(Request $request)` — GET, valida `ref` (nullable), `placa` (nullable), `ahora` (nullable date), `ancla` (nullable string), `ubicacion_tipo` (`in:auto,local,ubigeo,indeterminado`), `ubicacion_id`, y los parámetros numéricos. Si hay `ref` o `placa` llama `Servicio::predecir()` / `candidatos_viaje()` y pinta. `self::comunes()` devuelve `rutas`, `nombres_rutas`, `locales`, `departamentos`, `parametros`.
- Ruta: `Route::get('/prediccion/presenta', [PrediccionController::class, 'presenta'])->name('lupita.prediccion.presenta')->middleware('permission:ver tiempos')`.

- [ ] **Step 1: Implementar el controlador, la ruta y el enlace del menú**

`PrediccionController::presenta()` valida con `$request->validate([...])`, arma `$datos` con `Servicio::catalogos()`, `Servicio::candidatos_viaje($placa)` y, si vienen `ref` o `placa`, `Servicio::predecir()`. `self::comunes()` devuelve `rutas`, `nombres_rutas`, `locales`, `departamentos` y `Parametros::cargar()`, con la misma forma que `ComportamientoController::comunes():139`. Registrar la ruta en el grupo `lupita` (junto a comportamiento, `routes/web.php:100`) y el enlace en `layouts/app.blade.php` junto a «Comportamiento» (`:131`).

- [ ] **Step 2: Verificar controlador y ruta**

Run: `php artisan route:list --name=lupita.prediccion`
Expected: la ruta `lupita.prediccion.presenta` listada con el middleware `permission:ver tiempos`.

- [ ] **Step 3: Implementar las vistas**

`prediccion_presenta.blade.php` con cinco bloques: (1) formulario `GET` con `ref` y `placa`; (2) lista de viajes ancla con radio (`$datos->candidatos`), marcando «sin viaje en curso» cuando no hay; (3) ubicación con `<select>` de tipo y de local/departamento; (4) tarjeta de parámetros (`prediccion_parametros.blade.php`) que reenvía todo por querystring; (5) `prediccion_resultado.blade.php` con la hora grande, la ventana p25–p75 en pequeño, el badge de evidencia/confianza, la tabla de desglose (`concepto`, `min`, `fuente`), los supuestos y las advertencias en `alert-warning`. Estilo de `comportamiento_ficha.blade.php`: `@extends('layouts.app')`, `@section('content')`, `container-fluid py-3`, tarjetas `card border-*`, iconos `bi-`.

- [ ] **Step 4: Verificar que las vistas compilan**

Run: `php artisan view:cache`
Expected: `Blade templates cached successfully.`

- [ ] **Step 5: Agregar el botón en la ficha**

En `comportamiento_ficha.blade.php`, junto al botón «Volver al ranking» (`:52`): `<a href="{{ route('lupita.prediccion.presenta', ['placa' => $f->nombre]) }}" class="btn btn-outline-primary"><i class="bi bi-clock-history"></i> Predecir carga</a>`. Sólo cuando `$esPlaca`.

Run: `php artisan view:cache` → sin errores.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PrediccionController.php resources/views/lupita/prediccion_presenta.blade.php resources/views/lupita/partials/prediccion_resultado.blade.php resources/views/lupita/partials/prediccion_parametros.blade.php resources/views/lupita/comportamiento_ficha.blade.php routes/web.php resources/views/layouts/app.blade.php
git commit -m "feat(prediccion): pantalla calculadora de presenta para carga"
```

---

### Task 9: Pantalla de aciertos

**Files:**
- Modify: `app/Http/Controllers/PrediccionController.php` (método `aciertos()`)
- Create: `resources/views/lupita/prediccion_aciertos.blade.php`
- Modify: `routes/web.php`

**Interfaces:**
- `PrediccionController::aciertos(Request $request)` — valida `fecha_inicio`, `fecha_fin` (default últimos 30 días, igual que `ComportamientoController::preparar():95-99`), `placa`, `limite` (default 200, máx 1000). Llama `Servicio::backtest()`.
- Ruta: `lupita.prediccion.aciertos` con `permission:ver tiempos`.

- [ ] **Step 1: Implementar el controlador y la ruta**

`PrediccionController::aciertos()` valida `fecha_inicio`, `fecha_fin`, `placa` y `limite`; si no vienen fechas aplica el default de últimos 30 días con `$request->merge(...)` antes de validar (mismo patrón que `ComportamientoController::preparar():95-99`); llama `Servicio::backtest([...])` y pasa `filas`, `resumen_motor`, `resumen_base`, `comparacion` y `parametros` a la vista. Registrar `lupita.prediccion.aciertos` con `permission:ver tiempos`.

Run: `php artisan route:list --name=lupita.prediccion`
Expected: `lupita.prediccion.presenta` y `lupita.prediccion.aciertos`.

- [ ] **Step 2: Implementar la vista**

Tarjetas superiores: una por método (motor vs histograma) con n, MAE, mediana del error y % dentro de ±1 h/±3 h, más una tarjeta de veredicto (`Aciertos::comparar()` → «el motor gana por X min de MAE»). Tabla: ref, ancla (`ref` + hito + fecha), predicho, ventana, real, error con color (`success` ≤ 60 min, `warning` ≤ 180, `danger` resto) y badge de acierto, con una columna extra de «método actual» para comparar fila a fila. Pie con el conteo de filas evaluadas y la nota de cómo se cortó el tiempo.

- [ ] **Step 3: Verificar**

Run: `php artisan view:cache`
Expected: `Blade templates cached successfully.`

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/PrediccionController.php resources/views/lupita/prediccion_aciertos.blade.php routes/web.php
git commit -m "feat(prediccion): pantalla de aciertos contra las refs ya cerradas"
```

---

### Task 10: Extracción del upsert de referencias

**Files:**
- Create: `app/Database/DBRefsActualizar.php`
- Modify: `app/Http/Controllers/Api/ActualizarRefsController.php` (el método `actualizar_refs()` pasa a delegar)
- Test: `tests/Feature/Api/ActualizarRefsTest.php`

**Interfaces:**
- `DBRefsActualizar::aplicar(array $payload): array` — devuelve exactamente el arreglo que hoy devuelve `actualizar_refs()` (`success`, `referencias_de_lotes`, `resumen`, `resultados`).
- `DBRefsActualizar::parse_fecha($valor): ?string`, `::normalizar_refs(array $refs): array`, `::validar_ref(string $ref, array $item, array &$errores): array`, `::parse_lotes(string $csv): array` — los helpers puros se mueven tal cual, sin cambios de comportamiento.

- [ ] **Step 1: Write the failing test (sólo caminos sin base de datos)**

```php
// tests/Feature/Api/ActualizarRefsTest.php
namespace Tests\Feature\Api;

use Tests\TestCase;

class ActualizarRefsTest extends TestCase
{
    public function test_rechaza_payload_no_json(): void
    {
        $this->post('/api/refs/actualizar', ['refs' => []], ['Content-Type' => 'application/x-www-form-urlencoded'])
            ->assertStatus(415)
            ->assertJsonPath('success', false);
    }

    public function test_rechaza_sin_refs(): void
    {
        $this->postJson('/api/refs/actualizar', ['referencias_de_lotes' => true])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_rechaza_refs_vacio(): void
    {
        $this->postJson('/api/refs/actualizar', ['refs' => []])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor\bin\phpunit --testsuite Feature --filter ActualizarRefsTest`
Expected: FAIL (o error) porque los helpers todavía no existen donde se buscan; sirve de línea base de comportamiento del endpoint actual.

- [ ] **Step 3: Mover sin tocar lógica**

`DBRefsActualizar` recibe el `EVENTOS`, `MAX_REFS_POR_ENVIO`, `MAX_LOTES` y los métodos `parse_lotes`, `normalizar_refs`, `validar_ref`, `parse_fecha`, `update_referencia`, `insertar_referencia`, `reprocesar_ruta`, `resolver_conductor`, `resolver_trt` y el cuerpo del `DB::transaction`, **copiados literalmente**. `ActualizarRefsController::actualizar_refs()` queda en: si no es JSON → 415; si falta `refs` → 422; si el mapa queda vacío → 422; si supera el máximo → 422; si no, `return response()->json(DBRefsActualizar::aplicar($payload));`. Los cuatro cortes tempranos se quedan en el controlador para que la validación de entrada siga siendo idéntica.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor\bin\phpunit --testsuite Feature --filter ActualizarRefsTest`
Expected: PASS (3 tests) — 415/422 sin tocar la base.

- [ ] **Step 5: Commit**

```bash
git add app/Database/DBRefsActualizar.php app/Http/Controllers/Api/ActualizarRefsController.php tests/Feature/Api/ActualizarRefsTest.php
git commit -m "refactor(refs): extraer el upsert de referencias sin cambiar la respuesta"
```

---

### Task 11: API pública de predicción

**Files:**
- Create: `app/Http/Controllers/Api/PrediccionApiController.php`
- Modify: `routes/web.php` (dentro del grupo `/api`, junto a las rutas de tiempos en la línea ~313)
- Test: `tests/Feature/Api/PrediccionApiTest.php`

**Interfaces:**
- `GET /api/prediccion/presenta?ref=…` → `prediccion_api()` con `historia` vacía.
- `POST /api/prediccion/presenta` — cuerpo JSON: `ref` (requerido, string 3..10), `ahora` (date), `ancla` (object con `ref` y `hito`), `ubicacion` (object `tipo`/`id`), `viaje` (object), `historia` (array de objetos con `ref` y hitos), `guardar` (bool).
- `GET /api/prediccion/aciertos?desde=&hasta=&placa=&limit=` → filas + resumen motor/base.
- Errores: 422 JSON con `success => false` y `detalle`; ref inexistente ⇒ 404 `success => false`, `error => 'Ref no encontrada'`; `guardar=true` sin `historia` ni `viaje` ⇒ 422.
- Con `guardar=true` llama `DBRefsActualizar::aplicar(['refs' => […]])` y agrega `->guardado` al JSON.

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Api/PrediccionApiTest.php
namespace Tests\Feature\Api;

use Tests\TestCase;

class PrediccionApiTest extends TestCase
{
    public function test_exige_ref(): void
    {
        $this->getJson('/api/prediccion/presenta')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_rechaza_ref_con_formato_invalido(): void
    {
        $this->getJson('/api/prediccion/presenta?ref=a')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_rechaza_ahora_invalido(): void
    {
        $this->getJson('/api/prediccion/presenta?ref=REQ123&ahora=mañana')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_rechaza_ubicacion_desconocida(): void
    {
        $this->postJson('/api/prediccion/presenta', [
            'ref' => 'REQ123',
            'ubicacion' => ['tipo' => 'marte', 'id' => 'X'],
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_guardar_sin_datos_es_invalido(): void
    {
        $this->postJson('/api/prediccion/presenta', ['ref' => 'REQ123', 'guardar' => true])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor\bin\phpunit --testsuite Feature --filter PrediccionApiTest`
Expected: FAIL — 404 porque las rutas no existen.

- [ ] **Step 3: Implementar**

El controlador valida con `$request->validate([...])` dentro de `try/catch (ValidationException)` y responde 422 en JSON con el mismo formato de `TiemposPromedioApiController::error_validacion():72`. La búsqueda de la ref usa `DBPrediccion::ref()` y devuelve 404 si no existe. Con payload (`historia`/`viaje`) y `forzar`, arma la entrada con esos datos antes de consultar la base.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor\bin\phpunit --testsuite Feature --filter PrediccionApiTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PrediccionApiController.php tests/Feature/Api/PrediccionApiTest.php routes/web.php
git commit -m "feat(prediccion): api publica de prediccion de presenta"
```

---

### Task 12: Cierre

- [ ] **Step 1: Correr todo**

Run: `vendor\bin\phpunit`
Expected: todas las suites verdes.

Run: `php artisan view:cache` y `php artisan route:list --name=prediccion`
Expected: sin errores y con las tres rutas (`prediccion.presenta`, `prediccion.aciertos`, `api.prediccion.*`).

- [ ] **Step 2: Dejar la nota de despliegue**

En `sql/prediccion.sql`, encabezado con: aplicar el `CREATE TABLE` a mano en el servidor, correr `php artisan prediccion:diagnostico --dias=120` y revisar los números antes de confiar en la tabla de aciertos.

- [ ] **Step 3: Commit**

```bash
git add sql/prediccion.sql
git commit -m "docs(prediccion): nota de despliegue y verificacion en servidor"
```

---

## Cambios hechos durante la ejecución (2026-11-13)

El plan se ejecutó completo; estas son las decisiones que se apartaron de lo escrito, con
el motivo. El diseño (`docs/superpowers/specs/2026-11-13-prediccion-presenta-carga-design.md`)
ya está actualizado y es la referencia.

1. **Ventanas por densidad de hitos, no por cobertura de intervalos** (Task 3/7). La
   cobertura contaba el hueco entre viajes como actividad, así que el descanso era
   invisible: ninguna placa mostraba franja nocturna. Ahora una franja es muerta si tuvo
   menos de `min_hitos_bin` marcas en todo el lookback.
2. **Calibración con los 20 pares más recientes por entidad** (Task 2/7). El hueco mediano
   de la flota cayó de ~1900 min en abril a ~540 en noviembre; calibrar con 120 días
   parejos dejaba sesgo +176 min. Con recencia el sesgo quedó en −23 min.
3. **Calibración y franjas as-of por fila en la tabla de aciertos** (Task 7). Cada fila
   calibra sólo con los pares de su placa anteriores a su ancla, y sus franjas inactivas se
   calculan sólo con los hitos anteriores a ese ancla; la curva horaria global sale de una
   calibración previa al rango. La primera versión miraba hitos hasta el final del rango:
   era fuga de futuro y maquillaba el resultado (MAE 1189 → 1439 min con la fuga cerrada).
4. **El hueco medido manda sobre el traslado promedio** (Task 4). El piso
   `hueco = max(hueco, traslado)` se invirtió: el traslado sólo es piso en la rama
   `cadena`; si el hueco medido es menor se respeta y se advierte
   `hueco_menor_que_traslado`. El `e4` promedio se infla con viajes atípicos y estaba
   pisando 20 pares reales.
5. **`faltantes()` se acota al tope de una etapa** (Task 4): el remanente de un viaje no
   puede superar 2880 min; por encima los promedios son basura.
6. **Tolerancias de la tabla ampliadas a ±6 h y ±12 h** (Task 7). Con un hueco mediano de
   19 h, ±3 h no describe nada.
7. **Tasks 8 y 9 en un solo commit** (pantallas): comparten controlador, catálogos y
   tarjeta de parámetros; separarlas dejaba el menú apuntando a una ruta inexistente.
8. **La suite de tests corre contra MySQL, no sqlite** (hallazgo en Task 11): el `.env`
   gana sobre `phpunit.xml`. Por eso el test de la API es de integración real y el de
   `Parametros` ya no afirma "sin tabla".
9. **Ruta `app/Http/Controllers/API` vs `Api`** (Task 10/11): el repo trackea la carpeta en
   mayúsculas y el namespace PSR-4 pide `Api`. En Windows es la misma carpeta; en Linux los
   controladores preexistentes no autoloadearían. El archivo nuevo se agregó con la ruta
   del disco y git la normalizó a `API/`, quedando consistente con el resto.

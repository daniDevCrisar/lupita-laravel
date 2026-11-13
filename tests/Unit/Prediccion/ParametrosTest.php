<?php

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
        $this->assertSame(2, $p->min_hitos_bin);
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
        $this->assertSame(2, $p->min_hitos_bin);
    }
}

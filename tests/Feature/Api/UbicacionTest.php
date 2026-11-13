<?php

namespace Tests\Feature\Api;

use App\Prediccion\Servicio;
use Tests\TestCase;

/**
 * La ubicación declarada se valida: un local inexistente o un ubigeo mal escrito
 * cambiaban el nivel del traslado en silencio y devolvían una hora plausible.
 */
class UbicacionTest extends TestCase
{
    public function test_local_inexistente_no_pasa(): void
    {
        $this->assertNotNull(Servicio::ubicacion_invalida('local', 'LOC_QUE_NO_EXISTE'));
    }

    public function test_local_del_catalogo_pasa(): void
    {
        $this->assertNull(Servicio::ubicacion_invalida('local', 'LOC_CARAL'));
    }

    public function test_ubigeo_tiene_que_ser_de_seis_digitos(): void
    {
        $this->assertNotNull(Servicio::ubicacion_invalida('ubigeo', '140'));
        $this->assertNotNull(Servicio::ubicacion_invalida('ubigeo', 'abcdef'));
        $this->assertNull(Servicio::ubicacion_invalida('ubigeo', '140101'));
    }

    public function test_sin_ubicacion_declarada_no_hay_error(): void
    {
        $this->assertNull(Servicio::ubicacion_invalida('auto', ''));
        $this->assertNull(Servicio::ubicacion_invalida('indeterminado', ''));
    }
}

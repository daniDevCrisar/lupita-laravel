<?php

namespace App\Livewire;

use App\Database\DBScoringLlamadas as Scoring;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Badge con el veredicto del MODELO (% de exito) para la llamada que se elige
 * en procesar_audio.
 *
 * No toca la logica original de la vista: se suscribe al MISMO evento
 * `abrirMensajeTextbox` que ya dispara selLlamada(), asi se carga solo al
 * seleccionar una llamada. Lee `scoring_llamadas` por vapi_id (1 consulta) y
 * muestra el puntaje y si el modelo la daria por exito o fallo.
 */
class ScoringLlamadaBadge extends Component
{
    // Corte binario del veredicto, igual al criterio de trabajo del modulo Scoring.
    public const UMBRAL = 70;

    public ?string $vapi_id = null;
    public ?float $puntaje = null;
    public bool $puntuada = false;

    #[On('abrirMensajeTextbox')]
    public function abrir(string $vapi_id): void
    {
        $this->cargar(trim($vapi_id));
    }

    public function cargar(?string $vapi): void
    {
        $this->vapi_id = $vapi;
        $this->puntaje = null;
        $this->puntuada = false;

        if (!$vapi) return;

        $fila = DB::selectOne(
            'SELECT puntaje FROM scoring_llamadas WHERE vapi_id = ? LIMIT 1',
            [$vapi]
        );

        if ($fila && $fila->puntaje !== null) {
            $this->puntaje = (float) $fila->puntaje;
            $this->puntuada = true;
        }
    }

    /** Color bootstrap del helper del modulo (>=70 verde, >=45 ambar, resto rojo). */
    public function color(): string
    {
        return Scoring::color_puntaje($this->puntuada ? $this->puntaje : null);
    }

    /** Si el modelo, con su umbral, la clasifica como exitosa. */
    public function exitosa(): bool
    {
        return $this->puntaje !== null && $this->puntaje >= self::UMBRAL;
    }

    public function render()
    {
        return view('livewire.scoring-llamada-badge');
    }
}

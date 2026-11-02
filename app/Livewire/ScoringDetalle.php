<?php

namespace App\Livewire;

use App\Database\DBScoringLlamadas as Scoring;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Detalle de una llamada puntuada, cargado igual que en `procesar_audio`:
 * la conversacion llega de la tabla `mensajes` y se pinta en burbujas
 * (USER a la izquierda, IA a la derecha), y las flechas ◀ ▶ recorren las
 * llamadas de la pagina actual sin recargar.
 */
class ScoringDetalle extends Component
{
    /** vapi_ids de la pagina, en el orden en que se muestran en la tabla. */
    public array $ids = [];

    /** posicion actual dentro de `ids`. */
    public int $indice = 0;

    public bool $showModal = false;

    /** fila de scoring_llamadas + analisis_audio/audio_link (o null). */
    public ?object $fila = null;

    /** mensajes de la llamada (todos los tipos) para las burbujas. */
    public array $mensajes = [];

    #[On('abrirDetalle')]
    public function abrirDetalle(array $ids, int $indice): void
    {
        $this->ids = array_values(array_map(static fn($v) => (string) $v, $ids));

        if ($this->ids === []) {
            $this->reset([
                'indice',
            ]);

            return;
        }

        $this->indice = max(0, min($indice, count($this->ids) - 1));
        $this->showModal = true;

        $this->cargar();
    }

    public function anterior(): void
    {
        if ($this->indice > 0) {
            $this->indice--;
            $this->cargar();
        }
    }

    public function siguiente(): void
    {
        if ($this->indice < count($this->ids) - 1) {
            $this->indice++;
            $this->cargar();
        }
    }

    public function cerrar(): void
    {
        $this->showModal = false;
        $this->fila = null;
        $this->mensajes = [];
    }

    /** Carga la fila y la conversacion de la llamada en `indice`. */
    protected function cargar(): void
    {
        $vapi = $this->ids[$this->indice] ?? null;

        if ($vapi === null || $vapi === '') {
            $this->fila = null;
            $this->mensajes = [];

            return;
        }

        $this->fila = Scoring::detalle($vapi)->fila;

        // conversacion completa, igual que procesar_audio: USER izquierda, resto derecha
        $this->mensajes = DB::select(
            'SELECT tipo, mensaje FROM mensajes WHERE vapi_id = ? ORDER BY orden',
            [$vapi]
        );
    }

    public function render()
    {
        return view('livewire.scoring-detalle');
    }
}

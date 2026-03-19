<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
class EtiquetarLlamada extends Component
{
    public function render()
    {
        return view('livewire.etiquetar-llamada');
    }
}

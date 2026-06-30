<?php

namespace App\Livewire\LogConductores;

use App\Database\DBLlamadas;
use Livewire\Component;
use Illuminate\Support\Facades\Validator;

class Padre extends Component
{
    public $titulo = 'Lista de Conductores';

    public function render()
    {
        // ✅ Validar
        $validator = Validator::make(request()->all(), [
            'fecha_inicio'     => 'nullable|date',
            'fecha_fin'        => 'nullable|date',
            'llamada_tipo_id'  => 'nullable|numeric',
            'conductor'        => 'nullable|string',
            'trt'              => 'nullable|numeric',
            'ordenar_por'      => 'nullable|string',
            'orden'            => 'nullable|in:asc,desc',
        ]);

        // ✅ Si falla, regresar atrás con errores
        $llamadas = new DBLlamadas();

        if ($validator->fails()){
            return view('errors.request', [
                'message' => $validator->errors()->first()
            ]);
        }
        return view('livewire.log-conductores.padre', compact('llamadas'));
    }

    public function nuevoLog($logData = null)
    {
        $this->dispatch('nuevoLog',$logData);
    }
}

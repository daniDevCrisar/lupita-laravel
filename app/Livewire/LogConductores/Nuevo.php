<?php

namespace App\Livewire\LogConductores;

use Livewire\Component;

class Nuevo extends Component
{
    public $showModal = false;
    public $logData = null;

    protected $listeners = ['nuevoLog' => 'openModal'];

    public function openModal($logData = null)
    {
        $this->logData = (object) $logData;
        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
    }

    public function render()
    {
        return view('livewire.log-conductores.nuevo');
    }
}

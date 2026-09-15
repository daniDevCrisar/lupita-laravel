<?php

namespace App\Database;

use AllowDynamicProperties;
use App\Tools\BuscarEnArray;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use stdClass;

#[AllowDynamicProperties]
class DBReporteAnual{
    public function __construct($start,$end){
        $this->start = $start;
        $this->end = $end;
    }
}

<?php

namespace App\Http\Controllers;

use App\Database\DBConductores;
use App\Database\Tmp\DBTmpLotes;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        return view('home');
    }

    public function test_nombres(){
        $lote_id='202608190922288386';
        $conductores = DBTmpLotes::obtenerConductoresDuplicados($lote_id);
        echo 'obtenerConductoresDuplicados'.count($conductores) . '<br>';
        $personas=DBTmpLotes::compararNombres($conductores,'telefono','conductor');//comparar datos parecidos
        echo 'compararNombres'.count($personas) . '<br>';

        $log='';
        foreach ($personas as $item){
            $accion= DBConductores::buscar_duplicados($item);
//            dd($accion);

//            $log.= $accion['accion'] . ': <br> Buscado ' . $item->conductor . ' - Encontrado '. $accion['row']->conductor.' ('. $accion['comparar'] .'%)<br>';

            $log_data= [$accion['accion'],$item->conductor,$accion['buscado'], $accion['comparado'] ,$accion['comparar']  ];
            $log.= "
            <tr>
                <td>$log_data[0]</td>
                <td>$log_data[1]</td>
                <td>$log_data[2]</td>
                <td>$log_data[3]</td>
                <td>$log_data[4]</td>
            </tr>
            ";
        }
        echo "<table border='1'><thead><th>accion</th><th>buscar</th><th>encontrado</th><th>asercion</th><th>Identico</th></thead>
           <tbody>$log</tbody></table>";


        $comparar=DBTmpLotes::similitud('NICOLAS GARRIDO', 'EDGAR NICOLAS GARRIDO YURIVILCA');
        echo "Comparar DBTmpLotes::similitud('NICOLAS GARRIDO', 'EDGAR NICOLAS GARRIDO YURIVILCA '); {$comparar} <BR>";
        $comparar=DBTmpLotes::similitud('GARRIDO YURIVILCA NICOLAS', 'EDGAR NICOLAS GARRIDO YURIVILCA');
        echo "Comparar DBTmpLotes::similitud('GARRIDO YURIVILCA NICOLAS', 'EDGAR NICOLAS GARRIDO YURIVILCA'); {$comparar}  <BR>";
        $comparar=DBTmpLotes::similitud('GARRIDO YURIVILCA EDGAR NICOLAS', 'EDGAR NICOLAS GARRIDO YURIVILCA');
        echo "Comparar DBTmpLotes::similitud('GARRIDO YURIVILCA EDGAR NICOLAS', 'EDGAR NICOLAS GARRIDO YURIVILCA'); {$comparar}  <BR>";

        dd($personas);
        return 'sdas';
    }
}

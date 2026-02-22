<?php

namespace App\Tools;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ExcelTool
{
    public static function leer($path)
    {
        $spreadsheet = IOFactory::load($path);

        $resultado = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $nombre = $sheet->getTitle();
            $rows = $sheet->toArray('',true , false, false);
            $resultado[$nombre] = $rows;
        }

        return $resultado;
    }

    public static function normalizarTexto($valor)
    {
        // null a string
        $valor = trim((string)($valor ?? ''));

        if ($valor === '') return '';

        // Reemplazo manual de tildes
        $buscar = ['á','é','í','ó','ú','Á','É','Í','Ó','Ú','ü','Ü','Ñ','ñ'];
        $reemplazar = ['a','e','i','o','u','A','E','I','O','U','u','U','N','n'];

        $valor = str_replace($buscar, $reemplazar, $valor);

        // Mayúsculas UTF8
        $valor = mb_strtoupper($valor, 'UTF-8');

        return $valor;
    }

    public static function limpiarExcelHojas(array $excel)
    {
        foreach ($excel as $nombreHoja => &$rows) {
            foreach ($rows as &$row) {
                foreach ($row as &$col) {
                    $col = self::normalizarTexto($col);
                }
            }
        }
        unset($rows, $row, $col);
        return $excel;
    }

    public static function generarLoteId()
    {
        $fecha = date('YmdHis'); // 20260205184530
        $random = mt_rand(100, 9999); // 3-4 dígitos

        return (int) ($fecha . $random);
    }


    public static function ordenarColumnasExcel(array $tabla_cols, array $registros, $lote_id  )
    {
        $excel_cols = $registros[0] ?? false;
        //----normalizar nombres de columnas del excel (reemplazar espacios por guiones bajos, eliminar caracteres especiales, etc)----------------
        $excel_cols = array_map(function($item) {
            $col_nom = str_replace(' ', '_', $item);
            $col_nom = str_replace('?', '', $col_nom);
            return $col_nom;
        }, $excel_cols);
        //---------------------------

        $col_excel_ordenadas = [];
        //dd($excel_cols,$tabla_cols);
        //--------buscar columnas requeridas en el excel----------------
        foreach ($tabla_cols as $columna) {
            $columna_n=ExcelTool::normalizarTexto($columna);
            $buscar = array_search($columna_n, $excel_cols);
            if ($buscar !== false) {
                $col_excel_ordenadas[$columna] = $buscar;
                //echo "Columna encontrada: " . $columna . " en posición " . $buscar . "<br>";
            } else {
                $col_excel_ordenadas[$columna] = '';
                //echo "Columna NO encontrada: " . $columna . "<br>" . $buscar;
            }

            if ($lote_id and $columna == 'lote_id') {
                //echo "Asignando lote_id: " . $lote_id . "<br>";
                $col_excel_ordenadas[$columna] = strval($lote_id);
            }
        }

        //$col_excel_ordenadas['vapi_id']=0;
        //dd($col_excel_ordenadas);

        //------ generar el array ordenado de registros con las columnas requeridas----------------
        array_shift($registros);//eliminar la fila de encabezados
        $registros_ord=[];
        foreach ($registros as $fila) {
            //$fila_ordenada[0] = $lote_id; //agregar el id lote al inicio de cada fila
            $count=0;
            foreach ($col_excel_ordenadas as $columna) {
                    //echo "Procesando columna: " . $columna . " con índice " . $count. "<br>";
                if (is_string($columna)) $fila_ordenada[$count] =$columna;
                else $fila_ordenada[$count] = $fila[$columna] ?? '';
                $count++;
            }
            $registros_ord[] = $fila_ordenada;// registros$registros
        }
        return $registros_ord;
    }


}

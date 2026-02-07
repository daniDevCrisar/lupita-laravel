<?php

namespace App\Tools;

use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelTool
{
    public static function leer($path)
    {
        $spreadsheet = IOFactory::load($path);

        $resultado = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $nombre = $sheet->getTitle();
            $rows = $sheet->toArray();
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
        $buscar = ['á','é','í','ó','ú','Á','É','Í','Ó','Ú','ü','Ü'];
        $reemplazar = ['a','e','i','o','u','A','E','I','O','U','u','U'];

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


}

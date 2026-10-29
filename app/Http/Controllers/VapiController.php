<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use ZipArchive;
use Exception;

class VapiController extends Controller
{
    public function index()
    {
        // PON AQUÍ TU URL DE GOOGLE APPS SCRIPT (la que termina en /exec)
        $api_url = "https://script.google.com/macros/s/AKfycbxukv_mXTJgCQJQKp7goliUs7K5LWunR9VFOxF9UI0kM-1YsIGNJV4fXw-D7hOJyjh5vA/exec";
        $correos = [];

        try {
            $response = Http::post($api_url, ['modo' => 'ultimos5']);
            if ($response->successful() && $response->json('exito')) {
                $correos = $response->json('data');
            }
        } catch (Exception $e) {
            // Si falla, la vista mostrará que no hay correos
        }

        return view('import-vapi.vapi', compact('correos'));
    }

    public function procesarZip(Request $request)
    {
        $urlZip = $request->input('url');

        if (!$urlZip) {
            return response()->json(['error' => 'URL no proporcionada'], 400);
        }

        try {
            // 1. Descargar el ZIP de Vapi
            $response = Http::timeout(120)->get($urlZip);

            if ($response->failed()) {
                throw new Exception('No se pudo descargar. HTTP: ' . $response->status());
            }

            // 2. Guardar temporal en storage/app
            $tempZipPath = storage_path('app/temp_vapi_' . time() . '.zip');
            file_put_contents($tempZipPath, $response->body());

            // 3. Descomprimir buscando el JSON
            $zip = new ZipArchive;
            if ($zip->open($tempZipPath) !== TRUE) {
                throw new Exception('Archivo ZIP corrupto.');
            }

            $jsonContent = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (str_ends_with(strtolower($filename), '.json')) {
                    $jsonContent = $zip->getFromIndex($i); // Lee el archivo directo a memoria
                    break;
                }
            }

            $zip->close();
            unlink($tempZipPath); // Borramos el ZIP temporal

            if (!$jsonContent) {
                throw new Exception('No se encontró ningún archivo JSON dentro del ZIP.');
            }

            // 4. Decodificamos el JSON
            $jsonArray = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('El archivo extraído no es un JSON válido.');
            }

            // 5. Lo enviamos al frontend
            return response()->json([
                'exito' => true,
                'data' => $jsonArray // Array listo para tu función JS
            ]);

        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}

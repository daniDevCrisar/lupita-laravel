<?php

namespace App\ElevenLabs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de la API de Conversational AI de Eleven Labs (solo lectura).
 *
 * Solo cubre lo que el modulo necesita:
 *   - GET /v1/convai/agents                              catalogo de asistentes
 *   - GET /v1/convai/conversations                       listado por rango
 *   - GET /v1/convai/conversations/{id}                  detalle completo
 *   - GET /v1/convai/conversations/{id}/audio            MP3 binario
 *
 * DOS COSAS QUE SORPRENDEN DE ESTA API (medidas el 2026-11-19):
 *
 * 1. `page_size` tiene tope 100 y NO se pagina con `page`. Se pagina con un
 *    `next_cursor` opaco (base64) + `has_more`. Si se ignora el cursor, un rango
 *    con mas de 100 llamadas devuelve 100 y parece completo. `conversaciones()`
 *    encadena cursores y avisa cuando se topa con `max_paginas`.
 *
 * 2. El audio NO soporta `Range`: responde 200 con el archivo entero, nunca 206.
 *    Por eso el reproductor no puede hacer seek. Ver `audio()`.
 */
class ElevenLabsClient
{
    public function __construct(
        private readonly string $key,
        private readonly string $base = 'https://api.elevenlabs.io',
        private readonly int $timeout = 30,
        private readonly int $pageSize = 100,
        private readonly int $maxPaginas = 40,
    ) {
    }

    public static function desdeConfig(): self
    {
        $c = (array) config('services.elevenlabs', []);

        return new self(
            (string) ($c['key'] ?? ''),
            rtrim((string) ($c['base'] ?? 'https://api.elevenlabs.io'), '/'),
            (int) ($c['timeout'] ?? 30),
            min(100, max(1, (int) ($c['page_size'] ?? 100))),
            max(1, (int) ($c['max_paginas'] ?? 40)),
        );
    }

    public function configurado(): bool
    {
        return trim($this->key) !== '';
    }

    /**
     * Catalogo de asistentes del workspace.
     *
     * @return array<int, array{agent_id:string, nombre:string, archivado:bool}>
     */
    public function agentes(): array
    {
        $this->verificarKey();

        $agentes = [];
        $cursor = null;
        $paginas = 0;

        do {
            $query = ['page_size' => $this->pageSize];
            if ($cursor) {
                $query['cursor'] = $cursor;
            }

            $datos = $this->pedir('/v1/convai/agents', $query);
            foreach (($datos['agents'] ?? []) as $a) {
                $agentes[] = [
                    'agent_id' => (string) ($a['agent_id'] ?? ''),
                    'nombre' => (string) ($a['name'] ?? ''),
                    'archivado' => (bool) ($a['archived'] ?? false),
                ];
            }

            $cursor = $datos['next_cursor'] ?? null;
            $hayMas = (bool) ($datos['has_more'] ?? false);
            $paginas++;
        } while ($hayMas && $cursor && $paginas < $this->maxPaginas);

        return $agentes;
    }

    /**
     * Listado crudo de conversaciones dentro de un rango de inicio de llamada.
     *
     * @param  int  $desdeUnix  epoch en SEGUNDOS (la API trabaja en segundos)
     * @param  int  $hastaUnix  epoch en SEGUNDOS
     * @param  string|null  $agentId  filtra por asistente
     * @return array{conversations: array<int, array>, paginas: int, has_more: bool, tope: bool}
     *
     * @throws ElevenLabsException
     */
    public function conversaciones(int $desdeUnix, int $hastaUnix, ?string $agentId = null): array
    {
        $this->verificarKey();

        $todas = [];
        $cursor = null;
        $paginas = 0;
        $hayMas = false;

        do {
            $query = [
                'page_size' => $this->pageSize,
                'call_start_after_unix' => $desdeUnix,
                'call_start_before_unix' => $hastaUnix,
            ];
            if ($agentId) {
                $query['agent_id'] = $agentId;
            }
            if ($cursor) {
                $query['cursor'] = $cursor;
            }

            $datos = $this->pedir('/v1/convai/conversations', $query);
            foreach (($datos['conversations'] ?? []) as $c) {
                $todas[] = $c;
            }

            $cursor = $datos['next_cursor'] ?? null;
            $hayMas = (bool) ($datos['has_more'] ?? false);
            $paginas++;
        } while ($hayMas && $cursor && $paginas < $this->maxPaginas);

        return [
            'conversations' => $todas,
            'paginas' => $paginas,
            'has_more' => $hayMas,
            'tope' => $hayMas && $paginas >= $this->maxPaginas,
        ];
    }

    /**
     * Detalle completo de una conversacion (transcripcion, analisis, variables).
     */
    public function detalle(string $conversationId): array
    {
        $this->verificarKey();
        $id = $this->validarId($conversationId);

        return $this->pedir('/v1/convai/conversations/' . $id);
    }

    /**
     * Respuesta en streaming del MP3. NO se guarda en disco: el controlador
     * bombea el body tal cual.
     *
     * El `stream => true` de Guzzle es lo que evita cargar el archivo entero en
     * memoria; con los ~2,7 KB/s medidos una llamada de 10 min son ~1,6 MB.
     */
    public function audio(string $conversationId): Response
    {
        $this->verificarKey();
        $id = $this->validarId($conversationId);

        $url = $this->base . '/v1/convai/conversations/' . $id . '/audio';

        try {
            return Http::withHeaders(['xi-api-key' => $this->key])
                ->withOptions(['stream' => true])
                ->timeout($this->timeout)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new ElevenLabsException('No se pudo conectar con Eleven Labs: ' . $e->getMessage(), 0);
        }
    }

    // ------------------------------------------------------------------ internos

    private function verificarKey(): void
    {
        if (! $this->configurado()) {
            throw new ElevenLabsException(
                'Falta ELEVENLABS_API_KEY en el .env',
                401,
                'sin_api_key'
            );
        }
    }

    /**
     * El id entra en la URL: solo se aceptan los caracteres que usa ElevenLabs
     * para no permitir que un valor manipulado cambie el path.
     */
    private function validarId(string $conversationId): string
    {
        $id = trim($conversationId);

        if ($id === '' || ! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id)) {
            throw new ElevenLabsException('conversation_id invalido', 400, 'id_invalido');
        }

        return $id;
    }

    /**
     * GET con un reintento corto ante 429. Los 429 de esta API traen
     * `retry_after_seconds` en el cuerpo cuando existe.
     */
    private function pedir(string $ruta, array $query = []): array
    {
        $url = $this->base . $ruta;
        $intentos = 0;

        while (true) {
            try {
                $respuesta = Http::withHeaders(['xi-api-key' => $this->key])
                    ->acceptJson()
                    ->timeout($this->timeout)
                    ->get($url, $query);
            } catch (ConnectionException $e) {
                throw new ElevenLabsException('No se pudo conectar con Eleven Labs: ' . $e->getMessage(), 0);
            }

            if ($respuesta->successful()) {
                $json = $respuesta->json();

                return is_array($json) ? $json : [];
            }

            if ($respuesta->status() === 429 && $intentos < 1) {
                $intentos++;
                $espera = (int) ($respuesta->json('detail.retry_after_seconds')
                    ?? $respuesta->header('retry-after')
                    ?? 2);
                sleep(max(1, min(10, $espera)));

                continue;
            }

            throw ElevenLabsException::desdeRespuesta($respuesta->status(), $respuesta->json());
        }
    }
}

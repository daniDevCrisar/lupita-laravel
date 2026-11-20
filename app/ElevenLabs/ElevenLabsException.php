<?php

namespace App\ElevenLabs;

use RuntimeException;

/**
 * Error de la API de Eleven Labs con el contexto ya parseado.
 *
 * La API responde dos formas distintas y las dos se normalizan aqui:
 *   401/404  {"detail": {"type","code","message","status","request_id"}}
 *   422      {"detail": [{"type","loc":[...],"msg","input"}]}
 *
 * Se guarda el status HTTP y el codigo de la API porque el controlador decide
 * distinto segun el caso: 401 es "la key no sirve" (configuracion), 404 es "esa
 * conversacion no existe" (dato), 429 es "espera" (reintentable).
 */
class ElevenLabsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $codigoApi = '',
        public readonly array $cuerpo = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function desdeRespuesta(int $status, $cuerpo): self
    {
        $detalle = $cuerpo['detail'] ?? null;
        $codigo = '';
        $mensaje = 'La API de Eleven Labs respondio ' . $status;

        if (is_array($detalle)) {
            if (isset($detalle['message'])) {
                // forma objeto: {type, code, message, status}
                $mensaje = (string) $detalle['message'];
                $codigo = (string) ($detalle['code'] ?? $detalle['type'] ?? '');
            } else {
                // forma lista (422 de validacion): [{type, loc, msg}]
                $partes = [];
                foreach ($detalle as $item) {
                    $loc = implode('.', (array) ($item['loc'] ?? []));
                    $partes[] = trim($loc . ': ' . ($item['msg'] ?? ''));
                }
                if ($partes) {
                    $mensaje = implode(' | ', $partes);
                }
                $codigo = (string) ($detalle[0]['type'] ?? '');
            }
        } elseif (is_string($detalle) && $detalle !== '') {
            $mensaje = $detalle;
        }

        return new self($mensaje, $status, $codigo, is_array($cuerpo) ? $cuerpo : []);
    }

    public function esSinKey(): bool
    {
        return $this->status === 401;
    }

    public function esSinPermiso(): bool
    {
        return $this->codigoApi === 'missing_permissions';
    }

    public function esNoEncontrado(): bool
    {
        return $this->status === 404;
    }
}

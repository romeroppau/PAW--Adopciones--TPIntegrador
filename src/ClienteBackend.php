<?php

// Llamada interna de Adopciones a Backend (de proceso a proceso, sin pasar por Nginx) para dejar un
// aviso. Contrato: POST /internal/notificaciones (plan, sección 9). Mismo mecanismo que ClientePacientes.
class ClienteBackend
{
    // Devuelve ['codigo' => HTTP, 'datos' => respuesta]. Código 0 si no se pudo comunicar.
    public static function notificar(array $aviso): array
    {
        $token = Config::get('INTERNAL_TOKEN');

        if (!is_string($token) || $token === '') {
            Logger::error('Adopciones: falta INTERNAL_TOKEN para llamar a Backend');
            return ['codigo' => 0, 'datos' => []];
        }

        $base = Config::get('BACKEND_URL') ?: 'http://127.0.0.1:9001';

        $contexto = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Servicio-Token: $token\r\n",
            'content' => json_encode($aviso, JSON_UNESCAPED_UNICODE),
            'timeout' => 3, // un aviso no puede demorar la acción del usuario
            'ignore_errors' => true
        ]]);

        $cuerpo = @file_get_contents($base . '/internal/notificaciones', false, $contexto);

        if ($cuerpo === false) {
            return ['codigo' => 0, 'datos' => []];
        }

        $cabeceras = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);

        $codigo = 0;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $cabeceras[0] ?? '', $coincidencia)) {
            $codigo = (int) $coincidencia[1];
        }

        $datos = json_decode($cuerpo, true);

        return ['codigo' => $codigo, 'datos' => is_array($datos) ? $datos : []];
    }
}

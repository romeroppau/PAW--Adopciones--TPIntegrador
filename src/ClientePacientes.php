<?php

// Llamada interna de Adopciones a Pacientes (de proceso a proceso, sin pasar por Nginx) para pasar la
// mascota al adoptante. Contrato: contrato-adopciones.md, sección 7. Mismo mecanismo que
// ClienteInventario de Pacientes.
class ClientePacientes
{
    // Devuelve ['codigo' => HTTP, 'datos' => respuesta]. Código 0 si no se pudo comunicar.
    public static function transferir(int $mascotaId, int $duenioActualId, int $nuevoDuenioId): array
    {
        $token = Config::get('INTERNAL_TOKEN');

        if (!is_string($token) || $token === '') {
            Logger::error('Adopciones: falta INTERNAL_TOKEN para llamar a Pacientes');
            return ['codigo' => 0, 'datos' => []];
        }

        $base = Config::get('PACIENTES_URL') ?: 'http://127.0.0.1:9003';

        $contexto = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Servicio-Token: $token\r\n",
            'content' => json_encode(['duenio_actual_id' => $duenioActualId, 'nuevo_duenio_id' => $nuevoDuenioId]),
            'timeout' => 5,
            'ignore_errors' => true // para leer el cuerpo también cuando responde 409 o 401
        ]]);

        $cuerpo = @file_get_contents($base . '/internal/mascotas/' . $mascotaId . '/transferir', false, $contexto);

        if ($cuerpo === false) {
            Logger::error('Adopciones: Pacientes no respondió al transferir la mascota ' . $mascotaId);
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

<?php

// Llamadas internas de Adopciones a Pacientes (de proceso a proceso, sin pasar por Nginx): pasar la
// mascota al adoptante y cancelar el turno del chequeo cuando se da de baja la publicación.
// Contrato: contrato-adopciones.md, sección 7. Mismo mecanismo que ClienteInventario de Pacientes.
class ClientePacientes
{
    // Devuelve ['codigo' => HTTP, 'datos' => respuesta]. Código 0 si no se pudo comunicar.
    public static function transferir(int $mascotaId, int $duenioActualId, int $nuevoDuenioId): array
    {
        return self::pedir(
            '/internal/mascotas/' . $mascotaId . '/transferir',
            ['duenio_actual_id' => $duenioActualId, 'nuevo_duenio_id' => $nuevoDuenioId],
            'transferir la mascota ' . $mascotaId
        );
    }

    // Cancela el turno del chequeo si sigue programado. Devuelve true si quedó cancelado (por este
    // pedido o porque ya lo estaba), false si Pacientes no pudo (o el turno ya se atendió).
    public static function cancelarChequeo(int $turnoId): bool
    {
        $respuesta = self::pedir('/internal/turnos/' . $turnoId . '/cancelar-chequeo', (object) [], 'cancelar el turno ' . $turnoId);

        return $respuesta['codigo'] === 200
            && (($respuesta['datos']['cancelado'] ?? false) === true || ($respuesta['datos']['estado'] ?? '') === 'CANCELADO');
    }

    private static function pedir(string $ruta, array|object $cuerpo, string $que): array
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
            'content' => json_encode($cuerpo),
            'timeout' => 5,
            'ignore_errors' => true // para leer el cuerpo también cuando responde 409 o 401
        ]]);

        $respuesta = @file_get_contents($base . $ruta, false, $contexto);

        if ($respuesta === false) {
            Logger::error('Adopciones: Pacientes no respondió al ' . $que);
            return ['codigo' => 0, 'datos' => []];
        }

        $cabeceras = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);

        $codigo = 0;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $cabeceras[0] ?? '', $coincidencia)) {
            $codigo = (int) $coincidencia[1];
        }

        $datos = json_decode($respuesta, true);

        return ['codigo' => $codigo, 'datos' => is_array($datos) ? $datos : []];
    }
}

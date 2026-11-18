<?php

// Avisos del circuito de adopción (contrato-adopciones.md, sección 8). Arma cada aviso con un
// id_operacion fijo (un reintento no lo repite) y se lo pasa a Backend por /internal/notificaciones.
// Si Backend no responde, la acción del circuito se hace igual: el aviso no es parte de la transacción.
class AvisosAdopcion
{
    // Llega una postulación: a la rescatista.
    public static function postulacionNueva(int $postulacionId, int $rescatistaId, string $cliente, string $mascota): array
    {
        return self::aviso(
            $rescatistaId,
            'POSTULACION',
            "$cliente se postuló para adoptar a $mascota. Revisá la postulación en Mis publicaciones.",
            "postulacion-nueva-$postulacionId"
        );
    }

    // La rescatista elige al adoptante: al cliente elegido.
    public static function postulacionElegida(int $postulacionId, int $clienteId, string $mascota): array
    {
        return self::aviso(
            $clienteId,
            'POSTULACION',
            "¡Te eligieron para adoptar a $mascota! Te vamos a contactar para coordinar el chequeo en la veterinaria.",
            "postulacion-elegida-$postulacionId"
        );
    }

    // Se completa la adopción: al adoptante.
    public static function adopcionFinalizada(int $adopcionId, int $adoptanteId, string $mascota): array
    {
        return self::aviso(
            $adoptanteId,
            'CONTROL_ADOPCION',
            "¡La adopción de $mascota está completa! Ya la tenés en Mis mascotas, con su historial. Te vamos a escribir a los 30 y a los 90 días para saber cómo está.",
            "adopcion-finalizada-$adopcionId"
        );
    }

    // Manda el aviso. Nunca corta la acción: si falla, queda en el log y devuelve false.
    public static function enviar(array $aviso): bool
    {
        $respuesta = ClienteBackend::notificar($aviso);

        if ($respuesta['codigo'] !== 200) {
            Logger::error("Adopciones: no se pudo dejar el aviso {$aviso['id_operacion']} (Backend respondió {$respuesta['codigo']})");
            return false;
        }

        return true;
    }

    private static function aviso(int $usuarioId, string $tipo, string $mensaje, string $idOperacion): array
    {
        return [
            'usuario_id' => $usuarioId,
            'tipo' => $tipo,
            'canal' => 'WEB',
            'mensaje' => mb_substr($mensaje, 0, 500),
            'fecha_programada' => date('Y-m-d H:i:s'),
            'id_operacion' => $idOperacion,
        ];
    }
}

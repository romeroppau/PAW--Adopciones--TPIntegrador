<?php

// Avisos del circuito de adopción (contrato-adopciones.md, sección 8). Arma cada aviso con un
// id_operacion fijo (un reintento no lo repite) y se lo pasa a Backend por /internal/notificaciones.
// Si Backend no responde, la acción del circuito se hace igual: el aviso no es parte de la transacción.
class AvisosAdopcion
{
    // La rescatista publica un animal: a cada veterinaria, para que lo revise.
    public static function publicacionNueva(int $publicacionId, int $veterinariaId, string $rescatista, string $mascota): array
    {
        return self::aviso(
            $veterinariaId,
            'POSTULACION',
            "$rescatista publicó a $mascota para adopción y espera tu revisión. Está en Adopciones y rescatistas → Publicaciones por revisar.",
            "publicacion-nueva-$publicacionId-vet-$veterinariaId"
        );
    }

    // La veterinaria aprueba o rechaza la publicación: a la rescatista. $momento (AAAAMMDDHHMMSS) distingue
    // un segundo rechazo después de editarla.
    public static function publicacionModerada(int $publicacionId, int $rescatistaId, string $mascota, ?string $motivoRechazo, string $momento): array
    {
        if ($motivoRechazo === null) {
            return self::aviso(
                $rescatistaId,
                'POSTULACION',
                "¡Aprobamos la publicación de $mascota! Ya se ve en Adopciones y pueden postularse.",
                "publicacion-$publicacionId-aprobada"
            );
        }

        return self::aviso(
            $rescatistaId,
            'POSTULACION',
            "No aprobamos la publicación de $mascota. Motivo: $motivoRechazo. Podés editarla en Mis publicaciones y vuelve a revisión.",
            "publicacion-$publicacionId-rechazada-$momento"
        );
    }

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

    // El cliente cancela su postulación (24/11): a la rescatista.
    public static function postulacionCancelada(int $postulacionId, int $rescatistaId, string $cliente, string $mascota): array
    {
        return self::aviso(
            $rescatistaId,
            'POSTULACION',
            "$cliente canceló su postulación para adoptar a $mascota.",
            "postulacion-cancelada-$postulacionId"
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

    // La veterinaria asigna el turno del chequeo (o lo reprograma): al adoptante y a la rescatista,
    // por la campana y por WhatsApp. Un aviso por persona y por canal, cada uno con su id_operacion;
    // si se reprograma, el turno nuevo tiene otro id y el aviso vuelve a salir.
    public static function chequeoAsignado(
        int $adopcionId,
        int $turnoId,
        int $adoptanteId,
        int $rescatistaId,
        string $adoptante,
        string $mascota,
        string $fecha,
        string $hora
    ): array {
        $cuando = self::cuando($fecha, $hora);
        $avisos = [];

        foreach (['WEB' => 'web', 'WHATSAPP' => 'whatsapp'] as $canal => $sufijo) {
            $avisos[] = self::aviso(
                $adoptanteId,
                'POSTULACION',
                "Tenés turno para el chequeo de adopción de $mascota el $cuando en HuellaVet (Av. San Martín 1450, Luján). Si no podés ir, avisanos por WhatsApp.",
                "chequeo-$adopcionId-$turnoId-adoptante-$sufijo",
                $canal
            );
            $avisos[] = self::aviso(
                $rescatistaId,
                'POSTULACION',
                "$adoptante tiene turno para el chequeo de adopción de $mascota el $cuando en HuellaVet.",
                "chequeo-$adopcionId-$turnoId-rescatista-$sufijo",
                $canal
            );
        }

        return $avisos;
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

    // "2026-11-26" y "10:00:00" → "jueves 26/11 a las 10:00".
    public static function cuando(string $fecha, string $hora): string
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $momento = strtotime($fecha . ' 12:00:00');

        return $dias[(int) date('w', $momento)] . ' ' . date('d/m', $momento) . ' a las ' . substr($hora, 0, 5);
    }

    private static function aviso(int $usuarioId, string $tipo, string $mensaje, string $idOperacion, string $canal = 'WEB'): array
    {
        return [
            'usuario_id' => $usuarioId,
            'tipo' => $tipo,
            'canal' => $canal,
            'mensaje' => mb_substr($mensaje, 0, 500),
            'fecha_programada' => date('Y-m-d H:i:s'),
            'id_operacion' => $idOperacion,
        ];
    }
}

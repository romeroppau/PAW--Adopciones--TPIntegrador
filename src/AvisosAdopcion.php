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
            "$rescatista publicó a $mascota para adopción y espera tu revisión. Está en Adopción → Publicaciones.",
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
    // $cuando (AAAAMMDDHHMMSS): solo si volvió a postularse después de cancelar, para que el id_operacion
    // sea otro y Backend no lo tome como un reintento del primer aviso.
    public static function postulacionNueva(int $postulacionId, int $rescatistaId, string $cliente, string $mascota, ?string $cuando = null): array
    {
        return self::aviso(
            $rescatistaId,
            'POSTULACION',
            "$cliente se postuló para adoptar a $mascota. Revisá la postulación en Mis publicaciones.",
            "postulacion-nueva-$postulacionId" . ($cuando === null ? '' : "-$cuando")
        );
    }

    // El cliente cancela su postulación (24/11): a la rescatista. $cuando (AAAAMMDDHHMMSS) va en el
    // id_operacion porque puede volver a postularse y cancelar otra vez.
    public static function postulacionCancelada(int $postulacionId, int $rescatistaId, string $cliente, string $mascota, string $cuando): array
    {
        return self::aviso(
            $rescatistaId,
            'POSTULACION',
            "$cliente canceló su postulación para adoptar a $mascota.",
            "postulacion-cancelada-$postulacionId-$cuando"
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

    // La rescatista elige al adoptante: a cada veterinaria, para que coordine y cargue el turno del
    // chequeo (si no, se entera recién cuando revisa las adopciones en curso). Va el WhatsApp del
    // adoptante, si lo tiene, porque el turno se coordina por ahí. El id_operacion lleva la postulación:
    // si la adopción vuelve a tener que elegir y elige a otro, es otro aviso.
    public static function chequeoPorCoordinar(int $postulacionId, int $veterinariaId, string $adoptante, string $mascota, ?string $telefono): array
    {
        $contacto = $telefono === null || trim($telefono) === '' ? '' : " (WhatsApp $telefono)";

        return self::aviso(
            $veterinariaId,
            'POSTULACION',
            "Eligieron a $adoptante$contacto para adoptar a $mascota y espera el turno del chequeo. Coordinalo, cargalo en la Agenda y asignalo en Adopción → Adopciones en curso.",
            "postulacion-elegida-$postulacionId-vet-$veterinariaId"
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

    // La rescatista da de baja la publicación (contrato-adopciones.md, "Dar de baja"). $baja es lo que
    // devuelve AdopcionRepositorio::darDeBaja; $turnoCancelado, si Pacientes canceló el turno del
    // chequeo (null si no había uno programado). Devuelve la lista de avisos:
    //   - a cada postulante pendiente: que se dio de baja;
    //   - al adoptante elegido (web y WhatsApp): que la adopción no sigue y, si había, que se canceló el turno;
    //   - a cada veterinaria, si había adoptante: lo mismo, y si el turno no se pudo cancelar, que lo haga en la agenda.
    public static function publicacionDadaDeBaja(int $publicacionId, array $baja, array $veterinarias, string $rescatista, ?bool $turnoCancelado): array
    {
        $mascota = $baja['mascota'];
        $avisos = [];

        foreach ($baja['postulantes'] as $clienteId) {
            $avisos[] = self::aviso(
                $clienteId,
                'POSTULACION',
                "La publicación de $mascota se dio de baja: la rescatista ya no la ofrece en adopción. ¡Gracias por postularte!",
                "baja-$publicacionId-postulante-$clienteId"
            );
        }

        if ($baja['adoptante_id'] === null) {
            return $avisos;
        }

        $turno = $baja['turno'];
        $cuando = $turno === null ? null : self::cuando($turno['fecha'], $turno['hora']);
        $delTurno = $turnoCancelado === true ? " Se canceló tu turno del chequeo del $cuando." : '';

        foreach (['WEB' => 'web', 'WHATSAPP' => 'whatsapp'] as $canal => $sufijo) {
            $avisos[] = self::aviso(
                $baja['adoptante_id'],
                'POSTULACION',
                "La rescatista dio de baja la publicación de $mascota, así que la adopción no sigue.$delTurno Si tenés dudas, escribinos.",
                "baja-$publicacionId-adoptante-$sufijo",
                $canal
            );
        }

        $paraLaVeterinaria = match (true) {
            $turnoCancelado === true => " Se canceló el turno del chequeo del $cuando.",
            $turnoCancelado === false => " No se pudo cancelar el turno del chequeo del $cuando: cancelalo en la agenda.",
            default => '',
        };

        foreach ($veterinarias as $veterinariaId) {
            $avisos[] = self::aviso(
                $veterinariaId,
                'POSTULACION',
                "$rescatista dio de baja la publicación de $mascota: la adopción con {$baja['adoptante']} no sigue.$paraLaVeterinaria",
                "baja-$publicacionId-veterinaria-$veterinariaId"
            );
        }

        return $avisos;
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

    // "2026-11-26" y "10:00:00" → "jueves 26/11/2026 a las 10:00".
    public static function cuando(string $fecha, string $hora): string
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $momento = strtotime($fecha . ' 12:00:00');

        return $dias[(int) date('w', $momento)] . ' ' . date('d/m/Y', $momento) . ' a las ' . substr($hora, 0, 5);
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

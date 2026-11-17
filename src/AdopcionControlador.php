<?php

// Postulaciones, elección del adoptante, chequeo y cierre (contrato-adopciones.md, secciones 5 y 6).
class AdopcionControlador
{
    private const ESTADOS = ['PUBLICADA', 'CON_POSTULANTES', 'ADOPTANTE_ELEGIDO', 'CHEQUEO_PENDIENTE', 'FINALIZADA', 'CANCELADA'];

    // GET /api/adopciones — veterinaria. Sin estado: las que no terminaron.
    public static function listar(): void
    {
        Auth::requerirRol(['VETERINARIO']);
        $estado = Request::query()['estado'] ?? null;

        if ($estado !== null && !in_array($estado, self::ESTADOS, true)) {
            Response::error('El estado no es válido', 400, ['estado' => 'No es un estado de adopción']);
            return;
        }

        Response::json([
            'adopciones' => array_map(
                [AdopcionRepositorio::class, 'formatoAdopcion'],
                self::repositorio()->adopciones($estado)
            ),
        ]);
    }

    // POST /api/adopciones/{id}/postulaciones — cliente.
    public static function postularse(array $parametros): void
    {
        $sesion = Auth::requerirRol(['CLIENTE']);
        Auth::verificarCsrf($sesion);
        $clienteId = (int) $sesion['id'];

        $id = ParametroRuta::entero($parametros, 'id');
        if ($id === null) {
            Response::error('Adopción no encontrada', 404);
            return;
        }

        [$datos, $errores] = ValidadorAdopciones::postulacion(Request::json());
        if ($errores !== []) {
            Response::error('Revisá los datos de la postulación', 400, $errores);
            return;
        }

        $repositorio = self::repositorio();
        [$resultado, $postulacionId] = $repositorio->postularse($id, $clienteId, $datos, date('Y-m-d H:i:s'));

        match ($resultado) {
            'NO_EXISTE' => Response::error('Adopción no encontrada', 404),
            'ESTADO' => Response::error('La adopción ya no recibe postulaciones', 409),
            'YA_POSTULADO' => Response::error('Ya te postulaste a esta adopción', 409),
            default => self::postulado($repositorio, $postulacionId, $clienteId, $id),
        };
    }

    private static function postulado(AdopcionRepositorio $repositorio, int $postulacionId, int $clienteId, int $adopcionId): void
    {
        Logger::info("Adopciones: el cliente $clienteId se postuló a la adopción $adopcionId (postulación $postulacionId)");
        Response::json(['postulacion' => AdopcionRepositorio::formatoPostulacion($repositorio->postulacion($postulacionId))], 201);
    }

    // GET /api/adopciones/mis-postulaciones — cliente.
    public static function misPostulaciones(): void
    {
        $sesion = Auth::requerirRol(['CLIENTE']);

        Response::json([
            'postulaciones' => array_map(
                [AdopcionRepositorio::class, 'formatoPostulacion'],
                self::repositorio()->postulacionesDeCliente((int) $sesion['id'])
            ),
        ]);
    }

    // GET /api/adopciones/{id}/postulaciones — el rescatista de la publicación o la veterinaria.
    public static function postulaciones(array $parametros): void
    {
        $sesion = Auth::requerirRol(['RESCATISTA', 'VETERINARIO']);
        $repositorio = self::repositorio();
        $adopcion = self::adopcionVisible($repositorio, $parametros, $sesion);

        if ($adopcion === null) {
            Response::error('Adopción no encontrada', 404);
            return;
        }

        self::responderConPostulaciones($repositorio, $adopcion, false);
    }

    // POST /api/adopciones/{id}/elegir — el rescatista de la publicación. {"postulacion_id": 101}
    public static function elegir(array $parametros): void
    {
        $sesion = Auth::requerirRol(['RESCATISTA']);
        Auth::verificarCsrf($sesion);

        $id = ParametroRuta::entero($parametros, 'id');
        $postulacionId = ValidadorAdopciones::id(Request::json(), 'postulacion_id');

        if ($id !== null && $postulacionId === null) {
            Response::error('Elegí una postulación', 400, ['postulacion_id' => 'Falta la postulación elegida']);
            return;
        }

        $repositorio = self::repositorio();
        $resultado = $id === null ? 'NO_EXISTE' : $repositorio->elegir($id, (int) $sesion['id'], $postulacionId);

        match ($resultado) {
            'NO_EXISTE' => Response::error('Adopción no encontrada', 404),
            'NO_EXISTE_POSTULACION' => Response::error('Postulación no encontrada', 404),
            'ESTADO' => Response::error('La adopción no está esperando que elijas un adoptante', 409),
            'POSTULACION_NO_PENDIENTE' => Response::error('Esa postulación ya no está pendiente', 409),
            default => self::elegido($repositorio, $id, $postulacionId),
        };
    }

    private static function elegido(AdopcionRepositorio $repositorio, int $id, int $postulacionId): void
    {
        Logger::info("Adopciones: se eligió la postulación $postulacionId en la adopción $id");
        self::responderConPostulaciones($repositorio, $repositorio->adopcion($id), true);
    }

    // POST /api/adopciones/{id}/chequeo — veterinaria. {"turno_id": 120}
    public static function chequeo(array $parametros): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);

        $id = ParametroRuta::entero($parametros, 'id');
        $turnoId = ValidadorAdopciones::id(Request::json(), 'turno_id');

        if ($id !== null && $turnoId === null) {
            Response::error('Elegí el turno del chequeo', 400, ['turno_id' => 'Falta el turno del chequeo']);
            return;
        }

        $repositorio = self::repositorio();
        $resultado = $id === null ? 'NO_EXISTE' : $repositorio->asociarChequeo($id, $turnoId);

        if (str_starts_with($resultado, 'TURNO:')) {
            Response::error('El turno no sirve como chequeo', 400, ['turno_id' => substr($resultado, 6)]);
            return;
        }

        match ($resultado) {
            'NO_EXISTE' => Response::error('Adopción no encontrada', 404),
            'ESTADO' => Response::error('La adopción no está esperando el chequeo', 409),
            default => self::responderAdopcion($repositorio, $id, "se asoció el turno $turnoId como chequeo"),
        };
    }

    // POST /api/adopciones/{id}/completar — veterinaria. Pide a Pacientes que pase la mascota al
    // adoptante y recién después cierra la adopción. Reintentar es seguro: Pacientes responde
    // "transferida: false" si ya estaba hecho.
    public static function completar(array $parametros): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);

        $id = ParametroRuta::entero($parametros, 'id');
        $repositorio = self::repositorio();
        $adopcion = $id === null ? null : $repositorio->adopcion($id);

        if ($adopcion === null) {
            Response::error('Adopción no encontrada', 404);
            return;
        }

        if ($adopcion['adopcion_estado'] !== 'CHEQUEO_PENDIENTE') {
            Response::error('La adopción no está esperando el chequeo', 409);
            return;
        }

        if ($adopcion['turno_estado'] !== 'ATENDIDO') {
            Response::error('El chequeo todavía no se atendió', 409);
            return;
        }

        $respuesta = ClientePacientes::transferir(
            (int) $adopcion['mascota_id'],
            (int) $adopcion['rescatista_id'],
            (int) $adopcion['adoptante_id']
        );

        if ($respuesta['codigo'] !== 200) {
            Logger::error("Adopciones: Pacientes respondió {$respuesta['codigo']} al transferir la mascota de la adopción $id");
            Response::error('No se pudo transferir la mascota. Probá de nuevo.', 502);
            return;
        }

        $repositorio->finalizar($id, date('Y-m-d H:i:s'));
        self::responderAdopcion($repositorio, $id, 'finalizó: la mascota ' . $adopcion['mascota_id'] . ' pasó al cliente ' . $adopcion['adoptante_id']);
    }

    // POST /api/adopciones/{id}/cancelar — el rescatista de la publicación o la veterinaria.
    public static function cancelar(array $parametros): void
    {
        $sesion = Auth::requerirRol(['RESCATISTA', 'VETERINARIO']);
        Auth::verificarCsrf($sesion);

        $id = ParametroRuta::entero($parametros, 'id');
        $rescatistaId = $sesion['rol'] === 'RESCATISTA' ? (int) $sesion['id'] : null;
        $repositorio = self::repositorio();
        $resultado = $id === null ? 'NO_EXISTE' : $repositorio->cancelar($id, $rescatistaId, date('Y-m-d H:i:s'));

        match ($resultado) {
            'NO_EXISTE' => Response::error('Adopción no encontrada', 404),
            'ESTADO' => Response::error('La adopción ya terminó', 409),
            default => self::responderAdopcion($repositorio, $id, 'se canceló (usuario ' . $sesion['id'] . ')'),
        };
    }

    // La adopción, si quien pide la puede ver: la veterinaria todas, el rescatista las suyas.
    private static function adopcionVisible(AdopcionRepositorio $repositorio, array $parametros, array $sesion): ?array
    {
        $id = ParametroRuta::entero($parametros, 'id');
        $adopcion = $id === null ? null : $repositorio->adopcion($id);

        if ($adopcion === null) {
            return null;
        }

        if ($sesion['rol'] === 'RESCATISTA' && (int) $adopcion['rescatista_id'] !== (int) $sesion['id']) {
            return null;
        }

        return $adopcion;
    }

    private static function responderConPostulaciones(AdopcionRepositorio $repositorio, array $adopcion, bool $completa): void
    {
        Response::json([
            'adopcion' => $completa
                ? AdopcionRepositorio::formatoAdopcion($adopcion)
                : ['id' => (int) $adopcion['adopcion_id'], 'estado' => $adopcion['adopcion_estado']],
            'postulaciones' => array_map(
                [AdopcionRepositorio::class, 'formatoPostulacion'],
                $repositorio->postulacionesDeAdopcion((int) $adopcion['adopcion_id'])
            ),
        ]);
    }

    private static function responderAdopcion(AdopcionRepositorio $repositorio, int $id, string $evento): void
    {
        Logger::info("Adopciones: la adopción $id $evento");
        Response::json(['adopcion' => AdopcionRepositorio::formatoAdopcion($repositorio->adopcion($id))]);
    }

    private static function repositorio(): AdopcionRepositorio
    {
        return new AdopcionRepositorio(Database::conectar());
    }
}

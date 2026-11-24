<?php

// Publicaciones y moderación (contrato-adopciones.md, secciones 3 y 4).
class PublicacionControlador
{
    private const ESPECIES = ['PERRO', 'GATO', 'OTRO'];

    // GET /api/adopciones/publicaciones — público: "Animales disponibles".
    public static function listar(): void
    {
        $especie = Request::query()['especie'] ?? null;

        if ($especie !== null && !in_array($especie, self::ESPECIES, true)) {
            Response::error('La especie no es válida', 400, ['especie' => 'Elegí PERRO, GATO u OTRO']);
            return;
        }

        self::responderLista(self::repositorio()->disponibles($especie), false);
    }

    // GET /api/adopciones/publicaciones/{id} — público si está disponible; el rescatista que la cargó
    // y la veterinaria, en cualquier estado.
    public static function ver(array $parametros): void
    {
        $visible = self::visible($parametros);

        if ($visible === null) {
            Response::error('Publicación no encontrada', 404);
            return;
        }

        [$fila, $conConteo] = $visible;
        Response::json(['publicacion' => AdopcionRepositorio::formatoPublicacion($fila, $conConteo)]);
    }

    // GET /api/adopciones/publicaciones/{id}/foto — la imagen, para usarla directo en un <img>.
    public static function foto(array $parametros): void
    {
        $visible = self::visible($parametros);
        $foto = $visible === null ? null : self::repositorio()->foto((int) $visible[0]['id']);

        if ($foto === null || !in_array($foto[1], Imagen::TIPOS, true)) {
            Response::error('Foto no encontrada', 404);
            return;
        }

        // El tipo sale de la lista fija (Imagen::TIPOS), nunca de lo que se guardó sin revisar.
        header('Content-Type: ' . $foto[1]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        echo $foto[0];
    }

    // POST /api/adopciones/publicaciones — rescatista aprobado.
    public static function crear(): void
    {
        $sesion = Auth::requerirRol(['RESCATISTA']);
        Auth::verificarCsrf($sesion);
        $rescatistaId = (int) $sesion['id'];
        $repositorio = self::repositorio();

        if (!$repositorio->rescatistaAprobado($rescatistaId)) {
            Response::error('Tu cuenta todavía no fue aprobada', 403);
            return;
        }

        [$datos, $errores] = ValidadorAdopciones::publicacion(Request::json());

        if ($errores === [] && !$repositorio->mascotaACargo($datos['mascota_id'], $rescatistaId)) {
            $errores['mascota_id'] = 'Elegí una mascota que tengas a cargo';
        }

        if ($errores !== []) {
            Response::error('Revisá los datos de la publicación', 400, $errores);
            return;
        }

        $id = $repositorio->crearPublicacion($rescatistaId, $datos, date('Y-m-d H:i:s'));

        if ($id === null) {
            Response::error('La mascota ya tiene una publicación abierta', 409);
            return;
        }

        Logger::info("Adopciones: el rescatista $rescatistaId publicó la mascota {$datos['mascota_id']} (publicación $id)");
        $publicacion = $repositorio->publicacion($id);

        // Aviso a cada veterinaria: hay una publicación para revisar. No corta la respuesta.
        foreach ($repositorio->veterinarias() as $veterinariaId) {
            AvisosAdopcion::enviar(AvisosAdopcion::publicacionNueva(
                $id,
                $veterinariaId,
                (string) $publicacion['rescatista_nombre'],
                (string) $publicacion['mascota_nombre']
            ));
        }

        Response::json(['publicacion' => AdopcionRepositorio::formatoPublicacion($publicacion, true)], 201);
    }

    // PUT /api/adopciones/publicaciones/{id} — el rescatista edita la suya (activa).
    public static function editar(array $parametros): void
    {
        $sesion = Auth::requerirRol(['RESCATISTA']);
        Auth::verificarCsrf($sesion);
        $rescatistaId = (int) $sesion['id'];

        $id = ParametroRuta::entero($parametros, 'id');
        if ($id === null) {
            Response::error('Publicación no encontrada', 404);
            return;
        }

        [$datos, $errores] = ValidadorAdopciones::edicion(Request::json());
        if ($errores !== []) {
            Response::error('Revisá los datos de la publicación', 400, $errores);
            return;
        }

        $repositorio = self::repositorio();

        match ($repositorio->editarPublicacion($id, $rescatistaId, $datos)) {
            'NO_EXISTE' => Response::error('Publicación no encontrada', 404),
            'INACTIVA' => Response::error('La publicación ya no está activa', 409),
            'OTRA_ABIERTA' => Response::error('La mascota ya tiene otra publicación abierta', 409),
            default => self::respuestaPublicacion($repositorio, $id, "el rescatista $rescatistaId editó la publicación $id"),
        };
    }

    // DELETE /api/adopciones/publicaciones/{id} — el rescatista da de baja la suya. Si tenía una
    // adopción en curso, se cancela. 204 sin cuerpo.
    public static function darDeBaja(array $parametros): void
    {
        $sesion = Auth::requerirRol(['RESCATISTA']);
        Auth::verificarCsrf($sesion);
        $rescatistaId = (int) $sesion['id'];

        $id = ParametroRuta::entero($parametros, 'id');
        $resultado = $id === null ? 'NO_EXISTE' : self::repositorio()->darDeBaja($id, $rescatistaId, date('Y-m-d H:i:s'));

        if ($resultado === 'NO_EXISTE') {
            Response::error('Publicación no encontrada', 404);
            return;
        }

        if ($resultado === 'INACTIVA') {
            Response::error('La publicación ya no está activa', 409);
            return;
        }

        Logger::info("Adopciones: el rescatista $rescatistaId dio de baja la publicación $id");
        http_response_code(204);
    }

    private static function respuestaPublicacion(AdopcionRepositorio $repositorio, int $id, string $evento): void
    {
        Logger::info("Adopciones: $evento");
        Response::json(['publicacion' => AdopcionRepositorio::formatoPublicacion($repositorio->publicacion($id), true)]);
    }

    // GET /api/adopciones/mis-publicaciones — rescatista: todas las suyas.
    public static function mias(): void
    {
        $sesion = Auth::requerirRol(['RESCATISTA']);
        self::responderLista(self::repositorio()->deRescatista((int) $sesion['id']), true);
    }

    // GET /api/adopciones/moderacion — veterinaria: las pendientes, las más viejas primero.
    public static function porModerar(): void
    {
        Auth::requerirRol(['VETERINARIO']);
        self::responderLista(self::repositorio()->porModerar(), true);
    }

    // POST /api/adopciones/publicaciones/{id}/aprobar
    public static function aprobar(array $parametros): void
    {
        self::moderar($parametros, false);
    }

    // POST /api/adopciones/publicaciones/{id}/rechazar — {"motivo": "..."}
    public static function rechazar(array $parametros): void
    {
        self::moderar($parametros, true);
    }

    private static function moderar(array $parametros, bool $rechazar): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);

        $id = ParametroRuta::entero($parametros, 'id');
        $motivo = null;

        if ($rechazar) {
            [$datos, $errores] = ValidadorAdopciones::motivo(Request::json());
            if ($errores !== []) {
                Response::error('Revisá el motivo del rechazo', 400, $errores);
                return;
            }
            $motivo = $datos['motivo'];
        }

        $repositorio = self::repositorio();
        $resultado = $id === null ? 'NO_EXISTE' : $repositorio->moderar($id, $motivo, date('Y-m-d H:i:s'));

        match ($resultado) {
            'NO_EXISTE' => Response::error('Publicación no encontrada', 404),
            'NO_PENDIENTE' => Response::error('La publicación ya fue moderada o se dio de baja', 409),
            'MASCOTA_NO_DISPONIBLE' => Response::error('La mascota ya no está a cargo del rescatista', 409),
            default => self::moderada($repositorio, $id, $rechazar, (int) $sesion['id']),
        };
    }

    private static function moderada(AdopcionRepositorio $repositorio, int $id, bool $rechazar, int $veterinarioId): void
    {
        Logger::info('Adopciones: la veterinaria ' . $veterinarioId . ($rechazar ? ' rechazó' : ' aprobó') . " la publicación $id");
        $publicacion = $repositorio->publicacion($id);

        // Aviso a la rescatista: aprobada o rechazada con el motivo. No corta la respuesta.
        AvisosAdopcion::enviar(AvisosAdopcion::publicacionModerada(
            $id,
            (int) $publicacion['rescatista_id'],
            (string) $publicacion['mascota_nombre'],
            $rechazar ? (string) $publicacion['motivo_rechazo'] : null,
            date('YmdHis')
        ));

        Response::json(['publicacion' => AdopcionRepositorio::formatoPublicacion($publicacion, true)]);
    }

    // [fila, con conteo de postulaciones] si quien pide puede ver la publicación; si no, null.
    private static function visible(array $parametros): ?array
    {
        $id = ParametroRuta::entero($parametros, 'id');
        $fila = $id === null ? null : self::repositorio()->publicacion($id);

        if ($fila === null) {
            return null;
        }

        $sesion = Auth::usuarioActual();
        $rol = $sesion['rol'] ?? null;

        if ($rol === 'VETERINARIO' || ($rol === 'RESCATISTA' && (int) $sesion['id'] === (int) $fila['rescatista_id'])) {
            return [$fila, true];
        }

        // El cliente que se postuló la sigue viendo aunque ya no esté disponible (la rescatista eligió
        // adoptante, o la adopción terminó): la abre desde "Mis postulaciones".
        if ($rol === 'CLIENTE' && self::repositorio()->clientePostulado((int) $fila['id'], (int) $sesion['id'])) {
            return [$fila, false];
        }

        return AdopcionRepositorio::disponible($fila) ? [$fila, false] : null;
    }

    private static function responderLista(array $filas, bool $conConteo): void
    {
        Response::json([
            'publicaciones' => array_map(
                fn (array $fila): array => AdopcionRepositorio::formatoPublicacion($fila, $conConteo),
                $filas
            ),
        ]);
    }

    private static function repositorio(): AdopcionRepositorio
    {
        return new AdopcionRepositorio(Database::conectar());
    }
}

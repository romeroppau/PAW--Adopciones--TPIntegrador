<?php

// Acceso a las tablas publicacion, adopcion y postulacion (migración 007). De las tablas de los demás
// módulos solo lee: usuario (rescatista y adoptante), mascota (que esté a cargo del rescatista) y turno
// (el chequeo). La mascota pasa al adoptante por la llamada interna a Pacientes, nunca desde acá.
class AdopcionRepositorio
{
    // Columnas de la publicación, con su mascota, su rescatista y su adopción (si ya se aprobó).
    private const COLUMNAS_PUBLICACION = "
        p.id, p.rescatista_id, p.titulo, p.descripcion, p.estado_moderacion, p.motivo_rechazo,
        p.activa, p.creada_en,
        m.id AS mascota_id, m.nombre AS mascota_nombre, m.especie, m.raza, m.peso_kg,
        m.activo AS mascota_activa, m.duenio_id,
        TIMESTAMPDIFF(MONTH, m.fecha_nacimiento, CURDATE()) AS edad_meses,
        r.nombre AS rescatista_nombre, r.organizacion,
        a.id AS adopcion_id, a.estado AS adopcion_estado,
        (SELECT COUNT(*) FROM postulacion po
          WHERE po.adopcion_id = a.id AND po.estado = 'PENDIENTE') AS postulaciones_pendientes";

    private const DESDE_PUBLICACION = "
        FROM publicacion p
        JOIN mascota m ON m.id = p.mascota_id
        JOIN usuario r ON r.id = p.rescatista_id
        LEFT JOIN adopcion a ON a.publicacion_id = p.id";

    // "Animales disponibles": lo que ve el público.
    private const DISPONIBLE = "
        p.estado_moderacion = 'APROBADA' AND p.activa = 1 AND m.activo = 1
        AND a.estado IN ('PUBLICADA', 'CON_POSTULANTES')";

    private const COLUMNAS_ADOPCION = "
        a.fecha_inicio, a.fecha_fin, a.adoptante_id, a.turno_chequeo_id,
        ad.nombre AS adoptante_nombre, ad.telefono AS adoptante_telefono,
        t.fecha AS turno_fecha, t.hora AS turno_hora, t.estado AS turno_estado";

    private const DESDE_ADOPCION = "
        FROM adopcion a
        JOIN publicacion p ON p.id = a.publicacion_id
        JOIN mascota m ON m.id = p.mascota_id
        JOIN usuario r ON r.id = p.rescatista_id
        LEFT JOIN usuario ad ON ad.id = a.adoptante_id
        LEFT JOIN turno t ON t.id = a.turno_chequeo_id";

    private const SELECT_POSTULACION = "
        SELECT po.id, po.estado, po.fecha, po.tipo_vivienda, po.otras_mascotas, po.tiempo_disponible,
               po.cliente_id, c.nombre AS cliente_nombre, c.telefono AS cliente_telefono,
               a.id AS adopcion_id, a.estado AS adopcion_estado,
               t.fecha AS turno_fecha, t.hora AS turno_hora, t.estado AS turno_estado,
               p.id AS publicacion_id, p.titulo, m.id AS mascota_id, m.nombre AS mascota_nombre
        FROM postulacion po
        JOIN usuario c ON c.id = po.cliente_id
        JOIN adopcion a ON a.id = po.adopcion_id
        LEFT JOIN turno t ON t.id = a.turno_chequeo_id
        JOIN publicacion p ON p.id = a.publicacion_id
        JOIN mascota m ON m.id = p.mascota_id";

    public function __construct(private PDO $db)
    {
    }

    // ---------- Publicaciones ----------

    public function disponibles(?string $especie): array
    {
        $sql = 'SELECT ' . self::COLUMNAS_PUBLICACION . self::DESDE_PUBLICACION . ' WHERE ' . self::DISPONIBLE;
        $parametros = [];

        if ($especie !== null) {
            $sql .= ' AND m.especie = ?';
            $parametros[] = $especie;
        }

        return $this->filas($sql . ' ORDER BY p.creada_en DESC, p.id DESC LIMIT 100', $parametros);
    }

    public function deRescatista(int $rescatistaId): array
    {
        return $this->filas(
            'SELECT ' . self::COLUMNAS_PUBLICACION . self::DESDE_PUBLICACION
            . ' WHERE p.rescatista_id = ? ORDER BY p.creada_en DESC, p.id DESC LIMIT 100',
            [$rescatistaId]
        );
    }

    public function porModerar(): array
    {
        return $this->filas(
            'SELECT ' . self::COLUMNAS_PUBLICACION . self::DESDE_PUBLICACION
            . " WHERE p.estado_moderacion = 'PENDIENTE' AND p.activa = 1 AND m.activo = 1"
            . ' ORDER BY p.creada_en, p.id LIMIT 100'
        );
    }

    // Las cuentas de la veterinaria: a quiénes se avisa que hay una publicación por revisar.
    public function veterinarias(): array
    {
        return array_map('intval', array_column(
            $this->filas("SELECT id FROM usuario WHERE rol = 'VETERINARIO' ORDER BY id"),
            'id'
        ));
    }

    public function publicacion(int $id): ?array
    {
        $filas = $this->filas(
            'SELECT ' . self::COLUMNAS_PUBLICACION . self::DESDE_PUBLICACION . ' WHERE p.id = ?',
            [$id]
        );

        return $filas[0] ?? null;
    }

    // Si el cliente se postuló a la adopción de esa publicación (en cualquier estado).
    public function clientePostulado(int $publicacionId, int $clienteId): bool
    {
        $consulta = $this->db->prepare(
            'SELECT 1 FROM postulacion po JOIN adopcion a ON a.id = po.adopcion_id
             WHERE a.publicacion_id = ? AND po.cliente_id = ? LIMIT 1'
        );
        $consulta->execute([$publicacionId, $clienteId]);

        return $consulta->fetchColumn() !== false;
    }

    // Si la publicación está en "Animales disponibles" (para el público).
    public static function disponible(array $fila): bool
    {
        return $fila['estado_moderacion'] === 'APROBADA'
            && (int) $fila['activa'] === 1
            && (int) $fila['mascota_activa'] === 1
            && in_array($fila['adopcion_estado'], ['PUBLICADA', 'CON_POSTULANTES'], true);
    }

    public function foto(int $id): ?array
    {
        $consulta = $this->db->prepare('SELECT foto, foto_tipo FROM publicacion WHERE id = ?');
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : [$fila['foto'], $fila['foto_tipo']];
    }

    // El estado se lee de la base en cada publicación: si la veterinaria lo aprueba, no hace falta
    // volver a iniciar sesión.
    public function rescatistaAprobado(int $id): bool
    {
        $consulta = $this->db->prepare(
            "SELECT 1 FROM usuario WHERE id = ? AND rol = 'RESCATISTA' AND estado_validacion = 'APROBADO'"
        );
        $consulta->execute([$id]);

        return $consulta->fetchColumn() !== false;
    }

    public function mascotaACargo(int $mascotaId, int $rescatistaId): bool
    {
        $consulta = $this->db->prepare('SELECT 1 FROM mascota WHERE id = ? AND duenio_id = ? AND activo = 1');
        $consulta->execute([$mascotaId, $rescatistaId]);

        return $consulta->fetchColumn() !== false;
    }

    // Devuelve el id nuevo, o null si la mascota ya tiene una publicación abierta (índice único).
    public function crearPublicacion(int $rescatistaId, array $datos, string $ahora): ?int
    {
        [$bytes, $tipo] = $datos['foto'];

        $consulta = $this->db->prepare(
            'INSERT INTO publicacion (rescatista_id, mascota_id, titulo, descripcion, foto, foto_tipo, creada_en)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $consulta->bindValue(1, $rescatistaId, PDO::PARAM_INT);
        $consulta->bindValue(2, $datos['mascota_id'], PDO::PARAM_INT);
        $consulta->bindValue(3, $datos['titulo']);
        $consulta->bindValue(4, $datos['descripcion']);
        $consulta->bindValue(5, $bytes, PDO::PARAM_LOB);
        $consulta->bindValue(6, $tipo);
        $consulta->bindValue(7, $ahora);

        try {
            $consulta->execute();
        } catch (PDOException $error) {
            if (self::esDuplicado($error)) {
                return null;
            }
            throw $error;
        }

        return (int) $this->db->lastInsertId();
    }

    // Aprobar (motivo null) o rechazar una publicación PENDIENTE y activa.
    // Al aprobar se crea su adopción en PUBLICADA, en la misma transacción.
    // Devuelve OK, NO_EXISTE, NO_PENDIENTE o MASCOTA_NO_DISPONIBLE.
    public function moderar(int $id, ?string $motivo, string $ahora): string
    {
        return $this->enTransaccion(function () use ($id, $motivo, $ahora): string {
            $publicacion = $this->fila(
                'SELECT estado_moderacion, activa, mascota_id, rescatista_id FROM publicacion WHERE id = ? FOR UPDATE',
                [$id]
            );

            if ($publicacion === null) {
                return 'NO_EXISTE';
            }

            if ($publicacion['estado_moderacion'] !== 'PENDIENTE' || (int) $publicacion['activa'] !== 1) {
                return 'NO_PENDIENTE';
            }

            if ($motivo !== null) {
                $this->ejecutar(
                    "UPDATE publicacion SET estado_moderacion = 'RECHAZADA', motivo_rechazo = ? WHERE id = ?",
                    [$motivo, $id]
                );
                return 'OK';
            }

            if (!$this->mascotaACargo((int) $publicacion['mascota_id'], (int) $publicacion['rescatista_id'])) {
                return 'MASCOTA_NO_DISPONIBLE';
            }

            $this->ejecutar("UPDATE publicacion SET estado_moderacion = 'APROBADA' WHERE id = ?", [$id]);
            $this->ejecutar(
                "INSERT INTO adopcion (publicacion_id, estado, fecha_inicio) VALUES (?, 'PUBLICADA', ?)",
                [$id, $ahora]
            );

            return 'OK';
        });
    }

    // El rescatista edita su publicación activa. Solo cambian los campos que vienen.
    // Una publicación rechazada que se edita vuelve a PENDIENTE (sin motivo); una aprobada sigue aprobada.
    // Devuelve OK, NO_EXISTE, INACTIVA u OTRA_ABIERTA (la mascota ya se volvió a publicar).
    public function editarPublicacion(int $id, int $rescatistaId, array $datos): string
    {
        return $this->enTransaccion(function () use ($id, $rescatistaId, $datos): string {
            $publicacion = $this->fila(
                'SELECT estado_moderacion, activa, rescatista_id FROM publicacion WHERE id = ? FOR UPDATE',
                [$id]
            );

            if ($publicacion === null || (int) $publicacion['rescatista_id'] !== $rescatistaId) {
                return 'NO_EXISTE';
            }

            if ((int) $publicacion['activa'] !== 1) {
                return 'INACTIVA';
            }

            $columnas = [];
            $valores = [];

            foreach (['titulo', 'descripcion'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $columnas[] = "$campo = ?";
                    $valores[] = [$datos[$campo], PDO::PARAM_STR];
                }
            }

            if (array_key_exists('foto', $datos)) {
                $columnas[] = 'foto = ?';
                $valores[] = [$datos['foto'][0], PDO::PARAM_LOB];
                $columnas[] = 'foto_tipo = ?';
                $valores[] = [$datos['foto'][1], PDO::PARAM_STR];
            }

            if ($publicacion['estado_moderacion'] === 'RECHAZADA') {
                $columnas[] = "estado_moderacion = 'PENDIENTE'";
                $columnas[] = 'motivo_rechazo = NULL';
            }

            $consulta = $this->db->prepare('UPDATE publicacion SET ' . implode(', ', $columnas) . ' WHERE id = ?');
            foreach ($valores as $indice => [$valor, $tipo]) {
                $consulta->bindValue($indice + 1, $valor, $tipo);
            }
            $consulta->bindValue(count($valores) + 1, $id, PDO::PARAM_INT);

            try {
                $consulta->execute();
            } catch (PDOException $error) {
                // Volver a PENDIENTE la reabre: si la mascota ya tiene otra publicación abierta, no se puede.
                // MySQL deshace solo esa sentencia (que era la única), así que confirmar no cambia nada.
                if (self::esDuplicado($error)) {
                    return 'OTRA_ABIERTA';
                }
                throw $error;
            }

            return 'OK';
        });
    }

    // El rescatista da de baja su publicación (baja lógica). Si tenía una adopción en curso, se cancela
    // igual que con "Cancelar". Devuelve OK, NO_EXISTE o INACTIVA.
    public function darDeBaja(int $id, int $rescatistaId, string $ahora): string
    {
        return $this->enTransaccion(function () use ($id, $rescatistaId, $ahora): string {
            $publicacion = $this->fila('SELECT activa, rescatista_id FROM publicacion WHERE id = ? FOR UPDATE', [$id]);

            if ($publicacion === null || (int) $publicacion['rescatista_id'] !== $rescatistaId) {
                return 'NO_EXISTE';
            }

            if ((int) $publicacion['activa'] !== 1) {
                return 'INACTIVA';
            }

            $adopcion = $this->fila('SELECT id, estado FROM adopcion WHERE publicacion_id = ? FOR UPDATE', [$id]);

            if ($adopcion !== null && !in_array($adopcion['estado'], ['FINALIZADA', 'CANCELADA'], true)) {
                $this->cerrarCancelada((int) $adopcion['id'], $id, $ahora);
            } else {
                $this->ejecutar('UPDATE publicacion SET activa = 0 WHERE id = ?', [$id]);
            }

            return 'OK';
        });
    }

    // ---------- Adopciones ----------

    public function adopcion(int $id): ?array
    {
        $filas = $this->filas(
            'SELECT ' . self::COLUMNAS_PUBLICACION . ', ' . self::COLUMNAS_ADOPCION . self::DESDE_ADOPCION
            . ' WHERE a.id = ?',
            [$id]
        );

        return $filas[0] ?? null;
    }

    // La adopción con lo que muestra su detalle: los contactos del rescatista y del adoptante y la
    // consulta del chequeo (para enlazarla).
    public function detalle(int $id): ?array
    {
        return $this->fila(
            'SELECT ' . self::COLUMNAS_PUBLICACION . ', ' . self::COLUMNAS_ADOPCION . ',
                    r.email AS rescatista_email, r.telefono AS rescatista_telefono,
                    ad.email AS adoptante_email, c.id AS consulta_id'
            . self::DESDE_ADOPCION
            . ' LEFT JOIN consulta c ON c.turno_id = t.id WHERE a.id = ?',
            [$id]
        );
    }

    // Una página del listado de la veterinaria y el total. Devuelve [filas, total].
    // Sin estado: las que no terminaron, las más viejas primero (las que esperan hace más).
    // FINALIZADA o CANCELADA: las más recientes primero.
    // Filtros (ya validados): desde y hasta (Y-m-d, sobre el día en que terminó o, si no terminó, en
    // que empezó) y buscar (nombre de la mascota, del adoptante o del rescatista).
    public function adopciones(?string $estado, array $filtros, int $pagina, int $porPagina): array
    {
        $terminadas = in_array($estado, ['FINALIZADA', 'CANCELADA'], true);
        $columnaFecha = $terminadas ? 'a.fecha_fin' : 'a.fecha_inicio';
        $condiciones = [];
        $parametros = [];

        if ($estado === null) {
            $condiciones[] = "a.estado NOT IN ('FINALIZADA', 'CANCELADA')";
        } else {
            $condiciones[] = 'a.estado = ?';
            $parametros[] = $estado;
        }
        if (isset($filtros['desde'])) {
            $condiciones[] = "$columnaFecha >= ?";
            $parametros[] = $filtros['desde'];
        }
        if (isset($filtros['hasta'])) {
            // Hasta el final de ese día, sin envolver la columna en DATE() (así usa el índice).
            $condiciones[] = "$columnaFecha < ? + INTERVAL 1 DAY";
            $parametros[] = $filtros['hasta'];
        }
        if (isset($filtros['buscar'])) {
            $condiciones[] = '(m.nombre LIKE ? OR ad.nombre LIKE ? OR r.nombre LIKE ?)';
            $patron = '%' . addcslashes($filtros['buscar'], '%_\\') . '%';
            array_push($parametros, $patron, $patron, $patron);
        }

        $donde = ' WHERE ' . implode(' AND ', $condiciones);
        $orden = self::ordenAdopciones($filtros['orden'] ?? null, $terminadas, $columnaFecha);
        $desplazamiento = ($pagina - 1) * $porPagina;

        $total = (int) $this->fila('SELECT COUNT(*) AS total' . self::DESDE_ADOPCION . $donde, $parametros)['total'];
        $filas = $this->filas(
            'SELECT ' . self::COLUMNAS_PUBLICACION . ', ' . self::COLUMNAS_ADOPCION . self::DESDE_ADOPCION
            . $donde . $orden . " LIMIT $porPagina OFFSET $desplazamiento",
            $parametros
        );

        return [$filas, $total];
    }

    // Orden del listado de la veterinaria:
    //   prioridad: lo que necesita a la veterinaria primero: chequeos con turno (el más próximo
    //              primero), después adoptante elegido esperando turno, con postulantes y publicadas;
    //   recientes: las más nuevas primero (por el día en que empezó o, si terminó, en que terminó);
    //   antiguas:  las que esperan hace más primero.
    // Sin orden: las que no terminaron, antiguas; las terminadas, recientes (como antes).
    private static function ordenAdopciones(?string $orden, bool $terminadas, string $columnaFecha): string
    {
        $orden ??= $terminadas ? 'recientes' : 'antiguas';

        return match ($orden) {
            'prioridad' => " ORDER BY CASE a.estado WHEN 'CHEQUEO_PENDIENTE' THEN 0 WHEN 'ADOPTANTE_ELEGIDO' THEN 1"
                . " WHEN 'CON_POSTULANTES' THEN 2 WHEN 'PUBLICADA' THEN 3 ELSE 4 END,"
                . " t.fecha IS NULL, t.fecha, t.hora, a.fecha_inicio, a.id",
            'recientes' => " ORDER BY $columnaFecha DESC, a.id DESC",
            default => " ORDER BY $columnaFecha, a.id",
        };
    }

    // Devuelve [resultado, id]: OK con el id de la postulación, NO_EXISTE, ESTADO o YA_POSTULADO.
    public function postularse(int $adopcionId, int $clienteId, array $datos, string $ahora): array
    {
        return $this->enTransaccion(function () use ($adopcionId, $clienteId, $datos, $ahora): array {
            $adopcion = $this->fila('SELECT estado, publicacion_id FROM adopcion WHERE id = ? FOR UPDATE', [$adopcionId]);
            $publicacion = $adopcion === null ? null : $this->publicacion((int) $adopcion['publicacion_id']);

            if (
                $publicacion === null || $publicacion['estado_moderacion'] !== 'APROBADA'
                || (int) $publicacion['activa'] !== 1 || (int) $publicacion['mascota_activa'] !== 1
            ) {
                return ['NO_EXISTE', null];
            }

            if (!in_array($adopcion['estado'], ['PUBLICADA', 'CON_POSTULANTES'], true)) {
                return ['ESTADO', null];
            }

            try {
                $this->ejecutar(
                    'INSERT INTO postulacion (adopcion_id, cliente_id, tipo_vivienda, otras_mascotas, tiempo_disponible, fecha)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$adopcionId, $clienteId, $datos['tipo_vivienda'], $datos['otras_mascotas'], $datos['tiempo_disponible'], $ahora]
                );
            } catch (PDOException $error) {
                if (self::esDuplicado($error)) {
                    return ['YA_POSTULADO', null];
                }
                throw $error;
            }

            $id = (int) $this->db->lastInsertId();

            if ($adopcion['estado'] === 'PUBLICADA') {
                $this->ejecutar("UPDATE adopcion SET estado = 'CON_POSTULANTES' WHERE id = ?", [$adopcionId]);
            }

            return ['OK', $id];
        });
    }

    public function postulacion(int $id): ?array
    {
        $filas = $this->filas(self::SELECT_POSTULACION . ' WHERE po.id = ?', [$id]);

        return $filas[0] ?? null;
    }

    public function postulacionesDeCliente(int $clienteId): array
    {
        return $this->filas(
            self::SELECT_POSTULACION . ' WHERE po.cliente_id = ? ORDER BY po.fecha DESC, po.id DESC LIMIT 100',
            [$clienteId]
        );
    }

    public function postulacionesDeAdopcion(int $adopcionId): array
    {
        return $this->filas(
            self::SELECT_POSTULACION . ' WHERE po.adopcion_id = ? ORDER BY po.fecha, po.id LIMIT 100',
            [$adopcionId]
        );
    }

    // La rescatista elige al adoptante: esa postulación ELEGIDA, las demás RECHAZADA y la adopción
    // ADOPTANTE_ELEGIDO, todo junto.
    // Devuelve OK, NO_EXISTE, NO_EXISTE_POSTULACION, ESTADO o POSTULACION_NO_PENDIENTE.
    public function elegir(int $adopcionId, int $rescatistaId, int $postulacionId): string
    {
        return $this->enTransaccion(function () use ($adopcionId, $rescatistaId, $postulacionId): string {
            $adopcion = $this->fila(
                'SELECT a.estado, p.rescatista_id FROM adopcion a JOIN publicacion p ON p.id = a.publicacion_id
                 WHERE a.id = ? FOR UPDATE',
                [$adopcionId]
            );

            if ($adopcion === null || (int) $adopcion['rescatista_id'] !== $rescatistaId) {
                return 'NO_EXISTE';
            }

            $postulacion = $this->fila(
                'SELECT estado, cliente_id FROM postulacion WHERE id = ? AND adopcion_id = ? FOR UPDATE',
                [$postulacionId, $adopcionId]
            );

            if ($postulacion === null) {
                return 'NO_EXISTE_POSTULACION';
            }

            if ($adopcion['estado'] !== 'CON_POSTULANTES') {
                return 'ESTADO';
            }

            if ($postulacion['estado'] !== 'PENDIENTE') {
                return 'POSTULACION_NO_PENDIENTE';
            }

            $this->ejecutar(
                "UPDATE postulacion SET estado = IF(id = ?, 'ELEGIDA', 'RECHAZADA')
                 WHERE adopcion_id = ? AND estado = 'PENDIENTE'",
                [$postulacionId, $adopcionId]
            );
            $this->ejecutar(
                "UPDATE adopcion SET estado = 'ADOPTANTE_ELEGIDO', adoptante_id = ? WHERE id = ?",
                [(int) $postulacion['cliente_id'], $adopcionId]
            );

            return 'OK';
        });
    }

    // La veterinaria asocia el turno de chequeo (ya cargado en la agenda de Pacientes).
    // Devuelve OK, NO_EXISTE, ESTADO, o un mensaje que empieza con "TURNO:" si el turno no sirve.
    public function asociarChequeo(int $adopcionId, int $turnoId): string
    {
        return $this->enTransaccion(function () use ($adopcionId, $turnoId): string {
            $adopcion = $this->fila(
                'SELECT a.estado, a.turno_chequeo_id, p.mascota_id FROM adopcion a
                 JOIN publicacion p ON p.id = a.publicacion_id WHERE a.id = ? FOR UPDATE',
                [$adopcionId]
            );

            if ($adopcion === null) {
                return 'NO_EXISTE';
            }

            $permitido = $adopcion['estado'] === 'ADOPTANTE_ELEGIDO';

            // Reprogramar: el chequeo anterior se canceló o el adoptante no fue.
            if ($adopcion['estado'] === 'CHEQUEO_PENDIENTE') {
                $anterior = $this->fila('SELECT estado FROM turno WHERE id = ?', [(int) $adopcion['turno_chequeo_id']]);
                $permitido = $anterior !== null && in_array($anterior['estado'], ['CANCELADO', 'AUSENTE'], true);
            }

            if (!$permitido) {
                return 'ESTADO';
            }

            $turno = $this->fila('SELECT mascota_id, motivo, estado FROM turno WHERE id = ?', [$turnoId]);

            if ($turno === null) {
                return 'TURNO:El turno no existe';
            }
            if ($turno['motivo'] !== 'CHEQUEO_ADOPCION') {
                return 'TURNO:El turno no es un chequeo de adopción';
            }
            if ((int) $turno['mascota_id'] !== (int) $adopcion['mascota_id']) {
                return 'TURNO:El turno no es de la mascota de la publicación';
            }
            if ($turno['estado'] !== 'PROGRAMADO') {
                return 'TURNO:El turno no está programado';
            }
            if ($this->fila('SELECT id FROM adopcion WHERE turno_chequeo_id = ? AND id <> ?', [$turnoId, $adopcionId]) !== null) {
                return 'TURNO:El turno ya es el chequeo de otra adopción';
            }

            $this->ejecutar(
                "UPDATE adopcion SET estado = 'CHEQUEO_PENDIENTE', turno_chequeo_id = ? WHERE id = ?",
                [$turnoId, $adopcionId]
            );

            return 'OK';
        });
    }

    // Después de que Pacientes pasó la mascota: la adopción FINALIZADA y la publicación cerrada.
    // Si otro pedido ya la finalizó, no hace nada.
    public function finalizar(int $adopcionId, string $ahora): void
    {
        $this->enTransaccion(function () use ($adopcionId, $ahora): void {
            $adopcion = $this->fila('SELECT estado, publicacion_id FROM adopcion WHERE id = ? FOR UPDATE', [$adopcionId]);

            if ($adopcion === null || $adopcion['estado'] !== 'CHEQUEO_PENDIENTE') {
                return;
            }

            $this->ejecutar("UPDATE adopcion SET estado = 'FINALIZADA', fecha_fin = ? WHERE id = ?", [$ahora, $adopcionId]);
            $this->ejecutar('UPDATE publicacion SET activa = 0 WHERE id = ?', [(int) $adopcion['publicacion_id']]);
        });
    }

    // Cancela la adopción (el rescatista dueño o la veterinaria: $rescatistaId null).
    // Devuelve OK, NO_EXISTE o ESTADO.
    public function cancelar(int $adopcionId, ?int $rescatistaId, string $ahora): string
    {
        return $this->enTransaccion(function () use ($adopcionId, $rescatistaId, $ahora): string {
            $adopcion = $this->fila(
                'SELECT a.estado, a.publicacion_id, p.rescatista_id FROM adopcion a
                 JOIN publicacion p ON p.id = a.publicacion_id WHERE a.id = ? FOR UPDATE',
                [$adopcionId]
            );

            if ($adopcion === null || ($rescatistaId !== null && (int) $adopcion['rescatista_id'] !== $rescatistaId)) {
                return 'NO_EXISTE';
            }

            if (in_array($adopcion['estado'], ['FINALIZADA', 'CANCELADA'], true)) {
                return 'ESTADO';
            }

            $this->cerrarCancelada($adopcionId, (int) $adopcion['publicacion_id'], $ahora);

            return 'OK';
        });
    }

    // La adopción CANCELADA, la publicación cerrada y las postulaciones abiertas rechazadas
    // (dentro de la transacción de quien la llama).
    private function cerrarCancelada(int $adopcionId, int $publicacionId, string $ahora): void
    {
        $this->ejecutar("UPDATE adopcion SET estado = 'CANCELADA', fecha_fin = ? WHERE id = ?", [$ahora, $adopcionId]);
        $this->ejecutar('UPDATE publicacion SET activa = 0 WHERE id = ?', [$publicacionId]);
        $this->ejecutar(
            "UPDATE postulacion SET estado = 'RECHAZADA' WHERE adopcion_id = ? AND estado IN ('PENDIENTE', 'ELEGIDA')",
            [$adopcionId]
        );
    }

    // ---------- Formato de las respuestas (contrato-adopciones.md) ----------

    public static function formatoPublicacion(array $f, bool $conConteo): array
    {
        $adopcion = null;

        if ($f['adopcion_id'] !== null) {
            $adopcion = ['id' => (int) $f['adopcion_id'], 'estado' => $f['adopcion_estado']];
            if ($conConteo) {
                $adopcion['postulaciones_pendientes'] = (int) $f['postulaciones_pendientes'];
            }
        }

        return [
            'id' => (int) $f['id'],
            'titulo' => $f['titulo'],
            'descripcion' => $f['descripcion'],
            'estado_moderacion' => $f['estado_moderacion'],
            'motivo_rechazo' => $f['motivo_rechazo'],
            'activa' => (int) $f['activa'] === 1,
            'creada_en' => $f['creada_en'],
            'mascota' => [
                'id' => (int) $f['mascota_id'],
                'nombre' => $f['mascota_nombre'],
                'especie' => $f['especie'],
                'raza' => $f['raza'],
                'edad_meses' => $f['edad_meses'] === null ? null : (int) $f['edad_meses'],
                'peso_kg' => $f['peso_kg'] === null ? null : (string) $f['peso_kg'],
            ],
            'rescatista' => [
                'id' => (int) $f['rescatista_id'],
                'nombre' => $f['rescatista_nombre'],
                'organizacion' => $f['organizacion'],
            ],
            'adopcion' => $adopcion,
        ];
    }

    public static function formatoAdopcion(array $f): array
    {
        return [
            'id' => (int) $f['adopcion_id'],
            'estado' => $f['adopcion_estado'],
            'fecha_inicio' => $f['fecha_inicio'],
            'fecha_fin' => $f['fecha_fin'],
            'publicacion' => self::formatoPublicacion($f, true),
            'adoptante' => $f['adoptante_id'] === null ? null : [
                'id' => (int) $f['adoptante_id'],
                'nombre' => $f['adoptante_nombre'],
                'telefono' => $f['adoptante_telefono'],
            ],
            'turno_chequeo' => $f['turno_chequeo_id'] === null ? null : [
                'id' => (int) $f['turno_chequeo_id'],
                'fecha' => $f['turno_fecha'],
                'hora' => substr((string) $f['turno_hora'], 0, 5),
                'estado' => $f['turno_estado'],
            ],
        ];
    }

    // El detalle (GET /api/adopciones/{id}/detalle): la adopción con los contactos y la consulta del chequeo.
    public static function formatoDetalle(array $f): array
    {
        $adopcion = self::formatoAdopcion($f);
        $adopcion['publicacion']['rescatista']['email'] = $f['rescatista_email'];
        $adopcion['publicacion']['rescatista']['telefono'] = $f['rescatista_telefono'];
        if ($adopcion['adoptante'] !== null) {
            $adopcion['adoptante']['email'] = $f['adoptante_email'];
        }
        if ($adopcion['turno_chequeo'] !== null) {
            $adopcion['turno_chequeo']['consulta_id'] = $f['consulta_id'] === null ? null : (int) $f['consulta_id'];
        }

        return $adopcion;
    }

    // El teléfono del cliente solo se muestra cuando la postulación está ELEGIDA.
    public static function formatoPostulacion(array $f): array
    {
        return [
            'id' => (int) $f['id'],
            'estado' => $f['estado'],
            'fecha' => $f['fecha'],
            'tipo_vivienda' => $f['tipo_vivienda'],
            'otras_mascotas' => $f['otras_mascotas'],
            'tiempo_disponible' => $f['tiempo_disponible'],
            'cliente' => [
                'id' => (int) $f['cliente_id'],
                'nombre' => $f['cliente_nombre'],
                'telefono' => $f['estado'] === 'ELEGIDA' ? $f['cliente_telefono'] : null,
            ],
            'adopcion' => [
                'id' => (int) $f['adopcion_id'],
                'estado' => $f['adopcion_estado'],
                // El turno del chequeo, solo para la postulación elegida (es la del adoptante).
                'turno_chequeo' => $f['estado'] === 'ELEGIDA' && ($f['turno_fecha'] ?? null) !== null ? [
                    'fecha' => $f['turno_fecha'],
                    'hora' => substr((string) $f['turno_hora'], 0, 5),
                    'estado' => $f['turno_estado'],
                ] : null,
            ],
            'publicacion' => [
                'id' => (int) $f['publicacion_id'],
                'titulo' => $f['titulo'],
                'mascota' => ['id' => (int) $f['mascota_id'], 'nombre' => $f['mascota_nombre']],
            ],
        ];
    }

    // ---------- Ayudas ----------

    private function filas(string $sql, array $parametros = []): array
    {
        $consulta = $this->db->prepare($sql);
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    }

    private function fila(string $sql, array $parametros): ?array
    {
        $consulta = $this->db->prepare($sql);
        $consulta->execute($parametros);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    private function ejecutar(string $sql, array $parametros): void
    {
        $this->db->prepare($sql)->execute($parametros);
    }

    private function enTransaccion(callable $trabajo): mixed
    {
        $this->db->beginTransaction();

        try {
            $resultado = $trabajo();
            $this->db->commit();
            return $resultado;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private static function esDuplicado(PDOException $error): bool
    {
        return ($error->errorInfo[1] ?? null) === 1062;
    }
}

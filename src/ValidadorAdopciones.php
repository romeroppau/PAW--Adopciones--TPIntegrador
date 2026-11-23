<?php

// Validación de los cuerpos del circuito de adopción (contrato-adopciones.md, secciones 3 a 6).
// Cada método devuelve [datos limpios, errores por campo]. Si hay errores, los datos no se usan.
class ValidadorAdopciones
{
    // POST /api/adopciones/publicaciones
    public static function publicacion(array $datos): array
    {
        $errores = [];
        $limpios = [];

        if (!self::esId($datos['mascota_id'] ?? null)) {
            $errores['mascota_id'] = 'Elegí una mascota que tengas a cargo';
        } else {
            $limpios['mascota_id'] = $datos['mascota_id'];
        }

        self::texto($datos, 'titulo', 3, 120, 'El título', $limpios, $errores);
        self::texto($datos, 'descripcion', 10, 2000, 'La descripción', $limpios, $errores);

        if (!isset($datos['foto']) || $datos['foto'] === '') {
            $errores['foto'] = 'La foto es obligatoria';
        } else {
            $foto = Imagen::desdeDataUrl($datos['foto']);
            if (is_string($foto)) {
                $errores['foto'] = $foto;
            } else {
                $limpios['foto'] = $foto; // [bytes, tipo]
            }
        }

        return [$limpios, $errores];
    }

    // PUT /api/adopciones/publicaciones/{id}: los mismos campos que al publicar menos mascota_id, todos
    // opcionales (lo que no viene no cambia). Tiene que venir al menos uno.
    public static function edicion(array $datos): array
    {
        $errores = [];
        $limpios = [];

        if (array_key_exists('titulo', $datos)) {
            self::texto($datos, 'titulo', 3, 120, 'El título', $limpios, $errores);
        }
        if (array_key_exists('descripcion', $datos)) {
            self::texto($datos, 'descripcion', 10, 2000, 'La descripción', $limpios, $errores);
        }
        if (array_key_exists('foto', $datos) && $datos['foto'] !== null && $datos['foto'] !== '') {
            $foto = Imagen::desdeDataUrl($datos['foto']);
            if (is_string($foto)) {
                $errores['foto'] = $foto;
            } else {
                $limpios['foto'] = $foto;
            }
        }

        if ($errores === [] && $limpios === []) {
            $errores['titulo'] = 'Cambiá al menos el título, la descripción o la foto';
        }

        return [$limpios, $errores];
    }

    // POST /api/adopciones/publicaciones/{id}/rechazar
    public static function motivo(array $datos): array
    {
        $errores = [];
        $limpios = [];
        self::texto($datos, 'motivo', 3, 255, 'El motivo', $limpios, $errores);

        return [$limpios, $errores];
    }

    // POST /api/adopciones/{id}/postulaciones
    public static function postulacion(array $datos): array
    {
        $errores = [];
        $limpios = [];
        self::texto($datos, 'tipo_vivienda', 2, 60, 'El tipo de vivienda', $limpios, $errores);
        self::texto($datos, 'otras_mascotas', 2, 255, 'Lo de otras mascotas', $limpios, $errores);
        self::texto($datos, 'tiempo_disponible', 2, 120, 'El tiempo disponible', $limpios, $errores);

        return [$limpios, $errores];
    }

    public const ESTADOS_ADOPCION = ['PUBLICADA', 'CON_POSTULANTES', 'ADOPTANTE_ELEGIDO', 'CHEQUEO_PENDIENTE', 'FINALIZADA', 'CANCELADA'];
    public const POR_PAGINA_MAXIMO = 100;

    // GET /api/adopciones?estado=&desde=&hasta=&buscar=&pagina=&por_pagina= (todos opcionales).
    // Devuelve [datos limpios, errores]. Los datos: estado (o null), filtros (desde, hasta, buscar),
    // pagina (desde 1) y por_pagina (hasta 100; si no viene, 100, como antes de paginar).
    public static function listado(array $query): array
    {
        $errores = [];
        $filtros = [];
        $estado = $query['estado'] ?? null;

        if ($estado !== null && !in_array($estado, self::ESTADOS_ADOPCION, true)) {
            $errores['estado'] = 'No es un estado de adopción';
        }

        foreach (['desde', 'hasta'] as $campo) {
            if (!isset($query[$campo]) || $query[$campo] === '') {
                continue;
            }
            if (self::esFecha($query[$campo])) {
                $filtros[$campo] = $query[$campo];
            } else {
                $errores[$campo] = 'La fecha tiene que tener el formato AAAA-MM-DD';
            }
        }
        if (isset($filtros['desde'], $filtros['hasta']) && $filtros['desde'] > $filtros['hasta']) {
            $errores['hasta'] = 'La fecha "hasta" no puede ser anterior a "desde"';
        }

        $buscar = $query['buscar'] ?? '';
        if (!is_string($buscar)) {
            $errores['buscar'] = 'La búsqueda tiene que ser un texto';
        } elseif (trim($buscar) !== '') {
            if (mb_strlen(trim($buscar)) > 60) {
                $errores['buscar'] = 'La búsqueda puede tener hasta 60 caracteres';
            } else {
                $filtros['buscar'] = trim($buscar);
            }
        }

        $orden = $query['orden'] ?? '';
        if ($orden !== '' && $orden !== null) {
            if (in_array($orden, ['prioridad', 'recientes', 'antiguas'], true)) {
                $filtros['orden'] = $orden;
            } else {
                $errores['orden'] = 'El orden puede ser prioridad, recientes o antiguas';
            }
        }

        $pagina = self::enteroDeQuery($query, 'pagina', 1, 100000, 1, $errores);
        $porPagina = self::enteroDeQuery($query, 'por_pagina', 1, self::POR_PAGINA_MAXIMO, self::POR_PAGINA_MAXIMO, $errores);

        return [
            ['estado' => $estado, 'filtros' => $filtros, 'pagina' => $pagina, 'por_pagina' => $porPagina],
            $errores,
        ];
    }

    // {"postulacion_id": 101} o {"turno_id": 120}: un id entero de verdad, o null.
    public static function id(array $datos, string $campo): ?int
    {
        $valor = $datos[$campo] ?? null;

        return self::esId($valor) ? $valor : null;
    }

    private static function texto(
        array $datos,
        string $campo,
        int $minimo,
        int $maximo,
        string $nombre,
        array &$limpios,
        array &$errores
    ): void {
        if (!Validator::esTexto($datos, $campo) || trim($datos[$campo]) === '') {
            $errores[$campo] = "$nombre es obligatorio";
            return;
        }

        $valor = trim($datos[$campo]);

        if (!Validator::longitudMinima($valor, $minimo)) {
            $errores[$campo] = "$nombre tiene que tener al menos $minimo caracteres";
        } elseif (!Validator::longitudMaxima($valor, $maximo)) {
            $errores[$campo] = "$nombre puede tener hasta $maximo caracteres";
        } else {
            $limpios[$campo] = $valor;
        }
    }

    // Una fecha real con el formato AAAA-MM-DD (rechaza, por ejemplo, 2026-02-30).
    private static function esFecha(mixed $valor): bool
    {
        if (!is_string($valor) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes)) {
            return false;
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]);
    }

    // Un entero de la query string entre $minimo y $maximo. Si no viene, $porDefecto.
    private static function enteroDeQuery(array $query, string $campo, int $minimo, int $maximo, int $porDefecto, array &$errores): int
    {
        $valor = $query[$campo] ?? null;

        if ($valor === null || $valor === '') {
            return $porDefecto;
        }
        if (!is_string($valor) || !ctype_digit($valor) || (int) $valor < $minimo || (int) $valor > $maximo) {
            $errores[$campo] = "Tiene que ser un número entre $minimo y $maximo";
            return $porDefecto;
        }

        return (int) $valor;
    }

    private static function esId(mixed $valor): bool
    {
        return is_int($valor) && $valor > 0 && $valor <= 4294967295; // INT UNSIGNED
    }
}

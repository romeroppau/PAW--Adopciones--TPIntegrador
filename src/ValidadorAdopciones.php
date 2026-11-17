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

    private static function esId(mixed $valor): bool
    {
        return is_int($valor) && $valor > 0 && $valor <= 4294967295; // INT UNSIGNED
    }
}

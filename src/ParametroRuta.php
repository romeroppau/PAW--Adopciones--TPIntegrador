<?php

// Lectura de los parámetros de la ruta (por ejemplo, el {id} de /turnos/{id}/cancelar).
class ParametroRuta
{
    // Un parámetro de la ruta como entero positivo, o null si no lo es (y entonces se responde 404).
    public static function entero(array $parametros, string $clave): ?int
    {
        $valor = $parametros[$clave] ?? '';

        if (!is_string($valor) || !ctype_digit($valor) || strlen($valor) > 10) {
            return null;
        }

        $valor = (int) $valor;

        return $valor > 0 && $valor <= 4294967295 ? $valor : null; // INT UNSIGNED
    }
}

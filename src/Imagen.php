<?php

// Convierte la foto de una publicación entre los dos formatos que usa el sistema (copia de Imagen.php de
// Pacientes e Inventario: cada módulo es independiente, así que no comparten código fuera de comun/):
// - En el JSON viaja como data URL: "data:image/png;base64,iVBORw0KGgo..." (tipo + contenido en base64).
// - En la base se guardan los bytes (columna foto, MEDIUMBLOB) y el tipo aparte (columna foto_tipo).
class Imagen
{
    public const TAMANIO_MAXIMO = 1024 * 1024; // 1 MB, de la imagen ya decodificada
    public const TIPOS = ['image/png', 'image/jpeg', 'image/webp'];

    public const ERROR_TIPO = 'La imagen debe ser PNG, JPG o WEBP';
    public const ERROR_TAMANIO = 'La imagen no puede superar 1 MB';

    // Recibe un data URL y devuelve [bytes, tipo], o un mensaje de error (string) si no es válido.
    public static function desdeDataUrl(mixed $dataUrl): array|string
    {
        if (!is_string($dataUrl)) {
            return self::ERROR_TIPO;
        }

        // Se mira el tamaño antes de decodificar: base64 ocupa 4 caracteres por cada 3 bytes.
        // Así no se decodifica un texto gigante solo para rechazarlo después.
        $prefijoMaximo = 30; // "data:image/jpeg;base64," y algo de margen
        if (strlen($dataUrl) > intdiv(self::TAMANIO_MAXIMO * 4, 3) + 4 + $prefijoMaximo) {
            return self::ERROR_TAMANIO;
        }

        if (!preg_match('#^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/]+={0,2})$#', $dataUrl, $partes)) {
            return self::ERROR_TIPO;
        }

        [, $tipo, $base64] = $partes;

        $bytes = base64_decode($base64, true); // true: falla si hay caracteres que no son base64
        if ($bytes === false || $bytes === '') {
            return self::ERROR_TIPO;
        }

        if (strlen($bytes) > self::TAMANIO_MAXIMO) {
            return self::ERROR_TAMANIO;
        }

        // No alcanza con que el texto diga "image/png": se revisa el contenido real del archivo.
        $tipoReal = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($tipoReal !== $tipo) {
            return self::ERROR_TIPO;
        }

        return [$bytes, $tipo];
    }

    // Arma el data URL a partir de lo guardado en la base. Sin imagen, devuelve null.
    public static function aDataUrl(?string $bytes, ?string $tipo): ?string
    {
        if ($bytes === null || $bytes === '' || $tipo === null || !in_array($tipo, self::TIPOS, true)) {
            return null;
        }

        return 'data:' . $tipo . ';base64,' . base64_encode($bytes);
    }
}

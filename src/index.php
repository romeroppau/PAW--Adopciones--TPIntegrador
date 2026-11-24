<?php

// Localizar las herramientas compartidas del proyecto.
$comun = getenv('COMUN_DIR') ?: dirname(__DIR__, 2) . '/comun';

// Cargar las clases compartidas.
require_once $comun . '/config.php';
require_once $comun . '/response.php';
require_once $comun . '/request.php';
require_once $comun . '/router.php';
require_once $comun . '/logger.php';
require_once $comun . '/database.php';
require_once $comun . '/auth.php';
require_once $comun . '/validator.php';

spl_autoload_register(function (string $clase): void {
    $archivo = __DIR__ . '/' . $clase . '.php';

    if (is_file($archivo)) {
        require_once $archivo;
    }
});

date_default_timezone_set('America/Argentina/Buenos_Aires');

// Crear el router.
$router = new Router();

// Cargar automáticamente las rutas del módulo.
foreach (glob(__DIR__ . '/rutas/*.php') as $archivoRuta) {
    require_once $archivoRuta;
}

// Ejecutar la ruta solicitada y manejar errores inesperados.
try {
    $router->dispatch(
        Request::method(),
        Request::path()
    );
} catch (Throwable $error) {
    Logger::error(
        'Error inesperado en Adopciones: '
        . get_class($error)
        . ' - '
        . $error->getMessage()
    );

    Response::error(
        'Ocurrió un error interno del servidor',
        500
    );
}

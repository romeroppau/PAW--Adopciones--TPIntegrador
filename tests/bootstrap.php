<?php

// Localizar las herramientas compartidas del proyecto.
$comun = getenv('COMUN_DIR') ?: __DIR__ . '/../../PAW--ApiGateway--TPIntegrador/comun';

if (!is_dir($comun)) {
    fwrite(STDERR, "No se encontró la carpeta comun/ en: $comun\n");
    fwrite(STDERR, "Configurá la variable COMUN_DIR con su ubicación.\n");
    exit(1);
}

// Cargar las clases compartidas necesarias para las pruebas.
require_once $comun . '/config.php';
require_once $comun . '/logger.php';
require_once $comun . '/response.php';
require_once $comun . '/request.php';
require_once $comun . '/router.php';
require_once $comun . '/database.php';
require_once $comun . '/auth.php';
require_once $comun . '/validator.php';

spl_autoload_register(function (string $clase): void {
    $archivo = __DIR__ . '/../src/' . $clase . '.php';

    if (is_file($archivo)) {
        require_once $archivo;
    }
});


<?php

$router->get('/api/adopciones/salud', function (): void {
    try {
        Database::conectar()->query('SELECT 1');
    } catch (Throwable $error) {
        Logger::error(
            'Salud de Adopciones: no se pudo conectar a la base: '
            . $error->getMessage()
        );

        Response::json([
            'estado' => 'sin conexión a la base',
            'modulo' => 'Adopciones'
        ], 503);
        return;
    }

    Response::json([
        'estado' => 'funciona',
        'modulo' => 'Adopciones'
    ], 200);
});

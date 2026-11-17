<?php

// Rutas del circuito de adopción (rol 3). Contrato en docs/api/contrato-adopciones.md.
// index.php carga todos los archivos de src/rutas/.

// Publicaciones y moderación
$router->get('/api/adopciones/publicaciones', [PublicacionControlador::class, 'listar']);
$router->post('/api/adopciones/publicaciones', [PublicacionControlador::class, 'crear']);
$router->get('/api/adopciones/publicaciones/{id}', [PublicacionControlador::class, 'ver']);
$router->put('/api/adopciones/publicaciones/{id}', [PublicacionControlador::class, 'editar']);
$router->delete('/api/adopciones/publicaciones/{id}', [PublicacionControlador::class, 'darDeBaja']);
$router->get('/api/adopciones/publicaciones/{id}/foto', [PublicacionControlador::class, 'foto']);
$router->post('/api/adopciones/publicaciones/{id}/aprobar', [PublicacionControlador::class, 'aprobar']);
$router->post('/api/adopciones/publicaciones/{id}/rechazar', [PublicacionControlador::class, 'rechazar']);
$router->get('/api/adopciones/mis-publicaciones', [PublicacionControlador::class, 'mias']);
$router->get('/api/adopciones/moderacion', [PublicacionControlador::class, 'porModerar']);

// Postulaciones, elección, chequeo y cierre
$router->get('/api/adopciones', [AdopcionControlador::class, 'listar']);
$router->get('/api/adopciones/mis-postulaciones', [AdopcionControlador::class, 'misPostulaciones']);
$router->get('/api/adopciones/{id}/postulaciones', [AdopcionControlador::class, 'postulaciones']);
$router->post('/api/adopciones/{id}/postulaciones', [AdopcionControlador::class, 'postularse']);
$router->post('/api/adopciones/{id}/elegir', [AdopcionControlador::class, 'elegir']);
$router->post('/api/adopciones/{id}/chequeo', [AdopcionControlador::class, 'chequeo']);
$router->post('/api/adopciones/{id}/completar', [AdopcionControlador::class, 'completar']);
$router->post('/api/adopciones/{id}/cancelar', [AdopcionControlador::class, 'cancelar']);

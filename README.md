# PAW--Adopciones--TPIntegrador

Módulo **Adopciones** de HuellaVet (Entrega 5, rol 3). Publicaciones de los rescatistas, moderación de
la veterinaria, postulaciones de los clientes y cierre de la adopción.

- Corre en su propio proceso, `127.0.0.1:9004`, detrás del Nginx del repo ApiGateway (prefijo
  `/api/adopciones`), con su propio usuario de MySQL (`adopciones`).
- Usa las herramientas compartidas de `comun/` del Gateway (router, respuestas, sesión, base).
- Las tablas (`publicacion`, `adopcion`, `postulacion`) y los permisos están en la migración
  `base/007_adopciones.sql` del Gateway. Los datos de prueba, en `base/semillas/007_adopciones.sql`.
- Contrato: [`docs/api/contrato-adopciones.md`](docs/api/contrato-adopciones.md).

## Variables

| Variable | Para qué |
|---|---|
| `DB_*` | Conexión a MySQL. `DB_USER` y `DB_PASSWORD` se los pasa `iniciar.sh` del Gateway desde `ADOPCIONES_DB_USER` y `ADOPCIONES_DB_PASSWORD`. |
| `JWT_PUBLIC_KEY` | Para leer la sesión. Adopciones no recibe la clave privada. |
| `INTERNAL_TOKEN` | Para pedirle a Pacientes que pase la mascota al adoptante al completar la adopción. |
| `PACIENTES_URL` | Opcional. Por defecto `http://127.0.0.1:9003`. |

## Pruebas

```bash
composer install
COMUN_DIR=../PAW--ApiGateway--TPIntegrador/comun TEST_DB_PASSWORD=pruebas vendor/bin/phpunit --testdox
```

Las pruebas de la base usan el MySQL de prueba (puerto 3307, base `huellavet_test`), nunca Aiven. El
circuito completo se prueba con `pruebas/integracion.sh` del Gateway (casos de `09_adopciones.sh`).

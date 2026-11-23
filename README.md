# HuellaVet — Adopciones

Repositorio del módulo **Adopciones** del Trabajo Práctico Integrador de **Programación en Ambiente Web (PAW)** — UNLu, 2026 — Grupo **La 25**.

Es el **circuito de adopción**: los rescatistas validados publican a los animales que tienen a su cargo, la veterinaria modera las publicaciones, los clientes se postulan, el rescatista elige al adoptante y la veterinaria cierra la adopción después del **chequeo veterinario obligatorio**. Al finalizar, la ficha del animal pasa al adoptante.

## Autores

| Integrante | Legajo |
| --- | --- |
| Ana Paula Romero | 195388 |
| Maria Trinidad Lopez | 197958 |
| Valentino Aimale | 197961 |
| Cristian Tomás Anito | 158887 |

## Documentación

- [Contrato de adopciones](./docs/api/contrato-adopciones.md): el circuito completo, los estados, cada endpoint, los avisos y los casos de humo.
- [Contrato del ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador/blob/dev/api/CONTRATO_API_ApiGateway.md): reglas comunes a todos los módulos.
- DER y UML: en el repo [Backend](https://github.com/romeroppau/PAW--Backend--TPIntegrador/tree/dev/docs).

---

## El circuito

```
Rescatista se registra                                      → PENDIENTE de validación   Backend
Veterinaria aprueba al rescatista (o lo rechaza)            → APROBADO / RECHAZADO      Backend
Rescatista carga a la mascota que tiene a su cargo          → mascota (dueño: él)       Pacientes
Rescatista publica a la mascota                             → publicación PENDIENTE     Adopciones
Veterinaria aprueba la publicación (o la rechaza con motivo) → adopción PUBLICADA        Adopciones
Clientes se postulan                                        → CON_POSTULANTES           Adopciones
Rescatista elige al adoptante (las demás quedan rechazadas) → ADOPTANTE_ELEGIDO         Adopciones
Veterinaria carga el turno CHEQUEO_ADOPCION y lo asocia     → CHEQUEO_PENDIENTE         Pacientes + Adopciones
Veterinaria atiende el chequeo                              → turno ATENDIDO            Pacientes
Veterinaria completa la adopción                            → FINALIZADA                Adopciones → Pacientes
   └─ Pacientes pasa la mascota al adoptante, con su ficha y su historial
```

Cualquier estado antes de `FINALIZADA` se puede cancelar (`CANCELADA`). Una publicación rechazada se puede editar y vuelve a moderación.

### Estados

| Qué | Estados |
| --- | --- |
| Publicación (`estado_moderacion`) | `PENDIENTE` → `APROBADA` o `RECHAZADA` (con motivo) |
| Adopción | `PUBLICADA` → `CON_POSTULANTES` → `ADOPTANTE_ELEGIDO` → `CHEQUEO_PENDIENTE` → `FINALIZADA`, o `CANCELADA` |
| Postulación | `PENDIENTE` → `ELEGIDA` o `RECHAZADA` |

---

## Endpoints

| Endpoint | Quién | Qué hace |
| --- | --- | --- |
| `GET /api/adopciones/publicaciones` | Público | Animales disponibles para adoptar |
| `GET /api/adopciones/publicaciones/{id}`, `…/{id}/foto` | Público | Una publicación y su foto (una sin aprobar da 404 al público) |
| `POST /api/adopciones/publicaciones` | Rescatista aprobado | Publicar un animal, con foto y descripción |
| `GET /api/adopciones/mis-publicaciones` | Rescatista | Sus publicaciones, con el estado de la moderación y de la adopción |
| `PUT`, `DELETE /api/adopciones/publicaciones/{id}` | Rescatista | Editar o dar de baja |
| `GET /api/adopciones/moderacion` | Veterinaria | Publicaciones por moderar |
| `POST …/publicaciones/{id}/aprobar`, `…/rechazar` | Veterinaria | Aprobar o rechazar con motivo |
| `POST /api/adopciones/{id}/postulaciones` | Cliente | Postularse (una sola vez por adopción) |
| `GET /api/adopciones/mis-postulaciones` | Cliente | Sus postulaciones, con el estado de cada una y el turno de chequeo |
| `GET /api/adopciones/{id}/postulaciones` | Rescatista | Los postulantes de su adopción |
| `POST /api/adopciones/{id}/elegir` | Rescatista | Elegir al adoptante; las demás postulaciones quedan rechazadas en la misma transacción |
| `GET /api/adopciones` | Veterinaria | Todas las adopciones, con filtros (`estado`, `desde`, `hasta`, `buscar`), orden y paginado. Las que necesitan algo de la veterinaria aparecen primero |
| `GET /api/adopciones/{id}/detalle` | Veterinaria / rescatista de la publicación | El detalle de una adopción, con los contactos, el turno de chequeo y todas las postulaciones |
| `POST /api/adopciones/{id}/chequeo` | Veterinaria | Asociar (o reprogramar) el turno de chequeo |
| `POST /api/adopciones/{id}/completar` | Veterinaria | Cerrar la adopción, con el chequeo ya atendido. Pide a Pacientes la transferencia de la mascota |
| `POST /api/adopciones/{id}/cancelar` | Veterinaria | Cancelar la adopción |
| `GET /api/adopciones/salud` | Público | Salud del módulo |

---

## Avisos

Adopciones le pide a Backend cada aviso (`POST /internal/notificaciones`) con un `id_operacion` fijo, así un reintento no lo repite. Si Backend no responde, la acción se hace igual y el error queda en el log.

| Cuándo | A quién | Canal |
| --- | --- | --- |
| La rescatista publica un animal | Veterinaria | Web |
| La veterinaria aprueba o rechaza la publicación (con el motivo) | Rescatista | Web |
| Llega una postulación | Rescatista | Web |
| El rescatista elige al adoptante | Cliente elegido | Web |
| La veterinaria asigna o reprograma el turno del chequeo | Adoptante y rescatista | Web y WhatsApp |
| Se completa la adopción | Adoptante | Web |

Los controles de los 30 y 90 días después de la adopción los manda el motor diario de **Reportes**.

---

## Seguridad

- Sin sesión, 401; con un rol que no corresponde, 403. `POST`, `PUT` y `DELETE` exigen el token CSRF.
- El rescatista, el cliente y la veterinaria salen siempre de la sesión, nunca del pedido.
- Solo publica un rescatista **aprobado**: el estado se lee de la base en cada publicación (si la veterinaria lo aprueba, no hace falta volver a iniciar sesión).
- Lo que no es de uno da 404, igual que si no existiera: la publicación de otro rescatista, la postulación de otro cliente, una publicación sin aprobar vista por el público.
- Una mascota tiene una sola publicación abierta y un cliente se postula una sola vez por adopción: la base lo asegura con índices únicos.
- Las fotos se validan por su contenido real (PNG, JPG o WEBP).
- El usuario de MySQL `adopciones` solo trabaja sobre sus tablas.
- Las llamadas a Pacientes y Backend van por `/internal/` con el token entre servicios; Nginx no las expone afuera.

---

## Base de datos

Las tablas y los permisos están en la migración `base/007_adopciones.sql` del repo **ApiGateway**. Los datos de prueba, en `base/semillas/007_adopciones.sql`.

| Tabla | Para qué |
| --- | --- |
| `publicacion` | El animal publicado: título, descripción, foto, estado de moderación y motivo de rechazo |
| `adopcion` | El circuito de cada publicación aprobada: estado, adoptante, turno de chequeo y fecha de fin |
| `postulacion` | Las postulaciones de los clientes |

---

## Cómo está organizado el código

| Archivo | Qué hace |
| --- | --- |
| `src/index.php` | Punto de entrada: carga el código común, registra las rutas y responde 500 si algo falla de forma inesperada |
| `src/rutas/adopciones.php`, `salud.php` | Las rutas |
| `src/PublicacionControlador.php` | Publicaciones y moderación |
| `src/AdopcionControlador.php` | Postulaciones, elección, chequeo, cierre y cancelación |
| `src/AdopcionRepositorio.php` | Todas las consultas SQL y las transacciones |
| `src/ValidadorAdopciones.php` | Validación de cada pedido y de los filtros |
| `src/AvisosAdopcion.php` | Arma los avisos del circuito |
| `src/ClienteBackend.php`, `ClientePacientes.php` | Las llamadas internas a Backend (avisos) y a Pacientes (transferencia) |
| `src/Imagen.php`, `ParametroRuta.php` | La foto y la lectura del `{id}` de la ruta |
| `tests/` | Pruebas PHPUnit |

---

## Cómo correrlo

No se levanta solo: corre dentro del contenedor que arma el repo **ApiGateway** (proceso `127.0.0.1:9004`). Nginx deriva `/api/adopciones` a este módulo. Los pasos están en el README de [ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador#cómo-correrlo).

```bash
curl http://localhost:10000/api/adopciones/salud
```

Tiene que devolver `{"estado":"funciona","modulo":"Adopciones"}`.

### Variables propias

| Variable | Para qué |
| --- | --- |
| `DB_USER`, `DB_PASSWORD` | Se los pasa `iniciar.sh` desde `ADOPCIONES_DB_USER` y `ADOPCIONES_DB_PASSWORD`. Si faltan, el módulo no se inicia |
| `JWT_PUBLIC_KEY` | Leer la sesión (no recibe la clave privada) |
| `INTERNAL_TOKEN` | Pedir avisos a Backend y la transferencia a Pacientes |
| `BACKEND_URL`, `PACIENTES_URL` | Opcionales. Por defecto `http://127.0.0.1:9001` y `http://127.0.0.1:9003` |

---

## Pruebas

- **PHPUnit** (`tests/`): el validador (datos de la publicación, postulación, filtros) y los avisos del circuito.

  ```bash
  composer install
  COMUN_DIR=../PAW--ApiGateway--TPIntegrador/comun vendor/bin/phpunit --testdox
  ```

  Las pruebas que usan la base van contra el MySQL de prueba local (nunca Aiven): con `TEST_DB_PASSWORD` configurado; si no, se saltean.

- **Pruebas de humo** (repo ApiGateway): `pruebas/casos/09_adopciones.sh` recorre el circuito completo, de la publicación a la transferencia de la mascota.
- **GitHub Actions** corre la sintaxis y PHPUnit en cada push y pull request.

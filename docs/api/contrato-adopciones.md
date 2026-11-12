# Contrato: adopciones

Autor: rol 3 (Valentino). Entrega 5.

El circuito entero de la adopción, de punta a punta. Lo atienden tres módulos:

| Parte | Módulo | Prefijo | Rama |
|---|---|---|---|
| Validación de rescatistas | Backend (9001) | `/api/rescatistas` | `backend-rescatistas` |
| Publicación, moderación, postulaciones, chequeo y cierre | Adopciones (9004) | `/api/adopciones` | `adopciones-circuito` |
| Mascotas del rescatista y transferencia al adoptante | Pacientes (9003) | `/api/pacientes`, `/internal/mascotas` | `pacientes-transferir` |

Tablas: `publicacion`, `adopcion` y `postulacion` (migración `007_adopciones.sql`, en el Gateway).
Datos de prueba: `base/semillas/README.md` del Gateway, sección "Adopciones".

## El circuito

```
Rescatista se registra                                     → usuario PENDIENTE        (ya existe, E3)
Veterinaria aprueba al rescatista (o lo rechaza)            → APROBADO / RECHAZADO     Backend
Rescatista carga a la mascota que tiene a su cargo          → mascota (dueño: él)      Pacientes
Rescatista publica a la mascota                             → publicación PENDIENTE    Adopciones
Veterinaria aprueba la publicación (o la rechaza con motivo) → adopción PUBLICADA       Adopciones
Clientes se postulan                                        → CON_POSTULANTES          Adopciones
Rescatista elige al adoptante (las demás quedan rechazadas)  → ADOPTANTE_ELEGIDO        Adopciones
Veterinaria carga el turno CHEQUEO_ADOPCION y lo asocia     → CHEQUEO_PENDIENTE        Pacientes + Adopciones
Veterinaria atiende el chequeo (la consulta de la E4)       → turno ATENDIDO           Pacientes
Veterinaria completa la adopción                            → FINALIZADA               Adopciones → Pacientes
   └─ Pacientes pasa la mascota al adoptante, con su ficha y su historial
```

## Reglas generales

Las mismas de los demás módulos:
- Sin sesión: 401. Con una sesión de un rol que no corresponde: 403.
- POST, PUT y DELETE exigen el token CSRF: sin él, 403.
- Los datos inválidos dan 400 con un error por campo: `{"error": "Revisá los datos…", "campos": {"titulo": "El título es obligatorio"}}`.
- **El rescatista, el cliente y la veterinaria salen de la sesión (JWT), nunca del pedido.**
- Lo que no es de uno da 404, igual que si no existiera: la publicación de otro rescatista, la
  postulación de otro cliente, una publicación sin aprobar vista por el público.
- Los errores devuelven `{"error": "mensaje", "campos": {}}`, sin detalles internos.
- Fechas `AAAA-MM-DD HH:MM:SS`, en hora de Argentina.

## Estados

**Rescatista** (`usuario.estado_validacion`): `PENDIENTE` → `APROBADO` o `RECHAZADO`. Solo uno
`APROBADO` publica. El JWT no lleva el estado: Adopciones lo lee de la base en cada publicación (si la
veterinaria lo aprueba, no hace falta volver a iniciar sesión).

**Publicación** (`estado_moderacion`): `PENDIENTE` → `APROBADA` o `RECHAZADA` (con motivo). Además,
`activa` pasa a `false` cuando se da de baja o cuando la adopción termina (finalizada o cancelada).
Una mascota tiene **una sola publicación abierta** (activa y no rechazada): la base lo asegura.

**Adopción** (se crea al aprobar la publicación):

```
PUBLICADA ──(1.ª postulación)──► CON_POSTULANTES ──(elegir)──► ADOPTANTE_ELEGIDO
    ──(chequeo)──► CHEQUEO_PENDIENTE ──(completar, con el chequeo ATENDIDO)──► FINALIZADA

Cualquier estado antes de FINALIZADA ──(cancelar)──► CANCELADA
```

**Postulación:** `PENDIENTE` → `ELEGIDA` o `RECHAZADA`. Al elegir una, las demás de esa adopción
quedan `RECHAZADA` en la misma transacción. Un cliente se postula una sola vez por adopción.

---

## 1. Validación de rescatistas (Backend)

### Listar rescatistas
`GET /api/rescatistas` — Veterinario. Parámetro opcional `estado`: `PENDIENTE` (por defecto),
`APROBADO`, `RECHAZADO` o `TODOS`. Ordenados por id (los más viejos primero, hasta 100).
- 200: `{"rescatistas": [{"id": 105, "nombre": "Rescatista Pendiente", "email": "rescatista2@prueba.huellavet", "telefono": "1155550105", "organizacion": "Patitas al Rescate", "estado_validacion": "PENDIENTE"}]}`
- 400: `estado` no es uno de los cuatro.

### Aprobar o rechazar
`POST /api/rescatistas/{id}/aprobar` y `POST /api/rescatistas/{id}/rechazar` — Veterinario. Sin cuerpo.
- 200: `{"rescatista": { ... }}` con el estado nuevo.
- 404: no existe o no es un rescatista.
- 409: no está `PENDIENTE` (`"El rescatista ya fue aprobado"` o `"… rechazado"`).

En la etapa 3, cada cambio deja un aviso al rescatista (ver "Avisos").

### La sesión dice el estado
`GET /api/auth/yo` (y el login) suman `estado_validacion`: el del rescatista, `null` para los
demás roles. El frontend lo usa para mostrar "Tu cuenta está esperando la aprobación" en lugar del
botón "Publicar".

---

## 2. Mascotas del rescatista (Pacientes)

Hoy los rescatistas reciben 403 en Pacientes. Con `pacientes-transferir`, **el rescatista usa las
mismas rutas que el cliente** para sus mascotas, con las mismas reglas (solo ve y toca las suyas):
- `GET /api/pacientes/mascotas` y `GET /api/pacientes/mascotas/{id}`
- `POST /api/pacientes/mascotas`, `PUT /api/pacientes/mascotas/{id}` y `DELETE /api/pacientes/mascotas/{id}`
- `GET /api/pacientes/mascotas/{id}/foto`

Puede cargarlas aunque su cuenta esté `PENDIENTE` (lo que no puede es publicarlas). Los turnos, el
historial y el calendario siguen siendo de la veterinaria y del cliente: el rescatista recibe 403.
Pacientes no lee las tablas de Adopciones: si el rescatista da de baja una mascota publicada, Adopciones
deja de mostrar esa publicación (filtra por `mascota.activo`) y no deja aprobarla ni completar su adopción.

---

## 3. Publicaciones (Adopciones)

### Forma de la publicación

```json
{
  "id": 101,
  "titulo": "Pipa busca familia",
  "descripcion": "Gatita de 6 meses, muy cariñosa…",
  "estado_moderacion": "APROBADA",
  "motivo_rechazo": null,
  "activa": true,
  "creada_en": "2026-11-06 10:00:00",
  "mascota": { "id": 902, "nombre": "Pipa", "especie": "GATO", "raza": null, "edad_meses": 6, "peso_kg": "2.10" },
  "rescatista": { "id": 104, "nombre": "Rescatista Aprobada", "organizacion": "Refugio Huellitas" },
  "adopcion": { "id": 101, "estado": "CON_POSTULANTES" }
}
```
`adopcion` es `null` mientras la publicación no se aprobó. La foto no viaja en la publicación: se pide
aparte. Para el rescatista y la veterinaria, `adopcion` suma `postulaciones_pendientes` (cantidad).

### Animales disponibles
`GET /api/adopciones/publicaciones` — **Público** (sin sesión). Las publicaciones aprobadas y
activas cuya adopción está `PUBLICADA` o `CON_POSTULANTES`, de la más nueva a la más vieja (hasta 100).
Parámetro opcional `especie`: `PERRO`, `GATO` u `OTRO`.
- 200: `{"publicaciones": [ ... ]}`
- 400: `especie` no es válida.

### Ver una publicación
`GET /api/adopciones/publicaciones/{id}` — Público, si está en "Animales disponibles". El rescatista
que la cargó y la veterinaria la ven en cualquier estado.
- 200: `{"publicacion": { ... }}`
- 404: no existe, o no está disponible para quien la pide.

### Foto
`GET /api/adopciones/publicaciones/{id}/foto` — Quien puede ver la publicación. Devuelve la imagen
con su `Content-Type`; se usa directo en un `<img src>`.
- 404 (JSON): no existe o no está disponible para quien la pide.

### Publicar
`POST /api/adopciones/publicaciones` — Rescatista **aprobado**.

```json
{ "mascota_id": 901, "titulo": "Canela busca familia", "descripcion": "Perra de un año…", "foto": "data:image/png;base64,…" }
```
- `mascota_id`: una mascota activa **a cargo del rescatista** (su dueño en Pacientes).
- `titulo`: de 3 a 120 caracteres. `descripcion`: de 10 a 2000 caracteres.
- `foto`: obligatoria. Data URL de una imagen PNG, JPG o WEBP de hasta 1 MB; se revisa el contenido
  real del archivo, igual que la foto de la mascota.

Respuestas:
- 201: `{"publicacion": { ... }}` en estado `PENDIENTE`.
- 400: datos inválidos, o `mascota_id` no es una mascota activa suya (`"campos": {"mascota_id": "Elegí una mascota que tengas a cargo"}`).
- 403: `{"error": "Tu cuenta todavía no fue aprobada"}` si el rescatista está `PENDIENTE` o `RECHAZADO`.
- 409: la mascota ya tiene una publicación abierta.

### Mis publicaciones
`GET /api/adopciones/mis-publicaciones` — Rescatista. Todas las suyas (también las rechazadas, con su
motivo, y las cerradas), de la más nueva a la más vieja.
- 200: `{"publicaciones": [ ... ]}`

### Editar y dar de baja (etapa 3)
- `PUT /api/adopciones/publicaciones/{id}` — Rescatista, la suya. Mismos campos que al publicar menos
  `mascota_id`, todos opcionales (la foto que no viene no cambia). Una publicación rechazada que se
  edita vuelve a `PENDIENTE` (sin motivo); una aprobada sigue aprobada. 409 si ya no está activa.
- `DELETE /api/adopciones/publicaciones/{id}` — Rescatista, la suya. Baja lógica (`activa = false`);
  si tenía una adopción en curso, se cancela (ver "Cancelar"). 409 si ya no está activa.

---

## 4. Moderación (Adopciones, veterinaria)

### Publicaciones por moderar
`GET /api/adopciones/moderacion` — Veterinario. Las publicaciones `PENDIENTE` y activas, de la más
vieja a la más nueva.
- 200: `{"publicaciones": [ ... ]}`

### Aprobar
`POST /api/adopciones/publicaciones/{id}/aprobar` — Veterinario. Sin cuerpo. En una transacción:
la publicación queda `APROBADA` y se crea su adopción en `PUBLICADA`.
- 200: `{"publicacion": { ... }}` con su `adopcion`.
- 404: no existe.
- 409: no está `PENDIENTE` o no está activa; o la mascota ya no está a cargo del rescatista.

### Rechazar
`POST /api/adopciones/publicaciones/{id}/rechazar` — Veterinario. `{"motivo": "La foto no se ve bien"}`
(de 3 a 255 caracteres). El rescatista lo ve en "Mis publicaciones" y puede editarla (etapa 3).
- 200: `{"publicacion": { ... }}` en `RECHAZADA`.
- 400: falta el motivo o es muy largo.
- 404 / 409: igual que al aprobar.

---

## 5. Postulaciones (Adopciones)

### Postularse
`POST /api/adopciones/{id}/postulaciones` — Cliente. `{id}` es la adopción (`publicacion.adopcion.id`).

```json
{ "tipo_vivienda": "Casa con patio", "otras_mascotas": "Un perro", "tiempo_disponible": "Trabajo desde casa" }
```
- `tipo_vivienda`: de 2 a 60 caracteres. `otras_mascotas`: de 2 a 255 ("Ninguna" vale).
  `tiempo_disponible`: de 2 a 120.

La primera postulación pasa la adopción de `PUBLICADA` a `CON_POSTULANTES`.
- 201: `{"postulacion": { ... }}` en `PENDIENTE`.
- 400: datos inválidos.
- 404: la adopción no existe o su publicación no está disponible.
- 409: ya se postuló a esta adopción, o la adopción no está `PUBLICADA` ni `CON_POSTULANTES`.

### Forma de la postulación

```json
{
  "id": 101, "estado": "PENDIENTE", "fecha": "2026-11-09 18:20:00",
  "tipo_vivienda": "Casa con patio", "otras_mascotas": "Un perro y un gato", "tiempo_disponible": "Trabajo desde casa",
  "cliente": { "id": 102, "nombre": "Cliente de Prueba", "telefono": null },
  "adopcion": { "id": 101, "estado": "CON_POSTULANTES" },
  "publicacion": { "id": 101, "titulo": "Pipa busca familia", "mascota": { "id": 902, "nombre": "Pipa" } }
}
```
**El teléfono del cliente** solo se le muestra al rescatista cuando la postulación está `ELEGIDA`
(para coordinar la entrega); antes es `null`.

### Mis postulaciones
`GET /api/adopciones/mis-postulaciones` — Cliente. Las suyas, de la más nueva a la más vieja.
- 200: `{"postulaciones": [ ... ]}`

### Postulantes de una adopción
`GET /api/adopciones/{id}/postulaciones` — El rescatista de la publicación o la veterinaria.
Por fecha, las más viejas primero.
- 200: `{"adopcion": {"id": 101, "estado": "CON_POSTULANTES"}, "postulaciones": [ ... ]}`
- 404: no existe, o es de otro rescatista.

### Elegir al adoptante
`POST /api/adopciones/{id}/elegir` — El rescatista de la publicación. `{"postulacion_id": 101}`.
En una transacción: esa postulación queda `ELEGIDA`, las demás `RECHAZADA` y la adopción
`ADOPTANTE_ELEGIDO`, con el cliente como adoptante.
- 200: `{"adopcion": { ... }, "postulaciones": [ ... ]}`
- 400: falta `postulacion_id`.
- 404: la adopción no existe o es de otro rescatista; o la postulación no es de esta adopción.
- 409: la adopción no está `CON_POSTULANTES`, o la postulación no está `PENDIENTE`.

---

## 6. Chequeo y cierre (Adopciones, veterinaria)

### Adopciones en curso
`GET /api/adopciones` — Veterinario. Parámetro opcional `estado` (uno de los seis); sin él, las que
no terminaron. Las más viejas primero (hasta 100).
- 200: `{"adopciones": [{"id": 101, "estado": "ADOPTANTE_ELEGIDO", "fecha_inicio": "…", "fecha_fin": null, "publicacion": { ... }, "adoptante": {"id": 103, "nombre": "Cliente Dos", "telefono": "1155550103"}, "turno_chequeo": null}]}`

`turno_chequeo` es `{"id", "fecha", "hora", "estado"}` o `null`.

### Asociar el chequeo
El turno se carga primero en la agenda, como cualquier otro: `POST /api/pacientes/turnos` con la
mascota de la publicación y `motivo: "CHEQUEO_ADOPCION"` (`contrato-mascotas-turnos.md`). Después:

`POST /api/adopciones/{id}/chequeo` — Veterinario. `{"turno_id": 120}`.
- El turno tiene que existir, ser `CHEQUEO_ADOPCION`, de la mascota de la publicación, estar
  `PROGRAMADO` y no ser el chequeo de otra adopción.
- La adopción tiene que estar `ADOPTANTE_ELEGIDO`, o `CHEQUEO_PENDIENTE` con su turno anterior
  `CANCELADO` o `AUSENTE` (así se reprograma el chequeo).
- 200: `{"adopcion": { ... }}` en `CHEQUEO_PENDIENTE`.
- 400: falta `turno_id`, o el turno no cumple lo de arriba (con el motivo en `campos.turno_id`).
- 404: la adopción no existe.
- 409: la adopción no está en un estado que lo permita.

### Completar
`POST /api/adopciones/{id}/completar` — Veterinario. Sin cuerpo.
1. Comprueba que la adopción esté `CHEQUEO_PENDIENTE` y que su turno esté `ATENDIDO`
   (si no: 409 `"El chequeo todavía no se atendió"`).
2. Le pide a Pacientes que pase la mascota al adoptante (`/internal/mascotas/{id}/transferir`).
3. En una transacción: la adopción queda `FINALIZADA` con `fecha_fin`, y la publicación `activa = false`.

- 200: `{"adopcion": { ... }}` en `FINALIZADA`.
- 404: la adopción no existe.
- 409: no está `CHEQUEO_PENDIENTE`, o el chequeo no está `ATENDIDO`.
- 502: `{"error": "No se pudo transferir la mascota. Probá de nuevo."}` si Pacientes no responde o
  responde un error. No cambia nada en Adopciones.

**Reintentar es seguro:** si el paso 3 falla después de transferir, el próximo intento vuelve a pedir
la transferencia, Pacientes responde que ya estaba hecha y se completa el paso 3.

### Cancelar
`POST /api/adopciones/{id}/cancelar` — El rescatista de la publicación o la veterinaria. Sin cuerpo.
En una transacción: la adopción queda `CANCELADA` con `fecha_fin`, la publicación `activa = false` y
las postulaciones `PENDIENTE` o `ELEGIDA`, `RECHAZADA`. La mascota sigue a cargo del rescatista, que
puede volver a publicarla. Si había un chequeo programado, la veterinaria lo cancela en la agenda.
- 200: `{"adopcion": { ... }}`
- 404: no existe, o es de otro rescatista.
- 409: ya está `FINALIZADA` o `CANCELADA`.

---

## 7. Transferir la mascota (Pacientes, interno)

Endpoint de proceso a proceso, igual que el descuento de stock de Inventario
(`contrato-interno.md`): **no es público**, Nginx bloquea `/internal/` desde afuera y además exige el token.

`POST http://127.0.0.1:9003/internal/mascotas/{id}/transferir`

**Header obligatorio:** `X-Servicio-Token: <INTERNAL_TOKEN>`

```json
{ "duenio_actual_id": 104, "nuevo_duenio_id": 103 }
```

| Campo | Regla |
|---|---|
| `duenio_actual_id` | Entero mayor que 0. El rescatista: Pacientes comprueba que la mascota siga siendo suya |
| `nuevo_duenio_id` | Entero mayor que 0. Tiene que ser un usuario con rol `CLIENTE` |

**Qué hace:** cambia `mascota.duenio_id`. La ficha, los turnos, las consultas, las facturas y el
calendario sanitario no se tocan: pasan con la mascota.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"transferida": true}` | Se pasó la mascota al nuevo dueño |
| `200` | `{"transferida": false}` | **Ya era del nuevo dueño** (un reintento). No cambia nada |
| `400` | `{"error": "Datos de solicitud inválidos"}` | Cuerpo mal formado, o el nuevo dueño no es un cliente |
| `401` | `{"error": "No autorizado"}` | Falta el token o no coincide |
| `404` | `{"error": "Mascota no encontrada"}` | No existe o está dada de baja |
| `409` | `{"error": "La mascota no es del dueño indicado", "motivo": "DUENIO_DISTINTO"}` | No es ni del dueño actual ni del nuevo |
| `500` | `{"error": "No se pudo transferir la mascota"}` | Error inesperado, sin detalles internos |

**Por qué no lleva `id_operacion`:** la operación ya es idempotente por sí misma. Repetirla con los
mismos datos encuentra la mascota en manos del nuevo dueño y responde `transferida: false`; y
`duenio_actual_id` evita pasar una mascota que cambió de manos por otro camino.

Adopciones lo llama con `PACIENTES_URL=http://127.0.0.1:9003` e `INTERNAL_TOKEN`, con un tiempo
máximo de 5 segundos.

---

## 8. Avisos (etapa 3)

Por `POST /internal/notificaciones` de Backend (`contrato-notificaciones.md`, rol 1), con un
`id_operacion` fijo para que un reintento no repita el aviso:

| Cuándo | A quién | Tipo | `id_operacion` |
|---|---|---|---|
| La veterinaria aprueba o rechaza al rescatista (lo manda Backend directamente) | Rescatista | `CUENTA` | `cuenta-{usuario}-{APROBADO\|RECHAZADO}` |
| Llega una postulación | Rescatista | `POSTULACION` | `postulacion-nueva-{postulacion}` |
| El rescatista elige al adoptante | Cliente elegido | `POSTULACION` | `postulacion-elegida-{postulacion}` |
| Se completa la adopción | Adoptante | `CONTROL_ADOPCION` | `adopcion-finalizada-{adopcion}` |

Los controles de los 30 y 90 días los manda el motor (rol 4), leyendo `adopcion.fecha_fin` de las
adopciones `FINALIZADA` (`control-{adopcion}-{30|90}`). Si Backend no responde, la acción del
circuito se hace igual: el aviso no es parte de la transacción.

## 9. Casos de humo (`09_adopciones.sh`)

Sobre las semillas: la rescatista 104 publica a Canela (901) y la 105 (pendiente) recibe 403; la
veterinaria aprueba la 105, rechaza la publicación de Rocco (102) y aprueba la de Canela; el público
ve a Pipa y a Canela y no a Rocco; el cliente 103 se postula a Pipa (101) y no puede repetir; la
rescatista elige al cliente 103 y la otra postulación queda rechazada; completar sin chequeo da 409;
la veterinaria carga el turno de chequeo, lo asocia, lo atiende y completa; Pipa pasa a ser del
cliente 103 (y su historial con ella); `/internal/mascotas/…/transferir` da 404 desde afuera.

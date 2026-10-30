# Zona Náutico (plugin de WordPress)

API de la app Zona Náutico sobre WordPress. Imita el contrato de la API Laravel actual
(`{data, links, meta}`, `_id` como cadena…) para que la app no haya que rehacerla:
basta con cambiar `EXPO_PUBLIC_API_URL`.

Base de la API: `<sitio>/wp-json/zonanautico/v1`

## Qué hay

| Ruta | Acceso | Notas |
|---|---|---|
| `GET /business` | público | `page`, `perPage` (máx. 50), `category`, `orderby` (`_id`, `name`, `score`), `order`, `includesUser` |
| `GET /business/{id}` | público | `includesUser` (el dueño, cuenta «Comercio (app)»; solo `_id`, `name`, `role`, `avatar`) |
| `GET /event` | público | `page`, `perPage` (máx. 50), `category`, `business`, `orderby` (`id`, `name`, `start_date`, `end_date`), `order`, `includesCategory`, `includesBusiness`, `includesUser` |
| `GET /event/{id}` | público | mismas inclusiones |
| `GET /draw` | público | `page`, `perPage` (máx. 50), `controlParticipar=1` (solo abiertos), `orderby` (`id`, `title`, `start_date`, `end_date`), `order`, `includesUser` |
| `GET /draw/{id}` | público | `includesUser` |
| `GET /review` | público | `page`, `perPage` (máx. 50), `business`, `user`, `orderby` (`id`, `datetime`, `user_id`, `business_id`), `order`, `includesUser`, `includesBusiness` |
| `GET /review/{id}` | público | mismas inclusiones |
| `GET /faq` | público | `page`, `perPage` (máx. 50), `user` (el comercio), `published`, `order`, `includesUser` (`user` y `publisher`). Las no publicadas no salen nunca (H20) |
| `GET /faq/{id}` | público | `includesUser` |

Los negocios se guardan como entradas personalizadas de WordPress (tipo `negocio`, menú «Negocios»
en wp-admin): nombre = título, descripción = contenido, y el resto como campos personalizados con
el mismo nombre que las columnas de la tabla `businesses` del Laravel. `_id` es el ID de la entrada.

En la ficha del negocio en wp-admin hay casillas para categoría, dirección, contacto, horario,
coordenadas y 3 fotos (biblioteca de medios). Los datos se validan al guardar; la puntuación solo se
muestra, no se edita (la calcularán las reseñas, H14).

Solo salen negocios, novedades y sorteos publicados y sin contraseña; las novedades de un negocio que
no se ve, tampoco. Las descripciones del editor salen como texto plano (sin etiquetas ni entidades del
editor visual); lo que escriben las personas en reseñas y preguntas sale tal cual (solo con las
entidades decodificadas) y las fechas de las reseñas en hora de Madrid.

**Dueños y cuentas de la app.** El dueño de un negocio se elige en su ficha («Dueño») entre las
cuentas con rol «Comercio (app)», que no tienen ningún permiso en wp-admin. Una cuenta de wp-admin
nunca sale en la API, y el nombre público es nombre + apellidos (nunca el nombre de acceso ni el correo).
Negocios, novedades y sorteos tienen permisos propios (`edit_zn_negocios`…) que de momento solo tiene
el administrador.

**Puertas cerradas.** XML-RPC, páginas de autor (`?author=`) y vista embed, mapa del sitio, oEmbed,
feeds, `/wp/v2/users`, `/wp/v2/media` y `/wp/v2/comments` (users y media siguen abiertas para quien
ya puede ver usuarios o subir archivos).

Las reseñas y las preguntas son comentarios de WordPress sobre el negocio (tipos `zn_resena` y
`zn_pregunta`). Solo salen las aprobadas; en las preguntas, «aprobada» es `published` y el comercio
destinatario se guarda en la propia pregunta (`user_id`), como en el Laravel. La puntuación
del negocio se recalcula sola con la media de sus reseñas aprobadas. Los comentarios nativos de
WordPress están cerrados (sin formulario, sin `/wp/v2/comments` y fuera de feeds y widgets).

## Desarrollo en local

El sitio de Local `zonanautico-local` (http://localhost:10010) enlaza esta carpeta mediante
un enlace de carpeta en `wp-content/plugins/zona-nautico`.

- Datos inventados (solo en WordPress local; cada tipo solo si está vacío): `wp zona-nautico datos-prueba`
- Comprobar el contrato de la API: `node tests/contrato-negocios.mjs`, `contrato-eventos.mjs`, `contrato-sorteos.mjs`, `contrato-resenas.mjs` y `contrato-preguntas.mjs`
- Comprobar la ficha de wp-admin: `wp eval-file tests/admin-fichas.php`
- Comprobar reseñas y preguntas por dentro (puntuación, cierre de comentarios): `wp eval-file tests/comentarios.php`
- Lo que nunca debe salir y los arreglos de la revisión 1 (crea y borra sus propios datos): `wp eval-file tests/seguridad.php`
- Campos iguales a las respuestas reales del Laravel (`Zona-Nautico/api-muestras`): `node tests/contrato-muestras.mjs`
- Puertas de WordPress cerradas, vistas desde fuera: `node tests/puertas.mjs`
- Recalcular todas las puntuaciones (al final de una migración): `wp zona-nautico recalcular-puntuaciones`

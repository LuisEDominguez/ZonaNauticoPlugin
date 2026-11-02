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
| `GET /user/{id}` | **con token** | La propia cuenta, o la que pueda ver (`user:read`…). Formato del UserResource del Laravel con `id` numérico |

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

**Roles de la app (R22).** Un rol de WordPress por cada rol del Laravel: «Administración (app)»,
«Federación (app)», «Asociación (app)», «Comercio (app)» y «Usuario (app)» (`zn_administrador`…
`zn_usuario`), con los permisos del Laravel tal cual como capacidades `zn:<permiso>` y ninguno de
WordPress: no entran a wp-admin ni por `wp-login.php`. Cada cuenta de la app tiene un solo rol; si tiene
más, no cuenta como cuenta de la app. Para las rutas de la app: `ZN_Roles::puede( 'business:save', $elemento )`
saca el dueño del propio elemento guardado y vale con el `_own` si es suyo (el permiso general, sobre un
elemento, solo para la administración de la app y las lecturas públicas); para crear,
`ZN_Roles::puede_crear( 'business:save', $dueno )`; y en preguntas, `campos_editables_de_pregunta()`. La
cuenta padre (`zn_parent_id`, un nivel) solo hereda si es asociación o federación y solo en negocios,
novedades y preguntas; en cuentas, «lo suyo» es solo uno mismo y las hijas van con `user:save_commerce`…

**Token de la app (R32).** «Token opaco»: 32 bytes al azar en base64url, que la app manda en
`Authorization: Bearer …`. En la tabla `{prefijo}zn_tokens` solo se guarda su huella SHA-256. Caduca a
los 60 minutos y se anula al cerrar sesión o, todos los de la cuenta, al cambiar su contraseña o su rol
o al borrarla. Solo vale en `/zonanautico/v1` por `index.php` (nunca en admin-ajax, wp-cron, XML-RPC,
wp-admin ni wp-login, aunque la dirección lleve `?rest_route=`) y para cuentas de la app; se valida con
`determine_current_user`, así que un token que no vale es «sin sesión» (las rutas públicas responden
normal y las privadas, 401 «Unauthenticated.»). `ZN_Tokens::emitir()`, `validar()`, `anular()` y
`anular_todos()`.

En un servidor Apache, la cabecera `Authorization` tiene que llegar a PHP. El `.htaccess` estándar de
WordPress ya lo hace con `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`; el plugin lee
también `REDIRECT_HTTP_AUTHORIZATION`. **Comprobarlo en el servidor de verdad** antes de abrir el login.

**Puertas cerradas.** XML-RPC, páginas de autor (`?author=`) y vista embed, mapa del sitio, oEmbed,
feeds, `/wp/v2/users`, `/wp/v2/media` y `/wp/v2/comments` (users y media siguen abiertas para quien
ya puede ver usuarios o subir archivos).

**Errores con el formato del Laravel** (los que entiende la app): `422 {message, errors: {campo: [...]}}`
para datos no válidos, `401 {"message":"Unauthenticated."}` sin sesión, `401 {"message":"This action is
unauthorized."}` sin permiso, `404`/`405` y `500` solo con `{message}`. Los errores de WordPress
(`{code, message, data}`) se traducen al salir (`includes/class-zn-errores.php`); las rutas nuevas usan
`ZN_Errores::no_valido()`, `no_autenticado()` y `sin_permiso()`.

Las reseñas y las preguntas son comentarios de WordPress sobre el negocio (tipos `zn_resena` y
`zn_pregunta`). Solo salen las aprobadas; en las preguntas, «aprobada» es `published` y el comercio
destinatario se guarda en la propia pregunta (`user_id`), como en el Laravel. La puntuación
del negocio se recalcula sola con la media de sus reseñas aprobadas. Los comentarios nativos de
WordPress están cerrados (sin formulario, sin `/wp/v2/comments` y fuera de feeds y widgets).

**Correo.** A las cuentas de la app WordPress no les manda «tu contraseña/tu correo ha cambiado» (lo
hará la app). **Por defecto no sale ningún correo a personas reales**: solo a direcciones
`@ejemplo.invalid` (también en Cc y Bcc), que en Local recoge Mailpit. Para enviar correos de verdad, en
el servidor de producción: `define( 'ZN_CORREO_REAL', true );` en `wp-config.php` (y entorno
`production`). Sin avisos de «nuevo usuario» al administrador ni de comentarios. Nadie se registra por
`wp-login.php` y el rol por defecto nunca es uno de la app. Las cuentas de administración de la app no
salen nunca como `user` en la API.

**Campos y validación comunes (R58).** Los campos de negocios, novedades, sorteos, reseñas y preguntas
están definidos una sola vez en `includes/class-zn-esquema.php`: nombre en la app (camelCase) → campo
guardado, tipo, máximo (sección 4.3 del traspaso) y si es obligatorio. De ahí salen los campos
personalizados que se registran, los campos «simples» de las respuestas, las reglas de las fichas de
wp-admin (con su `maxlength`) y los de la orden de datos de prueba. Las rutas de escritura validarán con
`ZN_Esquema::validar( $recurso, $cuerpo, $guardado_o_null )`, pasarán el resultado por
`ZN_Esquema::permitir_dueno()` (el dueño solo con permiso: al crear, `puede_crear()`; al modificar, solo la
administración de la app lo cambia; en novedades, la regla de R70), responderán con `ZN_Esquema::respuesta()`
(422 con los textos del Laravel) y guardarán con `ZN_Esquema::guardar( $recurso, $id_o_null, $valores, $extra )`,
que reparte título, contenido, campos personalizados y campos del comentario, y aprueba los comentarios con
`wp_set_comment_status()`. Las fichas de wp-admin guardan con la misma pieza. Hay también esquema de cuenta
(`cuenta`: nombre, email único, contraseña de 8 a 255, teléfono y código de 6 cifras, como la app). Regla del PATCH: campo ausente → no se toca; `null` → se borra (en un
obligatorio, 422); `""` → 422.

**Límite de intentos (R59).** `ZN_Limite::intentar( 'login', $email )`, llamada **antes** de mirar la
contraseña, cuenta por cuenta + IP (el límite estricto: así nadie bloquea a otra persona), por cuenta sola (más
alto) y por IP (las IPv6 por su `/64`). La cuenta va por su número si existe. Guarda huellas HMAC con la clave
secreta del sitio en la tabla `{prefijo}zn_limites` y borra las caducadas en cada llamada. Devuelve los
segundos de espera si se pasa;
`ZN_Limite::demasiados( $espera )` da el 429 `{"message":"Too Many Attempts."}` con `Retry-After`. Los
topes están en `ZN_Limite::REGLAS`. La IP es `REMOTE_ADDR`; `X-Forwarded-For` solo se mira si
`REMOTE_ADDR` es un proxy de confianza declarado en `wp-config.php`:
`define( 'ZN_PROXIES', '10.0.0.0/8' );` (IPs o rangos, separados por comas). Los rangos IPv4 se escriben en
su forma normal (`10.0.0.0/8`), nunca como IPv6 (`::ffff:10.0.0.0/104`): así escritos se ignorarían. Una IP
que llegue como `::ffff:10.1.2.3` sí se reconoce (R78, R83). **Al subir al servidor,
averiguar si Dinahosting pone un proxy delante y cuáles son sus IPs.** Además, como mucho 10 tokens
activos por cuenta (`ZN_Tokens::MAX_ACTIVOS`): uno nuevo anula los más antiguos.

**Entrar y salir de la app (R30, R38, R39).** `includes/class-zn-api-sesion.php`. `POST /login` con
`{email, password}` en el cuerpo JSON (lo que llegue en la dirección se ignora): responde como el Laravel,
`{status: "success", token, id, role}`; con credenciales malas, `401 {status: "error", message: "Unauthorized"}`
(igual si la cuenta no existe o es de wp-admin, y con una comprobación de contraseña en todos los casos para
que el tiempo no lo delate); sin verificar el correo (`zn_verificado`), `401 {status: "email-not-verified"}`,
solo con la contraseña buena. Primero valida el formato (422, sin contar el intento), luego el límite de
intentos (429) y luego la contraseña con `wp_check_password()` (nunca `wp_authenticate()`, que rechaza las
cuentas de la app). No abre sesión de WordPress ni pone cookies, y cierra las que tuviera la cuenta.
`POST /refresh` (con un token que vale) da uno nuevo con la misma respuesta y anula el anterior; con uno
caducado hay que entrar de nuevo. `POST /logout` anula el token de la petición. Sin token, los dos dan
`401 «Unauthenticated.»`. Las respuestas con token llevan `Cache-Control: no-store`.

**Dueños de novedades y sorteos.** Campo `user_id`, elegido en su ficha («Dueño») entre las cuentas de
comercio, asociación, federación o administración de la app; sale como `user` con `includesUser`.

## Subir al servidor

Solo `zona-nautico.php`, `includes/`, `assets/` y `.htaccess` (nada de pruebas ni documentación). El
paquete se hace con git, que ya excluye lo demás (`.gitattributes`):

    git archive --format=zip --prefix=zona-nautico/ -o zona-nautico.zip HEAD

El `.htaccess` (Apache) bloquea además `.md`, `.mjs` y `tests/` por si se copiara la carpeta entera.
Pendiente de comprobar en el servidor: que llega la cabecera `Authorization`, que `SCRIPT_NAME` es
`index.php` (R39), que las respuestas con token no se guardan en caché (R49) y si hay un proxy delante
(entonces, `ZN_PROXIES` con sus IPs, R59).

## Desarrollo en local

El sitio de Local `zonanautico-local` (http://localhost:10010) enlaza esta carpeta mediante
un enlace de carpeta en `wp-content/plugins/zona-nautico`.

- **Todas las pruebas de una vez:** `node tests/todas.mjs` (con pausas; dice al final cuántas van bien).
  Las que crean datos solo funcionan en un WordPress local y usan contraseñas al azar.
- Datos inventados (solo en WordPress local; cada tipo solo si está vacío): `wp zona-nautico datos-prueba`
- Comprobar el contrato de la API: `node tests/contrato-negocios.mjs`, `contrato-eventos.mjs`, `contrato-sorteos.mjs`, `contrato-resenas.mjs` y `contrato-preguntas.mjs`
- Comprobar la ficha de wp-admin: `wp eval-file tests/admin-fichas.php`
- Comprobar reseñas y preguntas por dentro (puntuación, cierre de comentarios): `wp eval-file tests/comentarios.php`
- Lo que nunca debe salir y los arreglos de la revisión 1 (crea y borra sus propios datos): `wp eval-file tests/seguridad.php`
- Campos iguales a las respuestas reales del Laravel (`Zona-Nautico/api-muestras`): `node tests/contrato-muestras.mjs`
- Puertas de WordPress cerradas, vistas desde fuera: `node tests/puertas.mjs`
- Roles y permisos (crea y borra sus propias cuentas): `wp eval-file tests/roles.php`
- Token y `GET /user/{id}` (crea y borra cuentas y tokens; peticiones HTTP de verdad): `wp eval-file tests/tokens.php`
- Campos y validación comunes (solo lee): `wp eval-file tests/esquema.php`
- Límite de intentos (escribe y borra solo sus filas): `wp eval-file tests/limite.php`
- Login, refresh y logout (crea y borra cuentas, tokens y sus filas de intentos; también por HTTP): `wp eval-file tests/login.php`
- Dueño con permisos y guardado común (crea y borra cuentas, negocios y comentarios): `wp eval-file tests/escrituras.php`
- Formato de los errores: `node tests/contrato-errores.mjs` y `wp eval-file tests/errores.php` (los dos solo leen)
- Recalcular todas las puntuaciones (al final de una migración): `wp zona-nautico recalcular-puntuaciones`

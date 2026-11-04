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
| `POST /review` | **con token** | `businessId`, `score` (1 a 5), `description`, `title`. El autor es la cuenta del token (el `userId` de la app se ignora); una por persona y negocio (si no, 422); se publica al momento. `201 {data}` |
| `DELETE /review/{id}` | **con token** | Quien la escribió o la administración de la app. Borrado definitivo. `200 {status: "success"}` |
| `PATCH /review/reply/{id}` | **con token** | `response` (null la borra). El dueño del negocio o la administración de la app. `200 {data}` |
| `POST /faq` | **con token** | `userId` (el comercio; si no lo es, 422), `question`. Queda sin publicar. `201 {data}` |
| `PATCH /faq/{id}` | **con token** | `question`, `answer`, `published` (`true`/`false` o `1`/`0`). Comercio, su asociación y administración: todo; quien preguntó: solo el texto (y vuelve a no publicada). `200 {data}` |
| `POST /event` | **con token** | `name`*, `userId`* (el dueño: uno mismo o, la asociación o federación, su comercio), `businessId`, `category`, `description`, `latitude`, `longitude`, `startDate`, `endDate` (`Y-m-d H:i:s`), `photos[]`. Se publica. `201 {data}` |
| `PUT\|PATCH /event/{id}` | **con token** | Los mismos, opcionales (null borra; "" es 422). El dueño, su asociación o federación, o la administración de la app (la única que cambia el dueño). `200 {data}` |
| `DELETE /event/{id}` | **con token** | Los mismos. Borrado definitivo. `200 {status: "success"}` |
| `POST /draw` | **con token** | `title`*, `userId`*, `startDate`*, `endDate`*, `winners`* (≥ 1), `description`, `legalUrl`, `photos[]`. `201 {data}` |
| `PUT\|PATCH /draw/{id}`, `DELETE /draw/{id}` | **con token** | Solo su dueño o la administración de la app. Un PATCH sin `legalUrl` no lo borra |
| `GET /user/{id}` | **con token** | La propia cuenta, o la que pueda ver (`user:read`…). Formato del UserResource del Laravel con `id` numérico |
| `PUT\|PATCH /user/{id}` | **con token** | Solo la propia cuenta: `name`, `phone`, `email`, `password`. El correo y la contraseña piden `currentPassword` (R69). null borra solo el teléfono; "" es 422. `roleId` → 401. `200 {data}` como `GET /user/{id}` |
| `DELETE /user/{id}` | **con token** | «Eliminar cuenta»: solo la propia y con `user:delete_self` (hoy, solo el rol `user`, R44), con `currentPassword` en el cuerpo (R110). Borrado definitivo, como en Usuarios (R65, R95, R96), con sus tokens anulados y un correo de aviso (R111). `200 {status: "success"}` |
| `GET\|POST /verify-email` | público | Enlace del correo nuevo (`id`, `token`): GET pinta el botón, POST confirma el cambio |

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
WordPress: no entran a wp-admin ni por `wp-login.php` (salvo asociación y federación, a su panel; ver abajo). Cada cuenta de la app tiene un solo rol; si tiene
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

**Límite de escrituras (R94).** Crear reseñas, responderlas, hacer o modificar preguntas y crear o modificar
novedades y sorteos: 20 por hora y cuenta
(cuentan también las que no valen); al pasarse, `429 «Too Many Attempts.»`.

**Datos personales (R65).** Reseñas y preguntas no guardan la IP, el navegador ni el correo de quien escribe.
Al borrar una cuenta se borran sus reseñas y preguntas (y las que le hicieron, si es un comercio) y se recalculan
las puntuaciones; sus negocios, novedades y sorteos pasan a borrador y se quedan sin dueño (R95, R96). En Herramientas › Exportar / Borrar datos personales aparece «App Zona Náutico»: exporta la
cuenta, sus reseñas y sus preguntas; borrar quita sus reseñas y preguntas, el teléfono y la ubicación (la cuenta se
borra en Usuarios). `includes/class-zn-privacidad.php`.

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
alto; un intento ya frenado por cuenta + IP o por IP no suma aquí, R84) y por IP (las IPv6 por su `/64`). La cuenta va por su número si existe. Guarda huellas HMAC con la clave
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
que el tiempo no lo delate; sin cuenta, contra una huella de relleno guardada en la opción `zn_huella_relleno`
con el coste de este servidor, R85); sin verificar el correo (`zn_verificado`), `401 {status: "email-not-verified"}`,
solo con la contraseña buena. Primero valida el formato (422, sin contar el intento), luego el límite de
intentos (429) y luego la contraseña con `wp_check_password()` (nunca `wp_authenticate()`, que rechaza las
cuentas de la app). No abre sesión de WordPress ni pone cookies, y cierra las que tuviera la cuenta.
`POST /refresh` (con un token que vale) da uno nuevo con la misma respuesta y anula el anterior (solo una
vez: dos a la vez con el mismo token no dan dos, R87); con uno caducado, o pasados 14 días desde el login
(`ZN_Tokens::MAX_RENOVAR`, R86), hay que entrar de nuevo. `POST /logout` anula el token de la petición. Sin token, los dos dan
`401 «Unauthenticated.»`. Las respuestas con token llevan `Cache-Control: no-store`.

**Alta, verificación y recuperación (R30, R53, R62).** `includes/class-zn-api-cuenta.php`, con las respuestas
del Laravel que espera la app. `POST /register` (nombre, email, contraseña y, si quiere, teléfono; la ubicación no se guarda, R91):
`201 {status: "success"}`, siempre con el rol «user»; el nombre entero en `first_name`; email repetido →
`422` «The email has already been taken.». Manda un correo con el enlace `GET /verify?id=…&token=…` (token al azar,
solo su huella guardada, 48 horas, una sola vez), que abre una página con el botón «Confirmar mi correo»: abrir
el enlace no verifica (los filtros de correo lo abren solos, R88); lo hace el botón (`POST /verify`). Con
`&format=json`, JSON. `GET /send-email?email=` lo reenvía. `POST /reset-password-code` manda un código de 6
cifras (15 minutos, 5 intentos) y `POST /reset-password` (email, código y contraseña nueva) la cambia, cierra las
sesiones de la cuenta y deja el correo verificado; un código que no vale da `400 {status: "error", message:
"Invalid code"}`. Reenvío y código responden siempre `200 {status: "success"}`, exista o no la cuenta, para no
delatarla, y nunca piden un Bearer compartido. Todas validan primero el formato (422), luego el límite de
intentos (429). Los errores de cuentas de WordPress sin estado (`existing_user_email`…) salen como 422, no 500.

**Cambiar mis datos (R69).** `PATCH /user/{id}` (también `PUT`, y `POST` con `?_method=PATCH`), solo sobre la
propia cuenta. Nombre y teléfono cambian al momento; el correo y la contraseña piden la contraseña actual
(`currentPassword`): sin ella, `422` «The current password field is required.»; mala, `422` «The password is
incorrect.». Primero el formato (422), luego el límite de intentos `datos` (5 por cuenta e IP cada 15 minutos,
429) y luego la contraseña actual; que el correo nuevo ya es de otra cuenta solo se dice con la actual buena. La
contraseña nueva anula los demás tokens (no el de la app que la cambia), el código de recuperación y el cambio
de correo pendiente, y avisa por correo. El correo nuevo queda pendiente (`zn_email_nuevo`) hasta que se pulsa
el botón del enlace `GET /verify-email?id=…&token=…` que le llega (48 horas, una vez); al viejo le llega un
aviso. Como mucho 3 correos nuevos pedidos al día por cuenta (R105, 429); mandar su correo de siempre cancela el
cambio pendiente (R106). Al confirmar se anulan todos los tokens de la cuenta, se entra con el correo nuevo y al
viejo le llega otro aviso (R107); si otra cuenta se ha quedado el mismo correo a la vez, se deshace (R108).

**Eliminar cuenta.** `DELETE /user/{id}`, solo sobre la propia cuenta y solo si su rol tiene `user:delete_self`
(hoy, solo `user`, R44): el comercio, la asociación, la federación y la administración de la app reciben `401`, y
nadie borra otra cuenta desde aquí. Cuenta en el límite de escrituras (R94, 429). Pide la contraseña actual
(`currentPassword`, R110) igual que `PATCH /user/{id}`: mismos 422 y mismo límite de intentos `datos` (429). Borra
con `wp_delete_user()`, así que pasa lo mismo que al borrarla en Usuarios: reseñas y preguntas fuera, negocios,
novedades y sorteos a borrador y sin dueño, y todos sus tokens anulados. Después le llega un correo: «Tu cuenta de
Zona Náutico se ha borrado.» y, solo si `ZN_CORREO_CONTACTO` es un correo, «Si no has sido tú, escríbenos a …» (R111).

**Panel de asociación y federación (R40).** Asociación y federación entran a wp-admin
por `wp-login.php` con su misma cuenta de la app (correo verificado, un solo rol; mismo límite de intentos
que `POST /login`). Solo ven «Mi zona»: las cuentas que cuelgan de ellas (un nivel, las que pueden leer; la federación, también
los comercios de sus asociaciones, sin su correo) y los
negocios, novedades y sorteos suyos o de esas cuentas, sin enlaces ni formularios. Cualquier otra página de
wp-admin las devuelve ahí; no editan ni su perfil. Comercios, usuarios y la administración de la app siguen
sin wp-admin. En `/zonanautico/v1` la cookie del panel no vale: solo el token; por eso entrar en la app no cierra la
sesión del panel (R98). **Editar (parte 2a):** en «Mi zona», «Editar» en los negocios, novedades y sorteos
que la app les deja guardar (`ZN_Roles::puede`, un nivel): textos, categoría, fechas, ganadores y bases; pueden
pasar a borrador pero no publicar (eso, la administración); y borrar, a la papelera, si la app les deja borrar
(las novedades de un negocio borrado pasan a borrador). Al restaurar el negocio desde la papelera, sus novedades
siguen en borrador: hay que publicarlas a mano. En el servidor, **nunca** `EMPTY_TRASH_DAYS` a 0 en wp-config.php:
con 0, WordPress no usa la papelera y borrar desde el panel sería para siempre.
**Cuentas (parte 2b).** La asociación da de alta, renombra y da de baja sus comercios; la federación, sus
asociaciones (con los permisos de la app). Alta: nombre y correo; la cuenta se crea sin verificar y con una
contraseña al azar que nadie conoce, y le llega un correo para ponerla con «Olvidé la contraseña» en la app.
Baja = desactivar: no entra en la app ni en el panel, se cierran sus sesiones y sus cosas pasan a borrador; no se
borra nada. La administración la reactiva en Usuarios («Reactivar»); sus cosas las vuelve a publicar ella. Si la
federación da de baja una asociación, sus comercios se quedan sin gestor en el panel hasta que la administración
los pase a otra (R102). Una cuenta dada de baja no recibe códigos de recuperación (R104). **Pendiente (R103):** el
correo de contacto del correo de alta va en wp-config.php con `define( 'ZN_CORREO_CONTACTO', '…' );` (Luis dirá
cuál); sin él, el correo dice «avisa a Zona Náutico» sin dirección. Con sello, el esquema común y el límite de escrituras
de la app. Sin dueño, fotos, vídeos ni coordenadas.

**Dueños de novedades y sorteos.** Campo `user_id`, elegido en su ficha («Dueño») entre las cuentas de
comercio, asociación, federación o administración de la app; sale como `user` con `includesUser`.

## Subir al servidor

Solo `zona-nautico.php`, `includes/`, `assets/` y `.htaccess` (nada de pruebas ni documentación). El
paquete se hace con git, que ya excluye lo demás (`.gitattributes`):

    git archive --format=zip --prefix=zona-nautico/ -o zona-nautico.zip HEAD

El `.htaccess` (Apache) bloquea además `.md`, `.mjs` y `tests/` por si se copiara la carpeta entera.
Pendiente de comprobar en el servidor: que llega la cabecera `Authorization`, que `SCRIPT_NAME` es
`index.php` (R39), que las respuestas con token no se guardan en caché (R49) y si hay un proxy delante
(entonces, `ZN_PROXIES` con sus IPs, R59). En `wp-config.php`, `define( 'ZN_CORREO_CONTACTO', '…' );` con el
correo que diga Luis: lo usan el correo de alta del panel (R103) y el de «tu cuenta se ha borrado» (R111); sin él,
ese correo sale sin la frase «Si no has sido tú, escríbenos a …».

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
- Registro, verificación y recuperación (crea y borra cuentas; los correos se recogen y no salen): `wp eval-file tests/registro.php`
- Cambiar mis datos y confirmar el correo nuevo (crea y borra cuentas, tokens y sus intentos; también por HTTP): `wp eval-file tests/mis-datos.php`
- Dueño con permisos y guardado común (crea y borra cuentas, negocios y comentarios): `wp eval-file tests/escrituras.php`
- Reseñas y preguntas desde la app (crea y borra cuentas, negocios y comentarios; también por HTTP): `wp eval-file tests/opiniones.php`
- Novedades y sorteos desde la app (crea y borra cuentas, negocios, novedades y sorteos; también por HTTP): `wp eval-file tests/novedades-sorteos.php`
- Datos personales: borrar cuenta, exportar y borrar (crea y borra cuentas, negocios y comentarios): `wp eval-file tests/privacidad.php`
- Eliminar cuenta desde la app, `DELETE /user/{id}` (crea y borra cuentas, negocios, comentarios, tokens y sus intentos; también por HTTP; los correos se recogen y no salen): `wp eval-file tests/borrar-cuenta.php`
- Panel de asociación y federación (crea y borra cuentas, entradas y sus intentos): `wp eval-file tests/panel.php`
- Formato de los errores: `node tests/contrato-errores.mjs` y `wp eval-file tests/errores.php` (los dos solo leen)
- Recalcular todas las puntuaciones (al final de una migración): `wp zona-nautico recalcular-puntuaciones`

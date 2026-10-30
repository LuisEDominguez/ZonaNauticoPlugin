# Respuesta a la revisión del plugin Zona Náutico

Lo escribe solo el hilo que desarrolla; es la respuesta a `zonanautico-plugin-revision/REVISION.md`
(que escribe solo el hilo revisor). Un único archivo que se actualiza en cada ronda.

**Para los revisores:** cada punto dice qué se hizo y en qué commit, o por qué sigue pendiente. Con
esto podéis marcar en `REVISION.md` qué queda «arreglado» o «descartado». Ningún cambio se aplica sin
el sí de Luis.

**Qué pruebas lanza cada hilo** (decidido por Luis el 30-10: los revisores solo leen):

| Quién | Pruebas |
|---|---|
| Todas a la vez (hilo que desarrolla) | `node tests/todas.mjs` |
| Revisores (solo lectura, todas las que necesiten) | `node tests/contrato-negocios.mjs`, `contrato-eventos.mjs`, `contrato-sorteos.mjs`, `contrato-resenas.mjs`, `contrato-preguntas.mjs`, `contrato-muestras.mjs`, `contrato-errores.mjs`, `wp eval-file tests/admin-fichas.php` y `tests/errores.php` (no guardan nada; `errores.php` registra rutas de prueba solo en memoria), además de peticiones GET y lectura de código |
| Hilo que desarrolla (pasa el resultado a los revisores) | `wp eval-file tests/seguridad.php`, `tests/comentarios.php`, `tests/roles.php` y `tests/tokens.php` (crean datos o cuentas temporales y los borran) y `node tests/puertas.mjs` (no guarda nada, pero envía una petición a XML-RPC) |

Estados: **hecho** (pendiente de que el revisor lo compruebe) · **pendiente** · **espera decisión** ·
**se propone descartar**.

## Historial

| Ronda | Fecha | Commit revisado | Respuesta en commit | Resumen |
|---|---|---|---|---|
| 1 | 30-10-2026 | `ea9c0bc` (v0.7.0) | `47e5ca8` (v0.8.0) | Bloque 1 (graves y lo urgente de importantes) hecho; el resto, pendiente o a decidir |
| 2 | 30-10-2026 | `47e5ca8` (v0.8.0) | `5156ce5` (v0.8.1) | Bloque 1-bis: R2 (resto), R20 y limpieza de R13 |
| 3 | 30-10-2026 | `5156ce5` (v0.8.1) | `361c7a6` (v0.9.0) | OK del revisor al bloque 1-bis. Fase de login, paso 1: R12. Decisión de Luis sobre R7 |
| 4 | 30-10-2026 | `361c7a6` (v0.9.0) | `df0529c` (v0.9.1) | OK del revisor a R12. Arreglos previos a R22: R31, R33 y R34a |
| 5 | 30-10-2026 | `df0529c` (v0.9.1) | `ce2620a` (v0.10.0) | OK del revisor a R31, R33 y R34a. R22 (roles), sin el token: la librería espera la opinión del revisor y la decisión de Luis |
| 6 | 30-10-2026 | `ce2620a` (v0.10.0) | `3490c65` (v0.10.1) | R35, R36 y R37 (arreglos de roles antes de R32). Token y R40, en espera de Luis |
| 7 | 30-10-2026 | `3490c65` (v0.10.1) | `d8a4e07` (v0.10.2) | R41 y R42 (crear frente a modificar). Decisiones de Luis: R43 A, R44 A; R40 sin decidir |
| 8 | 30-10-2026 | `d8a4e07` (v0.10.2) | `21cabcc` (v0.10.3) | R45 (participantes: también quien organiza el sorteo) |
| 9 | 30-10-2026 | `21cabcc` (v0.10.3) | `2f6f500` (v0.11.0) | Ronda 9 limpia. Luis eligió token opaco: R32 hecho, con `GET /user/{id}` |
| 10 | 30-10-2026 | `2f6f500` (v0.11.0) | `af480bc` (v0.11.1) | R46 (confirmado y arreglado), R47, R48 y R51 |
| 11 | 30-10-2026 | `af480bc` (v0.11.1) | `b877325` (v0.11.2) | R54 (camino de la API sin rest_url) |
| Global | 30-10-2026 | `b877325` (v0.11.2) | ver revisión global (v0.12.0) | Primer bloque: R55, R56, R57, R60, R61, R63, R64 (+R52). Decisiones a-e de Luis |

---

## Revisión global

Revisión del plugin entero pedida por Luis antes del login. Luis dio el sí al primer bloque y aceptó las
cinco recomendaciones de decisión (30-10). El segundo bloque (R58, R59) y el login, más adelante.

### Decisiones de Luis (30-10)

| | Pregunta | Decisión |
|---|---|---|
| a | PATCH (H22): campo vacío | Ausente → no se toca; `null` → se borra; `""` → 422 |
| b | Nombre del autor de reseñas y preguntas | Solo el nombre de pila (lo que ya enseña la app) |
| c | Reseñas nuevas | Se publican al momento, como en el Laravel; se pueden ocultar después desde wp-admin |
| d | Pregunta a un comercio con varios negocios | Sale en todos los negocios de ese comercio (como la app hoy) |
| e | Borrar una cuenta | Se borran sus reseñas y preguntas y se recalculan las puntuaciones, como el Laravel |

### Primer bloque

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R55** Ficheros internos desde la web | hecho | Las pruebas `.php` salen sin hacer nada si no las carga WP-CLI (`tests/roles.php` desde la web: 200 y 0 bytes, sin la ruta del PC). `.htaccess` (Apache) bloquea `.md`, `.mjs`… y `tests/.htaccess` todo. Paquete para subir con `git archive` y `.gitattributes export-ignore`: solo `zona-nautico.php`, `includes/`, `assets/` y `.htaccess` (comprobado). En Local (nginx) los `.md` siguen legibles, pero Local no es público. |
| **R56** Cambiar la contraseña desde la app | hecho | `anular_todos( $cuenta, $menos )`: al cambiar la contraseña (o el correo) se anulan todos los tokens salvo el de la petición en curso si es de esa cuenta (`token_en_uso()`). Sin petición con token (wp-admin, WP-CLI), todos. Pruebas en `tests/tokens.php`. |
| **R52** Cambiar el correo | hecho | `profile_update` también anula si cambia `user_email` (con la misma excepción). |
| **R57** Correos | hecho | `send_password_change_email` y `send_email_change_email` → false para cuentas con rol de la app. `pre_wp_mail`: fuera de producción, solo a `@ejemplo.invalid` (Mailpit); cualquier otro destinatario, descartado. Las altas usarán `wp_insert_user` y los comentarios `wp_insert_comment` (no notifican); queda escrito para el registro. Pruebas en `tests/seguridad.php`. |
| **R60** Registro y rol por defecto | hecho | `users_can_register` siempre 0; `default_role` nunca un rol de la app (no se deja guardar y, si lo fuera, se lee `subscriber`). Con esto, R35 (borrar roles cambiaba el rol por defecto) ya no puede darse; la prueba de R35 pasa a comprobar la sincronización y que las cuentas conservan su rol. |
| **R61** Pruebas | hecho | Las que crean datos (`seguridad`, `comentarios`, `roles`, `tokens`) solo en `WP_ENVIRONMENT_TYPE = local`; contraseñas al azar (ya no `Clave-de-prueba-1`). Orden única `node tests/todas.mjs`, con pausas y resumen. Las de `/login` se escribirán antes que la ruta. |
| **R63** Dueño de novedades y sorteos | hecho | `user_id` en `ZN_Eventos::CAMPOS` y `ZN_Sorteos::CAMPOS`; casilla «Dueño» en sus fichas (`ZN_Admin_Campos::fila_dueno` / `limpiar_dueno`) con cuentas de comercio, asociación, federación o administración de la app (`ZN_Roles::ORGANIZADORES`); `includesUser` devuelve el organizador. Pruebas en `tests/seguridad.php`. |
| **R64** Contraseñas del Laravel | hecho | `Requires at least: 6.8`. Prueba en `tests/tokens.php`: un hash `$2y$10$` escrito tal cual con `$wpdb` vale con `wp_check_password` (buena sí, mala no). El importador deberá copiar el hash sin tocarlo. |
| **R58, R59** | pendiente | Segundo bloque, antes de la primera escritura. Regla del PATCH: decisión a. |
| **R62, R65, R66** | pendiente | Con el registro y la migración; decisiones b-e ya tomadas. |

### Resultado de las pruebas (código de la revisión global, 30-10)

`node tests/todas.mjs`: **358 comprobaciones bien en 14 juegos, todo bien** (`admin-fichas` 38,
`errores` 16, `comentarios` 15, `seguridad` 73, `roles` 90, `tokens` 56, `contrato-negocios` 8,
`contrato-eventos` 10, `contrato-sorteos` 8, `contrato-resenas` 11, `contrato-preguntas` 9,
`contrato-muestras` 6, `contrato-errores` 9, `puertas` 9).

---

## Ronda 11

El revisor dio por buenos R46, R47, R48 y R51 y pidió R54. Luis dio el sí (30-10).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R54** `rest_url()` antes de existir `$wp_rewrite` | hecho | `es_peticion_nuestra()` ya no llama a `rest_url()`: arma el camino con la ruta de `home_url( '/' )` + `rest_get_url_prefix()` + `zonanautico/v1`, y acepta también la variante con `/index.php/` delante. Pruebas: con `$GLOBALS['wp_rewrite'] = null` el token vale y no hay error fatal; la subcarpeta ahora se simula con `home_url` (`/sitio/wp-json/…` y `/sitio/index.php/wp-json/…` sí; `/xxxxxwp-json/…` y `/wp-json/…` fuera de la subcarpeta, no). |
| **R39** Cabecera `Authorization` y `SCRIPT_NAME` en el servidor real | pendiente (al subir) | No se puede probar en Local. Queda en la lista de «al subir al servidor»: comprobar que llega la cabecera y que `SCRIPT_NAME` es `index.php` con su PHP-CGI/FPM. |

### Resultado de las pruebas (código de la ronda 11, 30-10)

En verde: `tokens.php` 49, `roles.php` 87, `errores.php` 16, `seguridad.php` 62, `contrato-errores` 9,
`contrato-negocios` 8 y `puertas.mjs` 9. Solo cambia `ZN_Tokens` y su prueba; el resto, como en la ronda 10.

---

## Ronda 10

El revisor encontró R46 (el token valía fuera de la API con `?rest_route=`) y pidió R46, R47, R48 y R51
antes del login. Luis dio el sí (30-10), también a reproducir R46.

**Reproducción de R46 (en Local, cuenta y token temporales ya borrados):** `POST /wp-admin/admin-ajax.php?rest_route=/zonanautico/v1/`
con `action=heartbeat` y el token respondió `{"rest_nonce":…,"heartbeat_nonce":…,"nonces_expired":true}`:
**la cuenta de la app quedaba conectada en admin-ajax. Confirmado.** Sin `rest_route`, `{"wp-auth-check":false}`.
Tras el arreglo, con `rest_route` también `{"wp-auth-check":false}`.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R46** Token fuera de la API | hecho | `es_peticion_nuestra()` sale si `is_admin()`, `wp_doing_ajax()`, `wp_doing_cron()` o `XMLRPC_REQUEST`, y exige `basename( SCRIPT_NAME ) === 'index.php'`; `?rest_route=` solo vale en la portada o en `/index.php`. Segunda barrera: `rest_pre_dispatch` (prioridad 0) quita la sesión si la cuenta vino del token y la ruta despachada no es `/zonanautico/v1`. Pruebas: el heartbeat por HTTP de verdad con y sin truco; admin-ajax, wp-login y wp-cron con `rest_route`; `rest_route` en otra página; la segunda barrera. |
| **R47** Subcarpeta | hecho | El camino se compara entero con el de `rest_url( ZN_NAMESPACE )` (cubre subcarpeta y `/index.php/wp-json/`), sin recortes. Pruebas con `rest_url` en `/sitio`: vale `/sitio/wp-json/zonanautico/v1/…`; no `/xxxxxwp-json/zonanautico/v1/…` ni `/wp-json/zonanautico/v1/…`. |
| **R48** Tabla antes de mirar el usuario | hecho | `register_activation_hook` y comprobación de versión en `plugins_loaded` (antes de `WP::init`). Tabla versión 2 con `KEY caduca`. |
| **R51** `permission_callback` de `GET /user/{id}` | hecho | `ZN_API::con_sesion_de_la_app()` en la entrada (sesión y cuenta de la app); se mantiene la comprobación de dentro. Para todas las rutas privadas que vengan. |
| R49, R50, R52, R53, RGPD ubicación, pruebas extra | pendiente | Apuntados para producción y el login. Ya hecho de las pruebas extra: restaurar `$_SERVER` y `$_GET` en un `finally` y la subcarpeta. |

**Nota sobre las pruebas:** en la primera tanda el PHP de Local se reinició a mitad (502 «no live
upstreams», como en la ronda 4) y fallaron las peticiones HTTP que quedaban. Las mismas, una a una con
curl, daban bien. `tests/tokens.php` ahora espera 0,3 s entre peticiones y, ante un 502, espera 15 s y
repite (hasta 3 veces).

### Resultado de las pruebas (código de la ronda 10, 30-10)

Todas en verde: `tokens.php` 47, `roles.php` 87, `errores.php` 16, `seguridad.php` 62,
`comentarios.php` 15, `admin-fichas.php` 38, `contrato-errores` 9, `contrato-negocios` 8,
`contrato-eventos` 10, `contrato-sorteos` 8, `contrato-resenas` 11, `contrato-preguntas` 9,
`contrato-muestras` 6 y `puertas.mjs` 9.

---

## Ronda 9

La ronda 9 salió limpia. Los revisores explicaron a Luis cómo funciona el token y Luis eligió el **token
opaco** (30-10). Hecho R32, con lo que de R39 toca al token. El login (`POST /login`, registro,
recuperación: R30, R38 y el resto de R39) **no está empezado**.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Token** | hecho | `includes/class-zn-tokens.php`. `random_bytes(32)` en base64url (43 caracteres). Tabla `{prefijo}zn_tokens (id, hash CHAR(64) UNIQUE, user_id con índice, creado, caduca, anulado)` creada con `dbDelta` una vez por versión de la tabla; solo la huella SHA-256. Caduca a los 60 minutos. Solo para cuentas con un único rol zn_* (`emitir()` da null a una de wp-admin; `validar()` da 0 si la cuenta ya no es de la app). Limpieza de los caducados hace más de un día al emitir. |
| **R32** Validación | hecho | Filtro `determine_current_user` (prioridad 30): solo si la petición va a `/zonanautico/v1` y no hay ya una cuenta por cookie. Token que no vale = sin sesión. Pruebas por **HTTP de verdad** (`tests/tokens.php`): token caducado → `GET /user/{id}` 401 «Unauthenticated.»; token válido de otra cuenta → 401 «This action is unauthorized.»; token caducado o inventado en `GET /business` → 200. |
| **Anular** | hecho | `anular()` (para `/logout`) y `anular_todos()` al cambiar la contraseña (`wp_set_password`, `after_password_reset`, `profile_update` con hash distinto), al cambiar el rol (`set_user_role`, `add_user_role`, `remove_user_role`) y al borrar la cuenta (`delete_user`). Probado cada caso. |
| **R39** (parte del token) | hecho | Solo vale en `/zonanautico/v1` (por `/wp-json/…` o `?rest_route=…`, sin distinguir mayúsculas; no en `/wp/v2`, `admin-ajax.php` ni `/zonanautico/v10`); lee `HTTP_AUTHORIZATION` y `REDIRECT_HTTP_AUTHORIZATION`; regla de `.htaccess` documentada en el README. **Falta probarlo en el servidor de verdad.** |
| **`GET /user/{id}`** | hecho | `includes/class-zn-api-usuarios.php`. La app lo pide al arrancar. `{data: {_id, id (número), name, email, verified, role, parent{_id,id,name,email,role}, avatar, latitude, longitude, phone}}`. Sin sesión → 401 Unauthenticated; sin permiso (`ZN_Roles::puede( 'user:read', $cuenta )`) → 401 unauthorized; cuenta inexistente o de wp-admin → 404. `name` = nombre + apellidos; `verified` saldrá de `zn_verificado` cuando exista la verificación. |
| **R39** (resto) y **R30, R38** | pendiente | Con el login: respuesta de `/login`, cerrar sesiones de WordPress previas, `wp_check_password`, mismo mensaje y tiempo exista o no la cuenta, 429, etc. |
| `/refresh` | pendiente | Hoy la app no lo usa (pide entrar de nuevo a la hora). Se hará con el login. |
| **R40** | espera decisión | Luis lo consultará con Alejandro. |

### Resultado de las pruebas (código de la ronda 9, 30-10)

Todas en verde: `tokens.php` 35, `roles.php` 87, `errores.php` 16, `seguridad.php` 62,
`comentarios.php` 15, `admin-fichas.php` 38, `contrato-errores` 9, `contrato-negocios` 8,
`contrato-eventos` 10, `contrato-sorteos` 8, `contrato-resenas` 11, `contrato-preguntas` 9,
`contrato-muestras` 6 y `puertas.mjs` 9.

---

## Ronda 8

El revisor dio por buenos R41 y R42 y pidió R45. Luis dio el sí (30-10).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R45** El organizador no veía sus participantes | hecho | En `puede()`, sobre un sorteo, `draw_participation:read` y `draw_participation:export` valen para la administración de la app (como siempre) o si la cuenta es la dueña del sorteo (`user_id`) y tiene ese permiso, general o `_own`. Sin herencia por el padre. Pruebas en `tests/roles.php`: comercio organizador lee y exporta (con `export_own`); asociación organizadora lee y exporta; comercio ajeno, usuario, asociación sobre el sorteo de su comercio y federación sobre un sorteo ajeno, no; administración, todos. |
| Participante ve y borra la suya | pendiente | Con la tabla de participaciones (`dueno_de()` reconocerá esas filas). |
| **Token (R32)** y **R40** | espera decisión (Luis) | Sin cambios. |

### Resultado de las pruebas (código de la ronda 8, 30-10)

`roles.php` 87, `seguridad.php` 62 y `errores.php` 16, en verde. Solo cambia `ZN_Roles` y sus pruebas; la
API no cambia, así que no he repetido las pruebas por HTTP (en la ronda 7 estaban todas en verde).

---

## Ronda 7

El revisor dio por buenos R35 y R37 (y la herencia de R36) y pidió R41 y R42. Luis dio el sí (30-10) y
contestó a las decisiones pendientes.

| Punto | Estado | Qué se hizo / decisión |
|---|---|---|
| **R41** El permiso general antes que el dueño | hecho | `puede()` con un elemento: el permiso general solo vale para la administración de la app y para las lecturas públicas (`business/event/draw/review/faq:read`); el resto va por `_own` y el dueño guardado. Así un usuario (`faq:save`) ya no toca preguntas ajenas: **no se copia el fallo del Laravel** («Actualizar FAQ»). Nueva `campos_editables_de_pregunta()`: administración, comercio destinatario y su asociación → `question`, `answer`, `published`; quien preguntó → solo `question` (la ruta deberá dejarla sin publicar); el resto, nada. La prueba de herencia en preguntas ya no puede pasar por el permiso general. |
| **R42** Con `_own` no se podía crear | hecho | Nueva `puede_crear( $permiso, $dueno )`: la administración, a nombre de cualquiera; el resto, a su nombre (con el general o el `_own`) o, con el `_own`, a nombre de una hija (solo asociación y federación, solo negocios, novedades y preguntas). En reseñas, preguntas y participaciones la ruta forzará el dueño a la cuenta de la sesión (H21); queda escrito en la función. |
| `dueno_de()` con tablas propias | pendiente | Al crear participaciones y favoritos. |
| **R43** Quién ve y exporta participantes | **decidido (Luis): A** | Solo la administración de la app y quien organiza ese sorteo. `draw_participation:read` no es lectura pública en `puede()`. |
| **R44** Quién borra su propia cuenta | **decidido (Luis): A** | Como el Laravel: solo el rol `user` desde la app; comercio, asociación y federación lo piden y lo hace la administración. Falta saber el correo para esas peticiones (pregunta abierta a Luis). |
| **R40** Panel para los encargados | espera decisión | Luis no lo sabe; lo consultará con Alejandro. |
| `zn_parent_id` de las asociaciones al migrar | pendiente | Para R23 (migración). |
| **Token (R32)** | espera decisión (Luis) | Sin cambios. |

Pruebas: `tests/roles.php` pasa a 79 comprobaciones (R41: preguntas ajenas, federación, campos
editables, lecturas; R42: crear a su nombre, de una hija, de otro, reseñas, participaciones, preguntas).

### Resultado de las pruebas (código de la ronda 7, 30-10)

Todas en verde: `roles.php` 79, `errores.php` 16, `seguridad.php` 62, `comentarios.php` 15,
`admin-fichas.php` 38, `contrato-errores` 9, `contrato-negocios` 8, `contrato-eventos` 10,
`contrato-sorteos` 8, `contrato-resenas` 11, `contrato-preguntas` 9, `contrato-muestras` 6 y
`puertas.mjs` 9.

---

## Ronda 6

El revisor vio R22 bien para lo que existe hoy y pidió R35-R37 antes de R32. Luis dio el sí (30-10).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R35** Rehacer roles cambiaba el rol por defecto | hecho | `ZN_Roles::actualizar()` ya no borra roles: quita los permisos que sobran y añade los que faltan. Las cuentas se crean con el rol explícito (también `comercio-prueba` en los datos de prueba). Prueba: con `default_role = zn_usuario`, poner al día los roles no lo cambia. |
| **R36** «Cuelga de él» para cualquier `_own` | hecho | `ZN_Roles::puede( $permiso, WP_Post\|WP_Comment\|WP_User\|null, ?$usuario )` saca el dueño del elemento guardado (negocio/novedad/sorteo → `user_id`; reseña → quien la escribió, o el dueño del negocio para `review:reply`; pregunta → su comercio destinatario). Herencia por el padre solo si quien pregunta es asociación o federación y solo en `business`, `event` y `faq`. En cuentas: uno mismo con `user:save_own` / `user:delete_self`; otra cuenta solo con `user:<acción>_<rol de la cuenta destino>` y si cuelga de él (**más estricto que el Laravel**, que también deja tocar cuentas de ese rol sin padre). El padre se guarda en `zn_parent_id`. Se quitó la meta-capacidad `zn_puede`. |
| **R37** Cuentas con varios roles | hecho | `rol_app()` solo da rol si la cuenta tiene exactamente uno y es de la app; los permisos salen de ese rol (no de `allcaps`). Con dos roles de la app, o uno de la app y otro de WordPress: sin rol de la app, sin permisos `zn:` y sin salir en la API; y aun así sin entrar por `wp-login.php` (`tiene_rol_de_la_app()`). |
| **Token (R32)** | espera decisión (Luis) | Vuestra recomendación (token opaco con tabla propia) frente a `firebase/php-jwt`: Luis lo está pensando. R32 no está empezado. |
| **R40** Panel para los encargados | espera decisión (Luis y Alejandro) | Luis lo consultará; queda apuntado. |
| **R38, R39** | pendiente | Requisitos del login; se aplicarán al programarlo. |

Pruebas: `tests/roles.php` ampliado a 57 comprobaciones (R35, herencia por recurso y por rol, reseñas,
respuestas, preguntas, cuentas hijas, varios roles, limpieza elemento a elemento).

### Resultado de las pruebas (código de la ronda 6, 30-10)

Todas en verde: `roles.php` 57, `errores.php` 16, `seguridad.php` 62, `comentarios.php` 15,
`admin-fichas.php` 38, `contrato-errores` 9, `contrato-negocios` 8, `contrato-eventos` 10,
`contrato-sorteos` 8, `contrato-resenas` 11, `contrato-preguntas` 9, `contrato-muestras` 6 y
`puertas.mjs` 9.

---

## Ronda 5

El revisor dio por buenos R31, R33 y R34a y el OK para R22. Luis dio el sí a R22 (30-10). La parte
del token (R32) **no está hecha**: para validarlo propongo la librería `firebase/php-jwt` y Luis quiere
antes vuestra opinión (ver pregunta abajo).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R22** Roles de la app | hecho (sin R32) | `includes/class-zn-roles.php`: 5 roles (`zn_administrador`, `zn_federacion`, `zn_asociacion`, `zn_comercio`, `zn_usuario` → `administrator`, `federation`, `association`, `commerce`, `user`) con los permisos del Anexo A del traspaso como capacidades `zn:<permiso>` (51, 21, 27, 22 y 13) y ninguna de WordPress (ni `read`). Se rehacen enteros en cada versión. `usuario_publico()` ya da el rol de los cinco. |
| **R22** «Lo suyo» | hecho | `map_meta_cap` con la meta-capacidad `zn_puede`: `current_user_can( 'zn_puede', 'business:save', $dueno )` vale con `zn:business:save`, o con `zn:business:save_own` si el dueño es él o cuelga de él (`parent_id`, un nivel, como `EventController`/`FaqController` del Laravel). Nunca por `post_author`. |
| **R22** Permisos de panel de asociación y federación | decisión tomada | Ninguno de WordPress: gestionan desde la app, como hoy (el Laravel no tiene panel). Sus permisos son los del Laravel y se usarán en las rutas de escritura. |
| **R22** Sin wp-login.php para cuentas de la app | hecho | `wp_authenticate_user` las rechaza (antes de mirar la contraseña) y `allow_password_reset` → false para ellas. `login_errors` da el mismo aviso para todo, así wp-login.php tampoco delata si una cuenta existe. |
| **R32** Token por `determine_current_user` | pendiente | Espera la decisión sobre la librería. |
| **R24** Dueño que no es comercio | espera decisión | La casilla «Dueño» sigue ofreciendo solo comercios. |

Pruebas nuevas: `tests/roles.php` (38: los 5 roles y sus permisos exactos, rol en la API, «lo suyo» con
`parent_id`, un nivel, administrador de WordPress sin permisos de la app, wp-login.php y limpieza
cuenta a cuenta).

### Pregunta para los revisores (la pide Luis)

Para R32 propongo validar el token con **`firebase/php-jwt`** (la más usada en PHP para JWT, del mismo
tipo de token que el Laravel actual), copiada dentro del plugin, en vez de programar la firma a mano.
¿Os parece bien, o recomendáis otra opción? Luis decidirá con vuestra respuesta.

### Resultado de las pruebas (código de la ronda 5, 30-10)

Todas en verde: `roles.php` 38, `errores.php` 16, `seguridad.php` 62, `comentarios.php` 15,
`admin-fichas.php` 38, `contrato-errores` 9, `contrato-negocios` 8, `contrato-eventos` 10,
`contrato-sorteos` 8, `contrato-resenas` 11, `contrato-preguntas` 9, `contrato-muestras` 6 y
`puertas.mjs` 9.

---

## Ronda 4

El revisor dio R12 por arreglado y el OK para R22, con tres arreglos antes. Luis dio el sí a los tres
(30-10). R22, con los requisitos R30 y R32, se hará en la ronda siguiente y con su propio sí.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R31** `rest_forbidden_context` siempre «Unauthenticated.» | hecho | Quitado de `DE_SESION` (`class-zn-errores.php`): con sesión → «This action is unauthorized.», sin sesión → «Unauthenticated.», lo decide `is_user_logged_in()`. Pruebas en `tests/errores.php` (con y sin sesión). |
| **R33** Contraseñas de aplicación | hecho | `wp_is_application_passwords_available` → false (`class-zn-puertas.php`). Pruebas: `tests/errores.php` (no disponibles, ni para el administrador) y `tests/puertas.mjs` (una cabecera Basic con una cuenta inventada se ignora: 200 como un visitante). |
| **R34a** `es_nuestra()` distinguía mayúsculas | hecho | Compara en minúsculas, como WordPress casa las rutas. Pruebas en `tests/contrato-errores.mjs` (`/ZonaNautico/V1/business?orderby=foo` → 422 del Laravel) y `tests/errores.php`. |
| **R32, R30** | pendiente | Requisitos de R22 y del login; se cumplirán desde el principio en esos pasos. |
| **R34 b-e** | pendiente | Mejoras para cuando toque. |

### Resultado de las pruebas (código de la ronda 4, 30-10)

Todas en verde: `errores.php` 16, `seguridad.php` 62, `comentarios.php` 15, `admin-fichas.php` 38,
`contrato-errores` 9, `contrato-negocios` 8, `contrato-eventos` 10, `contrato-sorteos` 8,
`contrato-resenas` 11, `contrato-preguntas` 9, `contrato-muestras` 6 y `puertas.mjs` 9.

Nota: en una primera tanda seguida, el PHP del sitio de Local se reinició a mitad (nginx: «no live
upstreams», 502) y fallaron por eso las pruebas por HTTP que quedaban; repetidas una a una, todas
en verde. No es del plugin: pasa con muchas peticiones seguidas en el Local de Windows.

---

## Ronda 3

El revisor dio por arreglado el bloque 1-bis y el OK para la fase de usuarios y login, en el orden
R12 → R22, con R7 decidido antes de crear usuarios. Luis dio el sí al paso 1 (R12) y decidió R7 (30-10).
R22 se hará en la ronda siguiente, por separado.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R12** Errores con formato del Laravel | hecho | `includes/class-zn-errores.php`. Filtro `rest_post_dispatch` solo para `/zonanautico/v1`: `rest_invalid_param` / `rest_missing_callback_param` → `422 {message, errors: {campo: [...]}}` con «(and N more error[s])»; sin sesión (o sesión no válida) → `401 {"message":"Unauthenticated."}`; con sesión y sin permiso → `401 {"message":"This action is unauthorized."}` (la app solo cierra la sesión con el primero, `cliente.ts:249`); ruta que no existe → `404 «The route … could not be found.»`; método no admitido → `405 «The X method is not supported for route …. Supported methods: …»` (antes 404); `500` → `{"message":"Server Error"}` sin detalles. Las respuestas que ya tenían el formato no se tocan, y fuera de nuestra ruta tampoco. Helpers `no_valido()`, `no_autenticado()` y `sin_permiso()` para las escrituras. Las pruebas que daban por buenos 400 y 404 ahora esperan 422 y 405. Pruebas nuevas: `tests/contrato-errores.mjs` y `tests/errores.php` (los 401 se prueban con rutas temporales en memoria, porque aún no hay rutas privadas). |
| **R7** Ids del Laravel | decidido (Luis, 30-10) | Sí a `laravel_id` en cada elemento migrado, **usuarios incluidos**, y traducción de enlaces al migrar. Se implementa con la migración (y en usuarios, al crearlos en R22). |
| **R18** Filtro con id no numérico | — | Con R12 ya no es 400 sino 422, como en el Laravel. |
| **R22** Diseño de roles | pendiente | Siguiente paso, en su propia ronda y con el sí de Luis. |

### Resultado de las pruebas (código de la ronda 3, 30-10)

Todas en verde: `errores.php` 11, `seguridad.php` 62, `comentarios.php` 15, `admin-fichas.php` 38,
`contrato-errores` 8, `contrato-negocios` 8, `contrato-eventos` 10, `contrato-sorteos` 8,
`contrato-resenas` 11, `contrato-preguntas` 9, `contrato-muestras` 6 y `puertas.mjs` 8.

---

## Ronda 2

Luis dio el sí al bloque 1-bis y a borrar el contenido de ejemplo de WordPress (30-10).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R2** (resto) | hecho | `class-zn-puertas.php`: mapa del sitio apagado (`wp_sitemaps_enabled`); oEmbed fuera (ruta `/oembed/1.0/*`, enlaces de descubrimiento y vista `?embed=true` → portada); feeds de entradas y comentarios → 301 a la portada y sin sus enlaces en `<head>`. En Local, la entrada «Hello world!» (1) y la página «Sample Page» (2) están en la **papelera** (no borradas del todo, por si hiciera falta recuperarlas). Pruebas en `tests/puertas.mjs` (mapa, feeds, oEmbed) y `tests/seguridad.php`. |
| **R20** `texto_plano` cortaba reseñas y preguntas | hecho | `texto_plano()` solo para descripciones del editor (negocio, novedad, sorteo). Título, texto y respuesta de reseñas y pregunta y respuesta de FAQ usan `ZN_API::texto_escrito()`: solo decodifica entidades y recorta espacios. Pruebas con «me encantó <3 volveré» y «menores <12 gratis y >65» en `tests/seguridad.php`. |
| **R13** (limpieza) | hecho | La comprobación final de `tests/seguridad.php` mira cada id creado (entradas, comentarios y las 2 cuentas) con `get_post`, `get_comment` y `get_userdata`. Resultado: «borrados 12 entradas, 8 comentarios y 2 cuentas temporales». |
| **R13** (resto de la ronda 2: tipos en `contrato-muestras`, `/review` y `/faq` sin muestra, borrar puntuación, comando `recalcular-puntuaciones`, edición real) | pendiente | Fuera del bloque 1-bis que se pidió; queda apuntado. |
| **R12, R22** | pendiente | Primeros pasos de la fase de login, como pedís. |
| **R7, R24** | espera decisión | — |
| **R25-R29** | pendiente | Mejoras; sin tocar en esta ronda. |

### Resultado de las pruebas (código de la ronda 2, 30-10)

Todas en verde: `seguridad.php` 62, `comentarios.php` 15, `admin-fichas.php` 38, `contrato-negocios` 8,
`contrato-eventos` 10, `contrato-sorteos` 8, `contrato-resenas` 11, `contrato-preguntas` 9,
`contrato-muestras` 6 y `puertas.mjs` 8.

---

## Ronda 1

### Pruebas que pidieron los revisores (hechas en Local; datos temporales borrados)

| Prueba | Resultado |
|---|---|
| R11: negocio con contraseña → `GET /business/{id}` y `/business` | **Confirmado**: salía en los dos (200) |
| R19: reseña sin meta y después `add_comment_meta score=5` | **Confirmado**: la nota se quedaba en 1.5 (debía ser 4) |
| R3: usuario Autor de prueba | **Confirmado**: creaba y publicaba negocios y editaba los suyos (no los ajenos); `ZN_Admin_Eventos::limpiar` aceptaba un negocio ajeno |

### Resultado de las pruebas que lanza el hilo que desarrolla (código de `47e5ca8`, 30-10)

- `tests/seguridad.php`: 56 comprobaciones, todas bien; limpieza comprobada (no queda nada temporal).
- `tests/comentarios.php`: 15 comprobaciones, todas bien.
- `tests/puertas.mjs`: 5 de 5 bien (`/wp/v2/users`, `/wp/v2/media` y `/wp/v2/comments` → 404; `?author=1` → portada; XML-RPC → 403).

### Estado de cada punto

| Punto | Estado | Qué se hizo / por qué sigue así |
|---|---|---|
| **R1** Dueño del negocio | hecho (`47e5ca8`) | Dueño = campo `user_id` del negocio, elegido en la ficha («Dueño») solo entre cuentas con el rol nuevo `zn_comercio` («Comercio (app)», sin ningún permiso, ni `read`). `usuario_publico()` solo devuelve cuentas con rol de la app: un administrador nunca sale, aunque se fuerce. Prueba en `tests/seguridad.php`. |
| **R2** Puertas de WordPress | hecho (`47e5ca8`) | `includes/class-zn-puertas.php`: XML-RPC fuera (`xmlrpc_enabled`, `xmlrpc_methods` vacío y 403 en `xmlrpc.php`); `?author=` y páginas de autor → 301 a la portada; `/wp/v2/users` y `/wp/v2/media` quitadas salvo para quien tenga `list_users` / `upload_files`. Pruebas en `tests/puertas.mjs` (desde fuera) y `tests/seguridad.php`. |
| **R3** Permisos genéricos | hecho (`47e5ca8`) | `capability_type` propio (`zn_negocio(s)`, `zn_evento(s)`, `zn_sorteo(s)`) con `map_meta_cap`; solo el administrador los recibe (`includes/class-zn-roles.php`, una vez por versión). `auth_callback` de los metas con `edit_post` sobre la entrada. Al guardar una novedad se exige `edit_post` sobre el negocio elegido. Pruebas en `tests/seguridad.php` y `tests/admin-fichas.php`. |
| **R4** Fotos como URL | pendiente | De acuerdo. Se hará antes de migrar. Pregunta abierta para Alejandro: ¿se suben las 3.949 fotos a Medios o se mantiene `/storage/` en el mismo dominio? |
| **R5** Hora de las reseñas | hecho (`47e5ca8`) | `datetime` sale de `comment_date_gmt` convertido a Europe/Madrid (`ZN_API::hora_madrid`), sin depender de la zona del sitio. Datos de prueba guardan también la hora UTC. Prueba de verano/invierno en `tests/seguridad.php`. |
| **R6** HTML en descripciones | hecho (`47e5ca8`) | `ZN_API::texto_plano()` en descripciones de negocio, novedad y sorteo y en los textos de reseñas y preguntas: quita etiquetas (y el contenido de `<script>`), decodifica entidades, `&nbsp;` → espacio, conserva saltos. Se mantiene el editor visual. Prueba en `tests/seguridad.php`. |
| **R7** Ids del Laravel | espera decisión (Luis) | De acuerdo con la propuesta: `laravel_id` en cada elemento migrado y traducción de enlaces al migrar. |
| **R8** Destinatario de la pregunta | hecho (`47e5ca8`) | La pregunta guarda su comercio destinatario (`user_id` del comentario); `?user=` filtra por ese campo. Cambiar el dueño del negocio no arrastra sus preguntas. Prueba en `tests/seguridad.php`. |
| **R9** Categorías en el código | pendiente | De acuerdo (taxonomías con id del Laravel y orden). Más trabajo; después del bloque 1. |
| **R10** Nombre público | hecho (`47e5ca8`) | `name` = nombre + apellidos de la cuenta; si no hay, `null`. Nunca `display_name`, `user_login` ni el correo. Prueba en `tests/seguridad.php`. |
| **R11** Entradas con contraseña | hecho (`47e5ca8`) | `has_password => false` en los listados; `entrada()` exige contraseña vacía; reseñas y preguntas solo de negocios visibles (`ZN_Negocios::visibles()`). También se prueban borrador, privado, programado y papelera. |
| **R12** Formato de errores | pendiente | De acuerdo, con el matiz del revisor: el login ya es una escritura (email y contraseña) y la app espera `401 {"message":"Unauthenticated."}` y `422 {message, errors}`. Por eso R12, con sus pruebas de contrato de errores, es **lo primero de la fase de usuarios y login, antes de programar el propio login**. |
| **R13** Pruebas | hecho (`47e5ca8`) | Nuevas: `tests/seguridad.php` (crea y borra sus propios datos), `tests/puertas.mjs` y `tests/contrato-muestras.mjs` (compara campos y orden con `Zona-Nautico/api-muestras`). Lo de cubrir la edición real de reseñas queda para cuando exista la caja de moderación. |
| **R14** `meta.links` | pendiente | Prioridad mínima; para el final. |
| **R15** Novedades de un negocio en la papelera | hecho (`47e5ca8`) | Las novedades con `business_id` de un negocio no visible no salen ni en el listado ni en la ficha (404). Falta el aviso en wp-admin. Prueba en `tests/seguridad.php`. |
| **R16** Ficha de wp-admin | pendiente | Luis decidió dejarlo para más adelante (30-10): no es de seguridad. |
| **R17** «Noticia» | espera decisión (Alejandro / Luis) | — |
| **R18** Filtro con id no numérico | se propone descartar | De acuerdo con el revisor; lo confirma Luis. |
| **R19** Recalcular puntuaciones | hecho (`47e5ca8`) | Se recalcula también al añadir, cambiar o borrar el meta `score`, y hay comando `wp zona-nautico recalcular-puntuaciones`. Prueba en `tests/seguridad.php`. |

### Notas para la fase de usuarios y login

Recibidas y apuntadas; se aplicarán en esa fase. El rol `commerce` ya existe para los dueños (`zn_comercio`).

### Cambios en los datos de prueba de Local

- «CartasPokeLuis» queda sin dueño (antes salía la cuenta del administrador).
- La cuenta inventada `comercio-prueba` pasa al rol «Comercio (app)», con nombre «Comercio Prueba», y es la dueña de la Taberna. Sus preguntas llevan ese destinatario.

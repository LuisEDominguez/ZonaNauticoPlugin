# Respuesta a la revisión del plugin Zona Náutico

Lo escribe solo el hilo que desarrolla; es la respuesta a `zonanautico-plugin-revision/REVISION.md`
(que escribe solo el hilo revisor). Un único archivo que se actualiza en cada ronda.

**Para los revisores:** cada punto dice qué se hizo y en qué commit, o por qué sigue pendiente. Con
esto podéis marcar en `REVISION.md` qué queda «arreglado» o «descartado». Ningún cambio se aplica sin
el sí de Luis.

**Qué pruebas lanza cada hilo** (decidido por Luis el 30-10: los revisores solo leen):

| Quién | Pruebas |
|---|---|
| Revisores (solo lectura, todas las que necesiten) | `node tests/contrato-negocios.mjs`, `contrato-eventos.mjs`, `contrato-sorteos.mjs`, `contrato-resenas.mjs`, `contrato-preguntas.mjs`, `contrato-muestras.mjs`, `contrato-errores.mjs`, `wp eval-file tests/admin-fichas.php` y `tests/errores.php` (no guardan nada; `errores.php` registra rutas de prueba solo en memoria), además de peticiones GET y lectura de código |
| Hilo que desarrolla (pasa el resultado a los revisores) | `wp eval-file tests/seguridad.php`, `tests/comentarios.php` y `tests/roles.php` (crean datos o cuentas temporales y los borran) y `node tests/puertas.mjs` (no guarda nada, pero envía una petición a XML-RPC) |

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

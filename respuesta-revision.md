# Respuesta a la revisión del plugin Zona Náutico

Lo escribe solo el hilo que desarrolla; es la respuesta a `zonanautico-plugin-revision/REVISION.md`
(que escribe solo el hilo revisor). Un único archivo que se actualiza en cada ronda.

**Para los revisores:** cada punto dice qué se hizo y en qué commit, o por qué sigue pendiente. Con
esto podéis marcar en `REVISION.md` qué queda «arreglado» o «descartado». Ningún cambio se aplica sin
el sí de Luis.

**Qué pruebas lanza cada hilo** (decidido por Luis el 30-10: los revisores solo leen):

| Quién | Pruebas |
|---|---|
| Revisores (solo lectura, todas las que necesiten) | `node tests/contrato-negocios.mjs`, `contrato-eventos.mjs`, `contrato-sorteos.mjs`, `contrato-resenas.mjs`, `contrato-preguntas.mjs`, `contrato-muestras.mjs` y `wp eval-file tests/admin-fichas.php` (no guarda nada), además de peticiones GET y lectura de código |
| Hilo que desarrolla (pasa el resultado a los revisores) | `wp eval-file tests/seguridad.php` y `tests/comentarios.php` (crean datos temporales y los borran) y `node tests/puertas.mjs` (no guarda nada, pero envía una petición a XML-RPC) |

Estados: **hecho** (pendiente de que el revisor lo compruebe) · **pendiente** · **espera decisión** ·
**se propone descartar**.

## Historial

| Ronda | Fecha | Commit revisado | Respuesta en commit | Resumen |
|---|---|---|---|---|
| 1 | 30-10-2026 | `ea9c0bc` (v0.7.0) | `47e5ca8` (v0.8.0) | Bloque 1 (graves y lo urgente de importantes) hecho; el resto, pendiente o a decidir |
| 2 | 30-10-2026 | `47e5ca8` (v0.8.0) | ver ronda 2 (v0.8.1) | Bloque 1-bis: R2 (resto), R20 y limpieza de R13 |

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

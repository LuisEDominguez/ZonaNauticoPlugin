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
| Revisores (solo lectura, todas las que necesiten) | `node tests/contrato-negocios.mjs`, `contrato-eventos.mjs`, `contrato-sorteos.mjs`, `contrato-resenas.mjs`, `contrato-preguntas.mjs`, `contrato-muestras.mjs`, `contrato-errores.mjs`, `wp eval-file tests/admin-fichas.php`, `tests/esquema.php` y `tests/errores.php` (no guardan nada; `errores.php` registra rutas de prueba solo en memoria), además de peticiones GET y lectura de código |
| Hilo que desarrolla (pasa el resultado a los revisores) | `wp eval-file tests/seguridad.php`, `tests/comentarios.php`, `tests/roles.php`, `tests/tokens.php`, `tests/limite.php`, `tests/escrituras.php`, `tests/login.php`, `tests/registro.php`, `tests/opiniones.php` y `tests/privacidad.php` (crean datos, cuentas o filas temporales y los borran) y `node tests/puertas.mjs` (no guarda nada, pero envía una petición a XML-RPC) |

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
| Global | 30-10-2026 | `b877325` (v0.11.2) | `df0b1b5` (v0.12.0) | Primer bloque: R55, R56, R57, R60, R61, R63, R64 (+R52). Decisiones a-e de Luis |
| Global-bis | 30-10-2026 | `df0b1b5` (v0.12.0) | `7bf450f` (v0.12.1) | R57-bis, R70, R71. Decisión R69: A |
| Segundo bloque | 02-11-2026 | `7bf450f` (v0.12.1) | `100e42b` (v0.13.0) | R58 (esquema y validación comunes) y R59 (límite de intentos, tope de tokens). Decisión R40 de Luis |
| Segundo bloque-bis | 02-11-2026 | `100e42b` (v0.13.0) | `494dfd9` (v0.13.1) | R75, R76, R77 (límite), R79, R80, R81 (esquema) y robustez de las pruebas. R78, al subir |
| Segundo bloque-ter | 02-11-2026 | `494dfd9` (v0.13.1) | `f054082` (v0.13.2) | R82 (editar comentarios sin kses), R83 (IPv4 escritas como IPv6), borrador por defecto |
| Login A | 02-11-2026 | `bdca3f1` (v0.13.2) | `524ab78` (v0.14.0) | `POST /login`, `/refresh`, `/logout` (R30, R38, R39) y línea de R78 en el README |
| Login A-bis | 02-11-2026 | `524ab78` (v0.14.0) | `a7fd98e` (v0.14.1) | R84, R85, R86 (14 días) y R87 |
| Login B | 02-11-2026 | `a7fd98e` (v0.14.1) | `f1722fd` (v0.15.0) | Registro, verificación y recuperación (R30, R53, R62) |
| Login B-bis | 02-11-2026 | `f1722fd` (v0.15.0) | `daa57ab` (v0.15.1) | R88, R89, R91, R92; R90 aceptado |
| Escrituras y RGPD | 02-11-2026 | `daa57ab` (v0.15.1) | `3e598f5` (v0.16.0) | Reseñas y preguntas desde la app, R65 y el 405 de `/verify` |
| Escrituras-bis | 02-11-2026 | `3e598f5` (v0.16.0) | `5f1352e` (v0.16.1) | R93, R94, R95 = A |
| Novedades y sorteos | 02-11-2026 | `5f1352e` (v0.16.1) | `077f02a` (v0.17.0) | Escrituras de novedades y sorteos desde la app y R96 |

---

## Escrituras de novedades y sorteos (y R96)

Luis dio el sí (02-11, 10:55). Diff de la ronda: `git diff 3131936 077f02a`. Pruebas escritas antes
(`tests/novedades-sorteos.php` y lo nuevo de `tests/privacidad.php` fallaban). **Un solo revisor, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **`POST /event`, `POST /draw`** | hecho | `includes/class-zn-api-entradas.php` (las dos con el mismo código). `ZN_Esquema::validar()` (obligatorios del Laravel: novedad `name`, `userId`; sorteo `title`, `userId`, `startDate`, `endDate`, `winners` ≥ 1; fechas `Y-m-d H:i:s`) y `permitir_dueno()` (R79, R70): con `_own`, a su nombre; en novedades, la asociación o federación también a nombre de su comercio; si no, 401 (H15). Primero 422, después 401. Publicada al momento y **sin autor de WordPress (`post_author` 0)**: el dueño es `user_id`, y así `wp_delete_user()` no la borra por detrás. `201 {data}` con el mismo formato que el GET. |
| **`PUT\|PATCH /event/{id}`, `/draw/{id}`** | hecho | `puede( '<recurso>:save', entrada )`: el dueño guardado; en novedades, también su asociación o federación (como el Laravel); en sorteos, sin herencia; la administración de la app, todo. Entrada de ese tipo y no en la papelera (un borrador sí); si no, 404. Regla del PATCH; el dueño solo lo cambia la administración (R79) y con R70. **H22:** un PATCH sin `legalUrl` ya no lo borra. `200 {data}`. |
| **`DELETE /event/{id}`, `/draw/{id}`** | hecho | `puede( '<recurso>:delete', entrada )`. Borrado definitivo. `200 {status: "success"}` (el cuerpo del Laravel no está en los documentos; la app aún no lo usa). |
| **Fechas en un PATCH** | hecho | `ZN_Esquema::validar()`: antes solo miraba el fin cuando llegaba el fin. Ahora, si llega el inicio solo y queda después del fin guardado, 422 en `startDate` («before or equal to end date»). Texto nuevo `before_or_equal` (y su aviso para wp-admin). |
| **R94** | hecho | Crear y modificar novedades y sorteos cuentan para las 20 escrituras por hora y cuenta. |
| **R96** | hecho (regla A) | `delete_user`: los negocios, novedades y sorteos de la cuenta pasan a borrador y sin dueño; los que estén en la papelera siguen allí, también sin dueño. |
| Fechas de Adalo | apuntado | El Laravel también aceptaba fechas numéricas de Adalo (días desde 1899). La app nueva manda `Y-m-d H:i:s`; no se copia. |

### Resultado de las pruebas (novedades y sorteos, 02-11)

`node tests/todas.mjs`: **819 comprobaciones bien en 22 juegos** (`novedades-sorteos` 58 nueva, `privacidad` 29). `contrato-eventos` y `contrato-sorteos` comprobaban «POST da 405» (solo lectura): ahora «POST sin token → 401 Unauthenticated.» y «PUT en la lista → 405»; esas dos se repitieron solas tras corregirlas (11 y 9 bien).

## Escrituras-bis (R93–R95)

El revisor dio por bueno el bloque (ronda 19). Luis dio el sí a R93 y R94 y eligió **A** en R95 (02-11, 10:44).
Diff de la ronda: `git diff 095bc2d 5f1352e`. Pruebas escritas antes (fallaban).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R93** Dos reseñas a la vez | hecho | Tras guardar, `POST /review` vuelve a mirar las reseñas de esa persona en ese negocio (`suyas()`, por id): si la nueva no es la primera, la borra y responde el mismo 422. Prueba: `comments_pre_query` simula que la otra petición guarda la suya justo después de la comprobación → 422 y queda solo la primera. |
| **R94** Límite de escrituras | hecho | Regla `ZN_Limite::REGLAS['escritura']` = 20 por hora y **cuenta** (`ZN_API::limite_de_escritura()`, con el correo de la sesión). En `POST /review`, `PATCH /review/reply`, `POST /faq` y `PATCH /faq`, antes de validar (las que no valen también cuentan); en las dos `PATCH`, después de ver que existe y que hay permiso. Borrar no cuenta. Al pasarse, 429 «Too Many Attempts.». Prueba: 20 seguidas pasan, la 21.ª (de otro tipo) da 429 y no guarda; otra cuenta sigue. |
| **R95** Negocios de un comercio borrado | hecho (Luis: A) | `delete_user`: sus negocios (campo `user_id`, cualquier estado) pasan a borrador y se les quita el dueño; sus reseñas se quedan. Comentario de `class-zn-privacidad.php` corregido. Novedades y sorteos de esa cuenta, sin tocar (no entraba). |
| App: «ya has opinado» | apuntado | A Luis le parece bien el texto «Ya has opinado sobre este negocio». Es un cambio en la app (`errores.ts`): lo hacen Luis y Alejandro. |

### Resultado de las pruebas (escrituras-bis, 02-11)

`node tests/todas.mjs`: **757 comprobaciones bien en 21 juegos, todo bien** (`opiniones` 78, `privacidad` 27).

## Escrituras de reseñas y preguntas, y RGPD (R65)

Luis dio el sí a este bloque (02-11, 10:24). Diff de la ronda: `git diff 3e7b762 3e598f5`. Pruebas escritas antes que el código (`tests/opiniones.php` y `tests/privacidad.php` fallaban antes).
**Un solo revisor, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **`POST /review`** | hecho | `ZN_API_Resenas::crear()`. Valida con `ZN_Esquema` (negocio publicado, nota 1–5, texto y título); `response`, `userId` y `datetime` se quitan antes (la respuesta solo por `/review/reply`; el autor es la cuenta del token, H21; la fecha, del servidor). `puede_crear( 'review:save', autor )`. **H13 (Luis, 02-11: A): una reseña por persona y negocio**, también si la suya está oculta → 422 «The business id has already been taken.»; tras borrarla puede escribir otra. Publicada al momento (decisión c). `201 {data: reseña}` (el formato de la respuesta del Laravel no está en los documentos; la app no la lee). |
| **`DELETE /review/{id}`** | hecho | `puede( 'review:delete', reseña )`: la autora (`_own`) o la administración de la app; ni el dueño del negocio ni la asociación (como el Laravel). Vale también para una reseña oculta desde wp-admin. Borrado definitivo; la nota se recalcula sola. `200 {status: "success"}`. |
| **`PATCH /review/reply/{id}`** | hecho | `puede( 'review:reply', reseña )`: el dueño del negocio (sin herencia por el padre, como el Laravel) o la administración de la app. Solo `response` (campo nuevo del esquema de reseñas, máx. 2000); `null` la borra y `""` → 422 (regla del PATCH; el Laravel no dejaba borrarla). `200 {data}`. |
| **`POST /faq`** | hecho | `ZN_API_Preguntas::crear()`. Solo `userId` y `question` (lo demás se ignora). Si `userId` no es un comercio o no tiene ningún negocio publicado → 422 en `userId` (el Laravel daba 401 si no era comercio; propuesta del revisor). Cuelga del primer negocio publicado del comercio (sale en todos, decisión d). Quien pregunta = la cuenta del token; sin publicar y sin respuesta. `201 {data}`. |
| **`PATCH /faq/{id}`** | hecho | `campos_editables_de_pregunta()` (R41): comercio, su asociación y la administración de la app cambian `question`, `answer` y `published`; quien preguntó, solo `question`, y vuelve a no publicada. El resto (también `userId`, H15) se ignora. `published` `"true"` (texto) → 422, como el Laravel. |
| **R65** IP y navegador | hecho | `includes/class-zn-privacidad.php`: en `wp_insert_comment` (acción, porque esa función no tiene filtro previo) y en `wp_update_comment_data` se vacían `comment_author_IP`, `comment_agent` y `comment_author_email` de reseñas y preguntas, vengan de donde vengan. Los demás comentarios no se tocan. |
| **R65** Borrar la cuenta | hecho | `delete_user` (y `wpmu_delete_user`): borra del todo sus reseñas y preguntas (cualquier estado) y las preguntas dirigidas a ella si es un comercio (cascada del Laravel). Las puntuaciones se recalculan solas al borrar cada reseña. Las reseñas de los negocios de un comercio siguen mientras el negocio exista. Participaciones y favoritos, cuando existan. |
| **R65** Herramientas de privacidad | hecho | Exportador y borrador «App Zona Náutico». Exporta la cuenta (nombre, correo, teléfono, ubicación, rol, verificado), sus reseñas y sus preguntas (nada de huellas, tokens ni códigos). Borrar quita sus reseñas y preguntas (de 100 en 100), el teléfono y la ubicación, y avisa de que la cuenta sigue (se borra en Usuarios). |
| **405 en `/verify`** | hecho | `pagina_de_verificacion()` solo pinta la página con GET y POST; con `PUT`, el 405 sale en JSON como en las demás rutas. Prueba por HTTP en `tests/registro.php`. |
| Pieza común | hecho | `ZN_API::cuerpo()` (cuerpo JSON o formulario; la dirección no cuenta), que usa también `ZN_API_Cuenta`. |

**Apuntado (no se ha hecho):**
- `DELETE /faq/{id}` no estaba en el bloque.
- Límite de escrituras por cuenta (avalancha de reseñas o preguntas): no está en el Laravel; con su sí.
- La app solo usa hoy `POST /review` y `POST /faq`; borrar y responder desde la app son pantallas de Luis y Alejandro.

### Resultado de las pruebas (escrituras y RGPD, 02-11)

`node tests/todas.mjs`: **750 comprobaciones bien en 21 juegos** (`opiniones` 73 y `privacidad` 25 nuevas; `registro` 77). `contrato-resenas` y `contrato-preguntas` comprobaban «POST da 405» (solo lectura): ahora comprueban «POST sin token → 401 Unauthenticated.» y «PUT → 405»; esas dos se repitieron solas tras corregirlas (12 y 10 bien).

## Login B-bis (R88–R92)

El revisor dio por bueno el login B y las dos decisiones (send-email siempre 200; verificar al cambiar la
contraseña con el código). Luis dio el sí a R88, R89, R91 y R92, y a aceptar R90 por escrito (02-11, 10:08).
Diff de la ronda: `git diff f1722fd daa57ab`.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R88** Escáner de enlaces | hecho | `/verify` admite GET y POST. **GET no verifica**: con un token que vale pinta la página «Confirma tu correo» con el botón «Confirmar mi correo» (formulario POST a `/verify` con `id` y `token` ocultos, escapados); con `&format=json`, `200 {status: "pending"}`. **POST** verifica (página «Cuenta verificada» o `{status: "success"}`). Token que no vale, en los dos, 400. Pruebas por dentro y por HTTP: abrir el enlace dos veces no verifica; el POST del formulario sí; repetirlo, 400. |
| **R89** Fallos a la vez | hecho | `ZN_Limite::REGLAS['codigo']['cuenta']` = 10 por hora (antes 20). Prueba en `tests/registro.php`. |
| **R90** El tiempo delata la cuenta | aceptado (Luis) | Escrito en la cabecera de `class-zn-api-cuenta.php`: el registro ya lo dice con su 422, que la app necesita. |
| **R91** Ubicación en el alta | hecho | `/register` quita `latitude` y `longitude` antes de validar y no las guarda. Prueba: se mandan y no quedan. |
| **R92** Comentario | hecho | La cabecera dice ahora que el registro mira primero el límite y por qué. |

**Veredicto del revisor (ronda 18):** R88, R89, R91, R92 arreglados y R90 aceptado, sin fallos nuevos. Detalle
del 405 en `/verify` (con PUT sale la página HTML en vez de JSON): apuntado para el siguiente bloque (Luis lo deja a
mi criterio, 02-11 10:19).

### Resultado de las pruebas (login B-bis, 02-11)

`node tests/todas.mjs`: **649 comprobaciones bien en 19 juegos, todo bien** (`registro` 76).

## Login, parte B (registro, verificación y recuperación)

Luis dio el sí (02-11, 09:30). Diff de la ronda: `git diff ad8d3d3 f1722fd` (desde la última respuesta; el código
anterior era `a7fd98e`). Ronda delicada: revisor principal y, como mucho, un ayudante de seguridad.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **`POST /register`** | hecho | `includes/class-zn-api-cuenta.php`. Primero `ZN_Limite::intentar( 'registro' )` (por IP), luego `ZN_Esquema::validar( 'cuenta', … )` (sin `code`). `201 {status: "success"}`. Rol siempre `zn_usuario` (lo que mande la app en `role`/`roleId` se ignora; las altas de otros roles con token, con las escrituras). `user_login` al azar (`zn` + 16 hex), ni correo ni nombre; `display_name` y `nickname`, el mismo. Teléfono y ubicación como metas. Solo el cuerpo (H8). |
| **R30** Email repetido | hecho | 422 «The email has already been taken.» (la regla `unique` del esquema, sin distinguir mayúsculas). |
| **R30** Red de seguridad | hecho | `ZN_Errores::traducir()`: `existing_user_email` → 422 con ese mismo texto en `email`; `DE_CUENTAS` (otros errores de `wp_insert_user` y `wp_authenticate`) → 422. Antes salían como 500. |
| **R53** Nombre | hecho | Nombre entero (recortado) en `first_name`, apellidos vacíos; en blanco → 422. |
| **R57** Alta | hecho | `wp_insert_user()` con rol explícito; no manda avisos de WordPress. |
| **R62 / H16** Verificación | hecho | Token al azar de 32 bytes (base64url); se guarda solo su SHA-256 en `zn_verificar`, con `zn_verificar_caduca` a 48 horas; vale una vez. `GET /verify?id=&token=`: página HTML sencilla «Cuenta verificada» / «Este enlace no vale» (filtro `rest_pre_serve_request`, sin datos de la cuenta, `no-store`, `noindex`); con `&format=json`, `200 {status: "success"}` o `400 {status: "error"}`. |
| **H17** Reenvío | hecho | `GET /send-email?email=`: 422 si el email está mal escrito; si no, `intentar( 'reenviar' )` y **siempre 200 `{status: "success"}`**; solo manda correo a una cuenta de la app sin verificar (el token nuevo anula el anterior). El Laravel daba 400 si no existía o ya estaba verificada; la app trata 200 y 400 igual (`auth.ts:56-62`) y así no se delata nada. |
| **H24** Código | hecho | `POST /reset-password-code {email}`: siempre 200 `{status: "success"}`, sin Bearer compartido y sin el código en la respuesta. A una cuenta de la app: código `random_int` de 6 cifras por correo, guardado como HMAC con `wp_salt` (`id|código`), 15 minutos, fallos a cero. |
| **R30 / H24** Cambio | hecho | `POST /reset-password {email, code, password}`: formato del email y de la contraseña (8–255) → 422; `intentar( 'codigo' )` → 429; código que no vale (mal escrito, ausente, caducado, gastado, de otra cuenta o de una que no existe) → **400** `{status: "error", message: "Invalid code"}`. Cada fallo suma; al 5.º el código se borra (aunque los fallos vengan de IPs distintas). Bien: `wp_set_password()` (cierra todas las sesiones, R32), borra el código, **deja el correo verificado** (quien tiene el código lo recibió en ese correo) y `olvidar()`. |
| **R61** Pruebas | hecho | `tests/registro.php` (68), escrita antes que las rutas: por dentro (pasando por `rest_post_dispatch`, como al servir) y por HTTP el enlace del correo. Los correos se recogen en `pre_wp_mail` y no salen. |

**Apuntado:**
- Dar de alta comercios, asociaciones… con token (`user:register-<rol>`, con `parent_id`): con las escrituras de cuentas.
- `avatar` (multipart) en el alta: la app no lo manda; con las fotos.
- Que una cuenta exista se puede saber por el registro (lo exige la app, «email ya registrado») y por el tiempo que tarda mandar un correo en `send-email`/`reset-password-code`. Aceptado como en R85.
- Los textos de los correos y de la página son provisionales; los puede cambiar Luis.

### Resultado de las pruebas (login B, 02-11)

`node tests/todas.mjs`: **641 comprobaciones bien en 19 juegos, todo bien** (`registro` 68 nueva).

## Login A-bis (R84–R87)

Luis dio el sí a R84–R87 y a los 14 días de R86, y el permiso al revisor para pasar las rondas cerradas a otro
archivo (02-11, 08:42). Diff de la ronda: `git diff 524ab78 a7fd98e`.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R84** Bloquear a otra persona desde una IP | hecho | `ZN_Limite::intentar()` cuenta primero cuenta + IP e IP, y la regla por cuenta sola la última: si ya está frenado, en ella solo se lee (`leer()`), no se suma. `claves()` sigue igual por fuera (`claves_por_tipo()` por dentro). Prueba en `tests/limite.php`: 35 intentos desde una IP, esa IP sigue parada y la dueña entra desde otra. |
| **R85** Huella de relleno | hecho | Fuera la constante. `huella_de_relleno()` la crea con `wp_hash_password()` de una clave al azar, la guarda en la opción `zn_huella_relleno` (sin autoload) y la rehace si `wp_password_needs_rehash()` (cambio de coste, p. ej. PHP 8.4). Pruebas: se crea, tiene el coste actual y se rehace sola si se le pone otro coste. |
| **R86** `/refresh` sin límite | hecho | Columna `origen` en `zn_tokens` (versión de tabla 3; los tokens anteriores usan `creado`). `emitir( $id, $origen )`: el login la pone a ahora y `/refresh` la copia. Pasados `ZN_Tokens::MAX_RENOVAR` (14 días) desde el login, `/refresh` da 401 «Unauthenticated.» y el token sigue valiendo hasta caducar. Pruebas por HTTP con 13 y 15 días. |
| **R87** Dos `/refresh` a la vez | hecho | `anular()` devuelve si ha anulado esta llamada (`… AND anulado IS NULL`, una fila). `/refresh` anula primero y solo emite si fue él; si no, 401. Prueba: otra «petición» anula el token justo antes (filtro `query` en la prueba, sin ganchos de prueba en el código) → 401 y ningún token nuevo. |

**Veredicto del revisor:** R84–R87 arreglados, sin fallos nuevos. **Nota 1 (bloqueo de una hora con dos IPs):
Luis eligió A, dejarlo así** (02-11, 08:51); C, si se ve en los registros. Nota 2 (huellas `$2y$` y PHP 8.4),
apuntada para la migración (R23). Registro y recuperación: todavía no (Luis).

### Resultado de las pruebas (login A-bis, 02-11)

`node tests/todas.mjs`: **573 comprobaciones bien en 18 juegos, todo bien** (`login` 74, `limite` 35).

## Login, parte A (entrar, salir y renovar; sin correos)

Luis dio el sí a este bloque (02-11). Registro y recuperación de contraseña, después y con otro sí.

**Para los revisores (decidido por Luis para ahorrar):** un solo revisor por ronda (en login y registro,
4–5); mirad solo el diff de la ronda, `git diff bdca3f1 524ab78`; y pasad las rondas cerradas de
`REVISION.md` a otro archivo (p. ej. `REVISION-cerradas.md`) para que lo abierto quede corto. Una sola
revisión al terminar cada bloque; los arreglos pequeños los compruebo con las pruebas y van a la ronda
siguiente.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **`POST /login`** | hecho | `includes/class-zn-api-sesion.php`. `{email, password}` solo del cuerpo (JSON o formulario); lo que llegue en la dirección se ignora (H8). 200 `{status: "success", token, id (número), role}` (`src/api/auth.ts:11-15`). Sin `args` en la ruta: la validación la hace la propia ruta. |
| **Email válido antes de `intentar()`** | hecho | El email pasa por `ZN_Esquema::comprobar()` con el campo `email` del esquema de cuenta (formato y máximo, sin la regla `unique`); la contraseña, obligatoria y texto (sin mínimo: una cuenta migrada podría tener una más corta). Si algo falla, 422 con los textos del Laravel **y no se cuenta el intento**. |
| **R59/R75 en el login** | hecho | `ZN_Limite::intentar( 'login', $email )` después de validar y **antes** de buscar la cuenta o mirar la contraseña; con 429 no se comprueba nada (probado: cero comprobaciones de contraseña). Acierto → `olvidar()`. |
| **R30** Respuestas de la cuenta | hecho | Montadas a mano, sin `WP_Error`: credenciales malas → `401 {status: "error", message: "Unauthorized"}` (el cuerpo del traspaso, línea 124); sin verificar → `401 {status: "email-not-verified", message: "Email not verified"}`. Email que no existe → el mismo 401 (no 500, H28). |
| **R38** Sin `wp_authenticate` | hecho | `wp_check_password()` directamente; solo cuentas con un único rol `zn_*`. Que la cuenta no exista, sea de wp-admin, tenga un rol de la app y otro de WordPress o la contraseña sea mala da la misma respuesta, y **siempre se comprueba una contraseña** (con una huella de relleno, `HUELLA_DE_RELLENO`, si no hay cuenta), así el tiempo tampoco lo delata. «Sin verificar» solo se dice con la contraseña buena; con la mala, «error». Probado que una cuenta de la app entra por `/login` aunque `wp_authenticate()` la rechace. |
| **R39** (resto) | hecho | Respuesta del login con su prueba de contrato (también por HTTP). Al entrar, `WP_Session_Tokens::get_instance( $id )->destroy_all()`; no se abre sesión de WordPress ni se ponen cookies (probado por HTTP). La cabecera `Authorization` y `SCRIPT_NAME` en el servidor real siguen en la lista de «al subir». |
| **`POST /refresh`** | hecho | Solo con un token que vale (la sesión tiene que venir del token, no de una cookie de wp-admin): emite uno nuevo, misma respuesta que el login, y anula el anterior. Con un token caducado, 401: hay que entrar de nuevo (no hay periodo de gracia como el `refresh_ttl` del JWT). Sin token, 401 «Unauthenticated.». |
| **`POST /logout`** | hecho | Anula solo el token de la petición (los de otros móviles de la misma cuenta siguen) y responde 200 `{"message":"Successfully logged out"}`. Sin token o con uno ya anulado, 401 «Unauthenticated.». |
| **R49** (parte) | hecho | Las respuestas de login, refresh y logout llevan `Cache-Control: no-store, private`. La caché del servidor sigue en la lista de «al subir». |
| **R78** | hecho (README) | Línea nueva junto a `ZN_PROXIES`: los rangos IPv4 se escriben en forma normal (`10.0.0.0/8`), nunca como `::ffff:10.0.0.0/104`. Lo demás de R78, al subir. |

**Apuntado (no se ha hecho):**
- El texto exacto del `message` de «sin verificar» y del logout no está en los documentos; la app solo lee `status`. Si Alejandro tiene el del Laravel, se copia.
- Las contraseñas migradas (`$2y$10$…`) se comprueban tal cual y no se rehacen al entrar (rehacerlas con `wp_set_password()` anularía los tokens de la cuenta). Se puede hacer al migrar, con su sí.
- La app no llama a `/logout` ni a `/refresh` (`src/auth/sesion.ts`): es un cambio en la app, lo deciden Luis y Alejandro.
- R30 «red de seguridad» (`wp_insert_user` → 422) va con el registro.
- Las cuentas de prueba de Local (como `comercio-prueba`) no tienen `zn_verificado`: no pueden entrar hasta que exista la verificación (bloque B) o se marquen a mano.

### Resultado de las pruebas (código del login A, 02-11)

`node tests/todas.mjs`: **557 comprobaciones bien en 18 juegos, todo bien**. Nueva `tests/login.php`: 60
(login por dentro desde IPs de documentación y por HTTP de verdad, refresh, logout, límite, sesiones de
WordPress, limpieza de cuentas, tokens y filas de intentos). El resto de juegos, todos en verde.

## Segundo bloque-ter (R82, R83)

El revisor dio por buenos R75, R77, R79, R80 y R81; R76 parcial por R83. Luis dio el sí a R82, R83 y las dos
mejoras (02-11).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R82** Editar un comentario pasaba por kses | hecho | Quitar los filtros de `pre_comment_content` no basta: `wp_update_comment()` vuelve a poner `wp_filter_kses` él mismo si quien escribió no tiene `unfiltered_html` (`comment.php`, al principio de la función). Por eso `ZN_Esquema::texto_tal_cual()` repone el texto ya validado en `wp_update_comment_data` (prioridad máxima, datos ya sin barras), solo durante esa llamada; el resto de `wp_update_comment()` (ganchos `edit_comment`, cachés, puntuación) no cambia. Pruebas en `tests/escrituras.php`: editar a «me encantó <3 volveré; menores <12 gratis y >65» sale entero, el filtro no queda puesto y una edición por fuera de `ZN_Esquema` sigue pasando por kses. |
| **R83** IPv4 escritas como IPv6 | hecho | `ip_valida()` pasa `::ffff:a.b.c.d` a `a.b.c.d` (10 bytes a cero y `ffff`); `grupo()` y `ip()` la usan, y `en_lista()` reconoce así un proxy `::ffff:10.1.2.3` en `10.0.0.0/8`. Pruebas en `tests/limite.php`. En el servidor, mirar también la forma de `REMOTE_ADDR` (con R78). |
| Borrador por defecto | hecho | `guardar()` ya no publica: sin `post_status` en `$extra` la entrada nueva queda en borrador. Prueba en `tests/escrituras.php`. |
| Login: email válido antes de `intentar()` | apuntado | Requisito del login: validar el email con el esquema antes de contar el intento. |

### Resultado de las pruebas (código del segundo bloque-ter, 02-11)

`node tests/todas.mjs`: **497 comprobaciones bien en 17 juegos, todo bien** (`limite` 33, `escrituras` 26; el
resto, igual).

---

## Segundo bloque-bis (R75-R81)

El revisor dio por buenos R58 (como base) y el tope de tokens; R59 parcial. Luis dio el sí a R75, R76, R77,
R79, R80 y R81 (02-11). R78 queda para el servidor.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R75** Bloquear a otra persona | hecho | Reglas por acción en `ZN_Limite::REGLAS`: **cuenta + IP** (la estricta: login 5 cada 15 min), **cuenta sola** mucho más alta (login 30 por hora, 6 veces la anterior) e **IP sola** (login 50 cada 15 min). El dueño de la cuenta sigue entrando desde su IP aunque otro falle desde la suya. `olvidar()` tras un acierto borra solo cuenta + IP. El docblock y el README dicen que la ruta llama a `intentar()` antes de mirar la contraseña; se comprobará en las pruebas del login. |
| **R76** IPv6 | hecho | `ZN_Limite::grupo()`: una IPv6 cuenta por su `/64`; la IPv4, entera. |
| **R77** Huellas | hecho | `hash_hmac( 'sha256', …, wp_salt( 'auth' ) )`. Las filas cuya ventana más larga ya pasó se borran en cada llamada. Si el correo es de una cuenta que existe, se cuenta por `id:<número>` (`get_user_by( 'email' )`); si no, por el correo en minúsculas. |
| **R78** Proxy de Dinahosting | pendiente (al subir) | En la lista de «al subir al servidor» del README, junto a R39 y R49. |
| **R79** Dueño | hecho | `userId` marcado con `'dueno' => true` en negocio, novedad y sorteo. `ZN_Esquema::permitir_dueno( $recurso, $resultado, $elemento_o_null, $usuario )`: al crear, `ZN_Roles::puede_crear()` o `sin_permiso` (la ruta da 401); al modificar, el dueño solo cambia si la cuenta es la administración de la app con el permiso general y el valor es otro; si no, se ignora sin error (como el Laravel). En novedades aplica R70 con los valores finales (nuevos o guardados): 422 en `businessId` (o `userId`). La regla de R70 vive ahora en `ZN_Eventos::dueno_valido_para_negocio()`; la de `ZN_Admin_Eventos` la llama. |
| **R80** Guardar | hecho | `ZN_Esquema::guardar( $recurso, $id_o_null, $valores, $extra )`: entradas con `wp_insert_post` / `wp_update_post` (título y contenido) y campos personalizados; comentarios con `wp_insert_comment` (sin aprobar) / `wp_update_comment` y sus campos, y la aprobación siempre con `wp_set_comment_status()` (de `published` o de `$extra['comment_approved']`). Todo con `wp_slash`. Solo escribe campos del esquema; comprueba el tipo de la entrada o del comentario. El `user_id` de `$extra` (quien escribe) y el de `$valores` (destinatario de la pregunta) no se mezclan. Las fichas de wp-admin guardan con ella. Las cuentas se guardarán con el registro (R53, R69). |
| **R81** Cuenta y email repetido | hecho | Esquema `cuenta`: `name` 1-255 (en `first_name`, R53), `email` con formato, máximo 100 (la columna de WordPress) y **único** («The email has already been taken.», sin contar la propia cuenta en PATCH), `password` 8-255 sin recortar, `phone` opcional ≤20 y 9-15 cifras con `+` opcional, `latitude`/`longitude` y `code` de 6 cifras. Mensajes `unique`, `min_string`, `digits` y `regex` añadidos. |
| Pruebas (mejoras del revisor) | hecho | `tests/esquema.php`: sin datos de prueba dice «FALLO … datos-prueba» en vez de error fatal; los avisos se comprueban con `>=`. `tests/limite.php`: borra solo sus propias filas (por sus claves); 429 también a través de la API con `Retry-After`. `tests/escrituras.php` (nueva): R79 y R80 con cuentas temporales. |
| Resto de mejoras | pendiente | `TEXTOS` y `maxlength` a mano, `limpiar_urls` / `limpiar_fechas` del panel, `wp zona-nautico revisar-datos`, caracteres invisibles, `uninstall.php`. Cambios en la app («Espera unos minutos», mandar `null` para vaciar): los decide Luis. |

### Resultado de las pruebas (código del segundo bloque-bis, 02-11)

`node tests/todas.mjs`: **489 comprobaciones bien en 17 juegos, todo bien** (`esquema` 65, `limite` 29,
`escrituras` 22 nueva; el resto, igual).

---

## Segundo bloque (R58 y R59)

El revisor dio OK al segundo bloque en la verificación de global-bis. Luis dio el sí el 02-11
(primero este bloque y después el panel de R40).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R58** Esquema y validación comunes | hecho | `includes/class-zn-esquema.php`: una definición por recurso (`negocio`, `evento`, `sorteo`, `zn_resena`, `zn_pregunta`): campo de la app en camelCase → campo guardado, tipo, máximo de la sección 4.3, obligatorio, nombre en la respuesta y etiqueta para wp-admin. **La usan:** el registro de los campos personalizados (`ZN_Negocios::campos()`… sustituyen a las constantes `CAMPOS`; los mismos campos que antes, comprobado), los formateadores (los campos «simples» salen de `ZN_Esquema::salida()`; mismas claves, mismo orden y mismos tipos, comprobado), las fichas de wp-admin (`ZN_Admin_Campos::con_esquema()`: mismas reglas que la API, avisos en español y `maxlength`) y la orden de datos de prueba. **Validador:** `ZN_Esquema::validar( $recurso, $cuerpo, $guardado_o_null )` devuelve los valores con su nombre guardado y los errores con los textos del Laravel 10 («The name field must not be greater than 50 characters.», «The selected user id is invalid.», «The photos.0 field must be a valid URL.»…), con `:attribute` como lo pinta el Laravel (`categoryId` → «category id»); `ZN_Esquema::respuesta()` → 422 con `ZN_Errores::no_valido()`. **Regla del PATCH (decisión a):** ausente → no se toca; `null` → se borra (en un obligatorio, 422 «is required»); `""` o solo espacios → 422 «must have a value». Fotos y vídeos: `photos[i]` → `photo_i`, los huecos que no llegan no se tocan (como `processMedia`), `photos: null` borra los tres. Campos que no están en el esquema, ignorados (también `score`, H14). |
| R58: diferencias con el Laravel (a propósito) | para revisar | `name` del negocio y `title` del sorteo y de la reseña: máximo 50, el de la columna (el Laravel valida 255, H31). `score` de la reseña entre 1 y 5 (H13). `question` obligatoria. Textos de personas (reseña, pregunta, respuesta): máximo 2000 (el Laravel no pone tope). `email` y `web` con formato válido (el Laravel solo pedía texto). `endDate` no antes de `startDate`, también en PATCH contra la fecha guardada. En novedades se acepta `category` y su alias `categoryId` (H37). |
| R58: cambios en wp-admin | hecho | Las fichas validan con las reglas del esquema: un país de 3 letras, un teléfono de más de 20 caracteres o un email de más de 50 ya no se guardan (antes el país se recortaba a 2 y no había máximos); una categoría inventada ahora avisa (antes se ignoraba en silencio). Si el título pasa del máximo de la API, se guarda (lo guarda WordPress) pero avisa. Ganadores vacío: avisa y no se borra (es obligatorio). |
| **R59** Límite de intentos común | hecho | `includes/class-zn-limite.php`: `ZN_Limite::intentar( $accion, $email )` cuenta por IP y por email a la vez (ventana fija, una sola consulta atómica `INSERT … ON DUPLICATE KEY UPDATE`), devuelve los segundos de espera si alguna clave se pasa; `olvidar()` tras un acierto (solo el email); `demasiados()` → 429 `{"message":"Too Many Attempts."}` con `Retry-After`, que la app ya entiende. Topes en `ZN_Limite::REGLAS` (login 5 por email y 30 por IP cada 15 min; recuperar y reenviar 3 por email y 10 por IP por hora; código 5 por email cada 15 min; registro 10 por IP por hora). Tabla `{prefijo}zn_limites` con huellas SHA-256 («acción\|ip\|…»): ni IP ni correo en claro; se instala en `plugins_loaded` y al activar; filas de más de un día se borran solas. Todavía no la usa ninguna ruta: la usarán login, recuperación, reenvío y registro. |
| R59: de dónde sale la IP | hecho, falta el dato del servidor | `REMOTE_ADDR`. `X-Forwarded-For` solo si `REMOTE_ADDR` es un proxy de confianza declarado en `ZN_PROXIES` (IPs o rangos IPv4/IPv6 en `wp-config.php`), leyendo de derecha a izquierda y saltando los proxies de confianza; si algo no es una IP, se queda la última buena. Sin la constante, nunca se mira. **Al subir al servidor:** averiguar si Dinahosting pone un proxy y sus IPs (junto a R39 y R49). |
| R59: tope de tokens por cuenta | hecho | `ZN_Tokens::MAX_ACTIVOS = 10`: al emitir uno nuevo se anulan los más antiguos de esa cuenta. Pruebas en `tests/tokens.php`. |
| **R40** Panel para los encargados | **decidido (Luis, 02-11)** | Asociación y federación entran a wp-admin con su misma cuenta de la app y solo ven y gestionan lo suyo (usuarios, comercios y concursos de su rama). Los comercios no entran: todo desde la app. Cambia la regla «cuentas de la app sin `read`»; se hará en un bloque aparte, después de este, con el sí de Luis. |

### Resultado de las pruebas (código del segundo bloque, 02-11)

`node tests/todas.mjs`: **447 comprobaciones bien en 16 juegos, todo bien**. Nuevas: `esquema.php` 54
(solo lee; la pueden lanzar los revisores) y `limite.php` 20 (escribe y borra filas de prueba; hilo que
desarrolla); `tokens.php` pasa de 56 a 60. El resto, igual que en global-bis.

---

## Verificación del primer bloque (global-bis)

El revisor dio por buenos R55, R56, R60, R61, R63 y R64; R57 parcial. Luis dio el sí a R57-bis, R70 y
R71 (con la recomendación: `user` null) y eligió la opción A en R69 (30-10).

| Punto | Estado | Qué se hizo / decisión |
|---|---|---|
| **R57-bis** Correo | hecho | Regla invertida: correo real **solo** con `define( 'ZN_CORREO_REAL', true )` y entorno `production`; si no, solo `@ejemplo.invalid`. Se miran `to`, `Cc` y `Bcc` (cabeceras en texto o lista, con «Nombre <…>»): `ZN_Puertas::destinatarios()`. Fuera `wp_send_new_user_notification_to_admin`, `comments_notify` y `moderation_notify`. Pruebas en `tests/seguridad.php`. |
| **R70** Dueño de una novedad de un negocio | hecho | `ZN_Admin_Eventos::dueno_valido_para_negocio()`: dueño del negocio, su cuenta padre si es asociación o federación, o la administración de la app. Si no, la ficha no guarda el dueño y avisa. Pruebas en `tests/seguridad.php`. Lo usarán también las rutas de escritura. |
| **R71** `includesUser` y cuentas de administración | hecho | `usuario_publico()` devuelve null para el rol `administrator` de la app. Pruebas en `tests/roles.php` y `tests/seguridad.php`. |
| **R69** Cambiar contraseña o correo con un token robado | **decidido (Luis): A** | Pedir la contraseña actual para cambiar la contraseña o el correo. Requiere añadir ese campo en la app: **la app no se toca sin la decisión de Luis y Alejandro**, así que queda apuntado para cuando se programe `PATCH /user` y se pueda cambiar la app. Hasta entonces, `PATCH /user` no se publica sin esa comprobación. |

### Resultado de las pruebas (código de global-bis, 30-10)

`node tests/todas.mjs`: **369 comprobaciones bien en 14 juegos, todo bien** (`seguridad` 84, `roles` 90;
el resto, como en la revisión global).

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

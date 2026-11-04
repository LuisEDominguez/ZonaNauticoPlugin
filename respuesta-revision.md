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
| Hilo que desarrolla (pasa el resultado a los revisores) | `wp eval-file tests/seguridad.php`, `tests/comentarios.php`, `tests/roles.php`, `tests/tokens.php`, `tests/limite.php`, `tests/escrituras.php`, `tests/login.php`, `tests/registro.php`, `tests/mis-datos.php`, `tests/borrar-cuenta.php`, `tests/nombre-de-pila.php`, `tests/instalacion.php`, `tests/opiniones.php`, `tests/privacidad.php` y `tests/panel.php` (crean datos, cuentas o filas temporales y los borran) y `node tests/puertas.mjs` (no guarda nada, pero envía una petición a XML-RPC) |

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
| Panel R40, parte 1 | 02-11-2026 | `077f02a` (v0.17.0) | `20959b2` (v0.18.0) | Asociación y federación entran a wp-admin y solo ven lo suyo (sin editar) |
| Panel R40, parte 1-bis | 02-11-2026 | `20959b2` (v0.18.0) | `b11dd54` (v0.18.1) | R97, R98 = A y nombres de pila en «Mi zona» |
| Panel R40, parte 1-ter | 02-11-2026 | `b11dd54` (v0.18.1) | `2cece16` (v0.18.2) | La federación ve dos niveles (decisión de Luis) |
| Panel R40, nota ronda 24 | 02-11-2026 | `2cece16` (v0.18.2) | `b7f2297` (v0.18.3) | La federación no ve el correo de los comercios de sus asociaciones |
| Panel R40, parte 2a | 02-11-2026 | `b7f2297` (v0.18.3) | `9eb02bc` (v0.19.0) | Editar negocios, novedades y sorteos desde «Mi zona» |
| Panel R40, parte 2a-bis | 02-11-2026 | `9eb02bc` (v0.19.0) | `fe75dbb` (v0.19.1) | R99 = B, R100 = A, R101 |
| Panel R40, parte 2b | 02-11-2026 | `a26168e` (v0.19.1) | `1611c4f` (v0.20.0) | Cuentas: alta con correo, renombrar y baja desactivando |
| Panel R40, parte 2b-bis | 02-11-2026 | `1611c4f` (v0.20.0) | `2864e30` (v0.20.1) | R102 = A, R103 (correo de contacto pendiente), R104 |
| Cambiar mis datos | 02-11-2026 | `6a197c2` (v0.20.1) | `2fa7c1d` (v0.21.0) | R69: `PATCH /user/{id}` con la contraseña actual y confirmación del correo nuevo |
| Cambiar mis datos-bis | 02-11-2026 | `2fa7c1d` (v0.21.0) | `75fc042` (v0.21.1) | R105, R106, R107, R108 |
| Cambio de equipo (ronda 31) | 03-11-2026 | `7549f32` (v0.21.1) | `3013a0b` (v0.21.1) | Pruebas independientes del PC (solo `tests/`): rutas de Local, puerto y dueño. Revisada sin fallos |
| Eliminar cuenta (ronda 32) | 04-11-2026 | `3013a0b` (v0.21.1) | `a7332e4` (v0.22.0) | `DELETE /user/{id}` (D1: la app lo llamaba y no existía) |
| Eliminar cuenta-bis | 04-11-2026 | `a7332e4` (v0.22.0) | `09fac02` (v0.23.0) | R110 (contraseña actual para borrar) y R111 (correo de aviso) |
| Bloque 1, parte B | 04-11-2026 | `89521b9` (v0.23.0) | `f889285` (v0.24.0) | R66: solo el nombre de pila en lo público y `/review?user=` con token |
| Bloque 1, parte C | 04-11-2026 | `237719b` (v0.24.0) | `6b39aa8` (v0.25.0) | R68: instalación en un solo sitio, `uninstall.php` y prefijo `zn_` en las cuentas |
| Ronda 37, notas | 04-11-2026 | `6b39aa8` (v0.25.0) | `63c01aa` (v0.25.1) y `d579bd6` (puerto en las `.mjs`) | R119 y R121 (README) y R120 (migración solo de cuentas de la app); R122, a la espera de Luis |
| Bloque 2, parte A | 04-11-2026 | `d579bd6` (v0.25.1) | `c6713e7` (v0.26.0) | `GET /me` con los permisos de la cuenta |
| Bloque 2, parte B | 04-11-2026 | `a6e3ab4` (v0.26.0) | `8641ecf` (v0.27.0) | Portada: `/news`, `/home-slides`, `/business-category` e `includesCategory` |
| Bloque 2, parte C | 04-11-2026 | `8641ecf` (v0.27.0) | `57e1dbd` (v0.28.0) | Favoritos: `/favorite` y su RGPD |
| Sorteos, parte 1 (plugin) | 04-11-2026 | `3f8ecdf` (v0.28.0) | `dda1515` (v0.29.0) | Participaciones: `/draw-participation`, fotos privadas sin EXIF, export, ganadores, borrado y RGPD |
| Ronda 45 (sorteos) | 04-11-2026 | `dda1515` (v0.29.0) | `d649d33` (R132, R134) y `cf983a8` (v0.29.1) | R131-R141 |
| Ronda 45-bis | 04-11-2026 | `cf983a8` (v0.29.1) | `93f4d0c` (v0.29.2) | R144 (escalar antes de girar); R142 y R143 anotados para «Al subir» |
| Sorteos, parte 3 | 04-11-2026 | `93f4d0c` (v0.29.2) | `bece42f` (v0.30.0) | Gestionar participantes desde «Mi zona» y wp-admin |
| Ronda 47 | 04-11-2026 | `bece42f` (v0.30.0) | `3db3ef0` (v0.30.1) | R147 (sello por acción y fila) y R148 (foto del sorteo de la dirección) |
| Tanda C, parte 2 | 04-11-2026 | `3db3ef0` (v0.30.1) | `dff6625` (v0.30.2) | R50 y R109: sin permiso, 401 también cuando el id no existe |
| Tanda C, parte 3 | 04-11-2026 | `dff6625` (v0.30.2) | `f878bde` y `6bdde6d` (v0.30.3) | Preparar la subida: paquete (R55), lista «Día de la subida» y prueba de que sin `ZN_CORREO_REAL` no sale correo |
| Refresh token (opción C) | 04-11-2026 | `c4ba8c4` (v0.30.3) | (este commit, v0.31.0) | `refreshToken` de un solo uso, ligado al login, 14 días; reuso anula la cadena |

---

## Favoritos (bloque 2, parte C)

Luis (vía la maestra, 04-11): «Sí, apruebo»; para toda cuenta de la app con sesión, sin contadores públicos; tabla con
`user_id` y `business_id` (traspaso). Diff: `git diff 8641ecf HEAD`. Pruebas escritas antes (`tests/favoritos.php`,
31). **Datos personales: revisor principal + uno de seguridad, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Tabla** | hecho | `{prefijo}zn_favoritos` (`id`, `user_id`, `business_id`, `creado`; única por cuenta y negocio). La crea `ZN_Instalacion::instalar()` (`zn_favoritos_tabla`); `uninstall.php` la borra (R68). |
| **Rutas** | hecho | `GET /favorite` (solo las suyas; negocios visibles con el formato de `/business/{id}`; `no-store`), `POST /favorite {businessId}` (201, o 200 si ya estaba, con `INSERT IGNORE` y clave única: dos toques a la vez no duplican; 422 si falta o no es un entero; 404 si el negocio no se ve; límite R94) y `DELETE /favorite/{businessId}` (200 siempre). Todas con `con_sesion_de_la_app`: sin sesión, 401 «Unauthenticated.». |
| **Privacidad** | hecho | Solo lo propio, sin contadores en ninguna respuesta pública. RGPD (R65): `delete_user` borra sus filas (también con `DELETE /user`); `before_delete_post` de un negocio borra las suyas; el exportador añade «Negocios favoritos» y el borrador los quita. |
| **Pruebas** | hecho | `tests/favoritos.php` 31 (también por HTTP); `instalacion` (ahora tres tablas) 28, `privacidad` 29 y `borrar-cuenta` 39 siguen bien. |

## Portada (bloque 2, parte B)

Luis (vía la maestra, 04-11): «Sí, apruebo»; slides y Novedades ZN solo las edita la administración en wp-admin;
categorías fijas con `sort_order`; carga inicial solo con las 60 novedades y las 6 slides de la app; nombres del
traspaso. Diff: `git diff a6e3ab4 HEAD`. Pruebas escritas antes (`tests/portada.php`, 36).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Novedades ZN** | hecho | Tipo `zn_noticia` (permisos `zn_noticia(s)`, solo el administrador; sin página pública ni `/wp/v2`). `GET /news` paginado como el resto, por fecha descendente; `GET /news/{id}` (borrador u otro tipo, 404). `description` pasa por `wp_kses` con `br`, `p`, `strong`, `em`, `a[href]` e `img[src,alt]`; vacía → null. `date` desde `post_date_gmt` en hora de Madrid. |
| **Slides** | hecho | Tipo `zn_slide` (`zn_slide(s)`); `GET /home-slides`: publicadas con foto, por `menu_order` (= `sort_order`), sin paginar. `link` opcional (la app de hoy no lo usa). |
| **Categorías** | hecho | `GET /business-category`: las 7 de `ZN_Negocios::CATEGORIAS` con `sort_order` 1-7 (el orden de Adalo, el mismo que ya tenía la lista). `/business` y `/business/{id}` admiten `includesCategory` → `category {_id, name}` (o null). Sin él, la respuesta no cambia (contrato-negocios y contrato-muestras siguen bien). |
| **Quién edita** | hecho | `ZN_Roles::actualizar` da al administrador los permisos de los dos tipos nuevos; `ZN_Instalacion::es_del_plugin` los reconoce para `uninstall.php` (R68). Panel R40 sin cambios. |
| **Carga inicial** | hecho | `wp zona-nautico portada-inicial <carpeta de la app>`: 60 Novedades ZN (`zn_origen` = id de Adalo, fecha UTC de Adalo) y 6 slides con sus fotos subidas a medios. Repetirla no duplica. **Ejecutada en el Local** (60 y 6). |
| **Pruebas** | hecho | `tests/portada.php` 36; sin regresiones en `instalacion` 28, `roles` 90, `panel` 177, `seguridad` 84, `esquema` 65, `contrato-negocios`, `contrato-muestras` y `puertas`. |

## Refresh token (opción C)

Aprobado por Luis (vía la maestra, 04-11): «Sí, hasta 14 días», con la opción C de los revisores y sin renovar tokens caducados. Diff: `git diff c4ba8c4 HEAD`. Pruebas escritas antes en `tests/renovacion.php` (42; fallaban 18).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Login** | hecho | Además del token de 60 min, `refreshToken`: 64 bytes al azar en base64url (86 caracteres), al final de la respuesta. Se guarda solo su huella, en `zn_tokens` (versión de tabla 4: columnas `tipo` acceso/renovar, `cadena` y `usado`). Cada login abre una cadena; todo lo que sale de él la comparte. Caduca a `MAX_RENOVAR` (14 días) del login. No vale como token de acceso. |
| **POST /refresh** | hecho | Con `{refreshToken}`: no mira el token de acceso (puede haber caducado). Si vale, lo marca como usado (un `UPDATE … WHERE usado IS NULL`: solo una petición lo gasta), anula los tokens de acceso de su cadena y da token y refresh nuevos de la misma cadena, con la fecha del login de siempre. Sin `refreshToken`, exactamente como antes (prueba de `login`). Nunca se renueva un token de acceso caducado. |
| **Reuso** | hecho | Un refresh ya usado (o el segundo de dos a la vez) anula toda la cadena: su token de acceso y su refresh de ahora; 401. Los otros logins de la cuenta siguen. Así R87 sigue: de dos `/refresh` a la vez con lo mismo, solo uno da sesión (y con el refresh, ninguna queda). |
| **Anulación** | hecho | `/logout`: anula la cadena del token (también su refresh); con el token de acceso caducado, `{refreshToken}` en el cuerpo. Contraseña o correo: los refresh de los demás logins (no el de quien hace el cambio, R56). Rol, baja en el panel y borrado: todos (`anular_todos`). Cuenta dada de baja o sin rol de la app: el refresh no vale. Como mucho `MAX_ACTIVOS` cadenas con refresh (R59). |
| **Pruebas** | hecho | `renovacion` 42 (también por HTTP: token caducado en la cabecera, refresh como Bearer, logout). Cambiadas: `login` (la respuesta lleva `refreshToken` al final; la prueba de R87 llama a `renovar()` con su petición) 74. Sin cambios: `tokens` 60, `instalacion` 28, `mis-datos` 78, `registro` 77, `borrar-cuenta` 39, `panel` 177, `seguridad` 84, `me` 18, `roles` 90, `sin-adivinar` 35. |

## Tanda C, parte 3: preparar la subida (sin servidor)

Aprobado por Luis (vía la maestra, 04-11). Diff: `git diff dff6625 HEAD`. Nada se ha subido ni enviado a ningún servidor.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R55** Paquete | hecho | `node herramientas/empaquetar.mjs [--salida <carpeta>]` (por defecto, Descargas): `git archive` de `HEAD` a `zona-nautico-<versión>.zip`, con todo dentro de `zona-nautico/`. Se para con cambios sin guardar (el zip saldría de `HEAD`) y si el paquete llevaría algo fuera de la lista permitida (`zona-nautico.php`, `uninstall.php`, `.htaccess`, `includes/*.php`, `assets/`). Prueba escrita antes: `tests/paquete.mjs` (6; no necesita Local; hace el zip de verdad en una carpeta temporal y lo lista con `tar`). |
| **.gitattributes** | arreglado | La prueba encontró que `tests/ export-ignore` vale para `git archive` (que salta la carpeta) pero no para `git check-attr` (que mira archivo por archivo). Ahora cada carpeta con las dos formas, `tests/` (quita la carpeta del zip; sin ella quedaba vacía dentro) y `tests/**` (cada archivo, para `check-attr`); igual `herramientas/`. La prueba mira también que no haya carpetas vacías de pruebas o herramientas en el zip. El zip de antes ya salía sin pruebas. |
| **Día de la subida** | hecho | Una sola lista en el README que reúne todas las notas «al subir»: paquete (R55); `wp-config.php` (entorno, `ZN_CORREO_REAL` R57, `ZN_CORREO_CONTACTO` R103/R111, `EMPTY_TRASH_DAYS` R100, `DISABLE_WP_CRON`, R34e, `DISALLOW_UNFILTERED_HTML` R67); comprobaciones (R39, R49, R78/R59 proxy, R85); tickets (R131, R142, R143); cron real; cuentas de wp-admin (R67); plugins de correo (R57, R114); migración (R23) y la app (R112). Las menciones sueltas del README apuntan a la lista. |
| **Correo** | hecho | `tests/correo-sin-salida.php` (20): con direcciones de `example.com` (reservado, de nadie) pasa por alta, reenvío, código, contraseña cambiada, cuenta borrada y `wp_mail` con Cc y Bcc; los 8 o más correos a direcciones de verdad se paran todos, y uno a `@ejemplo.invalid` sí saldría. La regla del interruptor, aparte en `ZN_Puertas::correo_real_activado()` para probarla también para producción (en Local el entorno no se puede cambiar): solo con la constante a `true` (ni `'1'` ni `1`) y entorno `production`. Se recogen y no salen, ni a Mailpit. |
| **Pruebas** | hecho | `paquete` 6, `correo-sin-salida` 20 y `seguridad` 84; las tres nuevas (con `sin-adivinar`) añadidas a `tests/todas.mjs`. |

## Tanda C, parte 2 (R50, R109)

Aprobado por Luis (vía la maestra, 04-11). Diff: `git diff 3db3ef0 HEAD`. Pruebas escritas antes en `tests/sin-adivinar.php` (35; fallaban 15).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Regla** | hecho | `ZN_API::no_existe( permiso )`: lo que no existe responde como lo ajeno, 401 «This action is unauthorized.»; solo la administración de la app con ese permiso (lo ve todo) recibe 404. |
| **R50** `/user/{id}` | hecho | `GET`: una cuenta que no existe o que no es de la app (wp-admin) → el mismo 401 que una ajena. `PATCH` y `DELETE`: solo la propia cuenta, así que siempre 401 (también para la administración). |
| **R109** y el resto | hecho | Revisadas todas las rutas con id. Cambian las que pueden encontrar algo no público: `PATCH`/`DELETE /event/{id}` y `/draw/{id}` (borradores), `DELETE /review/{id}` y `PATCH /review/reply/{id}` (reseñas ocultas), `PATCH /faq/{id}` (preguntas sin publicar). En wp-admin, `admin-post.php?action=zn_participantes` con un sorteo que no existe da el mismo 403 que un sello malo. |
| **Sin cambios** | revisado | Fichas públicas (`GET /business`, `/event`, `/draw`, `/review`, `/faq`, `/news/{id}`): solo ven lo publicado, el 404 no descubre nada. `/draw-participation/{id}` (borrar, marcar, ticket) y el export ya daban 401 antes de mirar si existe. `POST /favorite` y `POST /draw-participation`: solo negocios y sorteos publicados. `DELETE /favorite/{id}`: siempre 200. El panel ya daba lo mismo con un sorteo que no existe. |
| **Pruebas** | hecho | `sin-adivinar` 35 (cada ruta: lo ajeno y lo que no existe dan la misma respuesta; la administración sigue con 404). Cambiadas a 401 las que esperaban 404 con cuentas sin permiso: `borrar-cuenta` 39, `mis-datos` 78, `tokens` 60, `novedades-sorteos` 58, `opiniones` 78. Sin cambios: `participaciones-admin` 38, `seguridad` 84, `roles` 90. |

## Ronda 47 (R147, R148)

Los pidió la maestra. Diff: `git diff bece42f HEAD`. Pruebas escritas antes en `tests/participaciones-admin.php` (ahora 38; fallaban).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R147** Sello | hecho | Un sello por acción y por fila: `zn_part_{sorteo}_ticket_{fila}`, `zn_part_{sorteo}_{hacer}_{fila}` y `zn_part_{sorteo}_csv`. `ZN_Participaciones::accion_sello()` lo saca de lo que se pide (mismo orden que `atender()`: foto, CSV, marca; un `hacer` desconocido no tiene sello) y `sello_valido()` lo comprueba en `atender_admin()` y en `ZN_Panel::procesar_participantes()`. `urls_con_sello()` hace las tres direcciones para los dos sitios. El sello del `<img>` ya no vale para «Marcar ganador», ni el de una fila para otra, ni el de «ganador» para «entregado», ni el del CSV para la foto. |
| **R148** Foto | hecho | `ticket_de_sorteo()`: la participación tiene que ser del sorteo de la dirección (también para la administración), igual que las marcas; `atender()` la usa. |
| **Pruebas** | hecho | `participaciones-admin` 38, `participaciones` 91 y `panel` 177, de una en una. |

## Sorteos, parte 3: gestionar participantes (panel y wp-admin)

Aprobado en la propuesta de participaciones. Diff: `git diff 93f4d0c HEAD`. Pruebas escritas antes (`tests/participaciones-admin.php`, 29).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Quién** | hecho | `puede_gestionar()`: quien organiza (`ZN_Roles::puede( 'draw_participation:read' )`, R43, sin herencia: la asociación no ve los de su comercio) o la administración de WordPress (`edit_others_zn_sorteos`). |
| **Panel** | hecho | «Mi zona»: enlace «Participantes de tus sorteos»; página `?participantes={id}` (sin `action`, R97) con la lista, la miniatura (`&ticket=`), los enlaces de ganador y entrega (`&fila=&hacer=`) y el CSV (`&csv=1`), todo con sello (desde la ronda 47, uno por acción y fila: R147) y atendido en `load-` antes de pintar. |
| **wp-admin** | hecho | Caja «Participantes» en la ficha del sorteo; foto, CSV y marcas por `admin-post.php?action=zn_participantes` con sello (R147). Enlaces y no formularios, porque la caja está dentro del formulario de la ficha. |
| **Reglas** | hecho | `marcar_fila()` común con la API: entrega solo a quien ganó, R139 (límite), aviso privado al ganador (R57). `csv()` común con el export. La participación tiene que ser del sorteo de la dirección. La miniatura nunca es la dirección del archivo. |
| **Sin probar aquí** | nota | Las páginas reales de wp-admin y «Mi zona» con sesión (haría falta entrar con contraseña): la prueba pinta el HTML y llama a los manejadores directamente. |

## Ronda 45-bis (R142-R144)

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R144** Memoria | hecho | `cargar_imagen()`: primero se escala a 2000 px y se suelta la grande, y luego se gira. Prueba escrita antes (fallaba): una foto de 6000 × 3000 con Orientation = 6 pasaba de 140 MB de memoria; ahora 78 MB, y se guarda en 1000 × 2000. |
| **R142, R143** | anotado | En el README, «Subir al servidor»: comprobar por HTTP que `zn-privado-tickets` no se abre; con WordPress en una subcarpeta de la web o enlaces en el camino, poner la ruta real con el filtro `zn_carpeta_tickets`. |

## Ronda 45: participaciones (R131-R141)

R132 y R134 los pidió la maestra; R131, R133, R135-R141, aprobados por Luis («Sí, como recomiendas»). Pruebas
escritas antes, en `tests/participaciones.php` (ahora 89). Diff: `git diff dda1515 HEAD`.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R131** Sin plan B | hecho | `carpeta()` solo da `zn-privado-tickets/` fuera de la web; si no es válida o no se puede escribir, `''`: el POST responde 503 («Tickets cannot be saved right now…») sin guardar nada y wp-admin avisa (`aviso_carpeta()`, solo a quien puede `manage_options`). Anotado en el README, en «Subir al servidor». |
| **R132** Píxeles | hecho | `comprobar_imagen()` con la cabecera (`getimagesize`): tamaño, tipo real y como mucho 40 MP, antes de decodificar; y `wp_raise_memory_limit( 'image' )`. El sorteo, el permiso, R140 y «ya participas» se miran antes de tocar la imagen. Probado con un PNG que dice medir 20000 × 20000: 422, no 500. |
| **R133** 90 días | hecho | Sin entrega, a los 90 días del fin también se borran nombre y correo de quien ganó (ya lo hacía; ahora con prueba). |
| **R134** Ruta | hecho | `ruta_valida()`: nombre `zn-privado*`, no vacía, no enlace (`is_link`, y `realpath` igual a la ruta), y que no sea ni contenga ABSPATH, `wp-content` ni `uploads`. `carpeta()` y `borrar_carpeta()` la exigen; `borrar_carpeta()` solo borra lo que pone el plugin (fotos de 32 caracteres, `.htaccess`, `index.php`). Probado con una carpeta ajena, ABSPATH, `uploads` y vacía (en Windows no se puede crear un enlace simbólico sin permisos: esa parte no se ha podido probar aquí). |
| **R135** `_envelope` | hecho | En `servir_archivo()`, si la respuesta viene envuelta y es un archivo, se responde un JSON con un mensaje, sin la ruta. Probado por HTTP. |
| **R136** Huérfanos | hecho | Si `unlink` falla, la fila se queda (la tarea lo reintenta). La tarea diaria barre las fotos sin fila de más de un día. |
| **R137** Desactivar | hecho | `register_deactivation_hook` → `desprogramar()` quita la tarea. |
| **R138** Cuándo cuentan los 30 días | hecho | Desde lo más tarde: el fin del sorteo o la última entrega. |
| **R139** PATCH | hecho | Cuenta en el límite de escrituras (429). |
| **R140** Organizador | hecho | Quien organiza no participa en su sorteo: 422 en `draw`. |
| **R141** Comentario | hecho | Corregido: queda el `user_id` de quien ganó y `winnerNames` sigue dando su nombre de pila mientras exista la cuenta (decisión 4). |

## Participaciones en sorteos, parte 1: plugin

Luis (vía la maestra, 04-11): «Sí, como recomiendas» a la propuesta, con los ajustes del traspaso (draw o drawId,
`user` del token, export `/export/{drawId}?format=csv`, campos `_id`, `date`, `user`, `draw`; sin instagram) y las
respuestas a-e. Diff: `git diff 3f8ecdf HEAD`. Pruebas escritas antes (`tests/participaciones.php`, 67).
**Datos personales y archivos: revisor principal + uno de seguridad, solo el diff.** La app va en la parte 2.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Tabla** | hecho | `{prefijo}zn_participaciones` (`draw_id`, `user_id`, `ticket`, `adulto_en`, `date`, `ganador`, `entregado_en`, `nombre`, `correo`), única por sorteo y cuenta (decisión a). La instala `ZN_Instalacion`; `uninstall.php` borra la tabla, la carpeta de tickets y la tarea diaria. |
| **POST** | hecho | Multipart; `draw` o `drawId`; `adult` aceptado o 422 (decisión 6, se guarda la fecha); `ticket` (o `attachment`) obligatorio, de 8 MB como mucho y que sea de verdad JPG, PNG o WebP (`getimagesize`, no el nombre); sorteo inexistente 404, cerrado 422, repetido 422 (también con dos envíos a la vez, por la clave única); `user` ignorado (H21); límite R94. |
| **Fotos** | hecho | Fuera de la web (`zn-privado-tickets/` junto a WordPress) o, si no se puede, `uploads/zn-privado/` con `.htaccess`. Nombre al azar de 32 caracteres; nunca URL pública: `ticket` es la ruta `/draw-participation/{id}/ticket`, que sirve el archivo tal cual (`rest_pre_serve_request`) solo a quien participa, organiza o administra, con `no-store` y `nosniff`. Se vuelve a guardar con GD (sin EXIF: probado con una foto con bloque EXIF y marca de GPS), girada y a 2000 px como mucho (decisión 2). |
| **Ver y exportar** | hecho | `GET` sin `draw`: las mías. Con `draw`: todas para quien organiza y la administración (`ZN_Roles::puede( 'draw_participation:read' )`, R43); a quien participa, solo la suya; otro organizador, 401. Export CSV (UTF-8 con BOM, `;`) con nombre y correo (R45, decisión 5), con las celdas protegidas contra fórmulas; otro formato, 422. |
| **Ganador y entrega** | hecho | `PATCH {winner, delivered}`, a mano (decisión d), quien organiza o la administración (decisión c). Entregar sin ser ganador: 422. Al marcar ganador se copian nombre y correo y se le manda un aviso privado (decisión 4). `/draw/{id}?includesWinners=1` → `winnerNames` con el nombre de pila. |
| **Borrado** | hecho | Tarea diaria `zn_limpiar_participaciones`: entrega de todos los premios + 30 días (decisión 1) o fin + 90 días sin entrega (decisión b): fuera todas las fotos y las participaciones de quien no ganó; a quien ganó se le quitan nombre, correo y foto. |
| **RGPD** | hecho | `delete_user` y borrar el sorteo: todo fuera salvo ganador sin premio entregado (nombre y correo hasta la entrega, sin foto; al entregar a una cuenta ya borrada, se quitan). Exportador (sin la foto) y borrador incluidos (decisión 7). |
| **Pendiente** | parte 2 | La app (pantalla de participar con foto, `expo-image-picker`) y marcar ganador y entrega desde wp-admin y el panel (hoy solo por la API). |

## Decisiones de Luis para las participaciones en sorteos (04-11, vía la maestra; sin programar)

Sus palabras: «1. Tal cual dices. 2. Lo que dices. 3. a) 4. Nombre de pila y aviso privado. 5. Nombre y correo.
6. Solo mayores de edad. 7. Como recomiendas». Se harán después de los bloques de portada y favoritos.

| # | Decisión |
|---|---|
| 1 | La foto del ticket se guarda hasta la entrega del premio más 30 días, y después se borra (Luis, 04-11: «30 días sí»). |
| 2 | Se quitan siempre los datos EXIF (ubicación incluida) al subir la foto. |
| 3 | El comercio que organiza ve el nombre y la foto del ticket de cada participante (R43). |
| 4 | Quien gana sale en la app solo con su nombre de pila (R66) y se le avisa en privado. |
| 5 | La exportación (R45) lleva nombre y correo; sin teléfono ni fotos. |
| 6 | Solo mayores de edad: casilla obligatoria «soy mayor de 18». |
| 7 | Al borrar la cuenta o el sorteo se borra todo (R65), salvo quien ha ganado y aún no ha recibido el premio: se guardan su nombre y su correo hasta la entrega y luego se borran. |

## GET /me (bloque 2, parte A)

Luis (vía la maestra, 04-11): «Bloque 2». Diff: `git diff d579bd6 HEAD`. Pruebas escritas antes (`tests/me.php`, 18).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Ruta** | hecho | `GET /me` en `class-zn-api-usuarios.php` (`yo()`): la cuenta del token con los mismos campos que `GET /user/{id}` y además `permissions` (de `ZN_Roles::permisos()`, sin `zn:`; nunca `read` ni `zn_panel_ver`). Sin sesión, cuenta de wp-admin o con dos roles (R37): 401 «Unauthenticated.». `no-store`. El Laravel no tiene esta ruta (se le pidió, 6.2): la forma es nueva, imitando la de `/user/{id}`. |
| **Solo para enseñar** | nota | Los permisos sirven a la app para enseñar u ocultar botones; cada ruta sigue comprobando con `ZN_Roles::puede()`. |
| **App** | hecho | `Zona-Nautico`: la sesión pide `/me` tras entrar y al restaurar, y `puede()` mira esos permisos en lugar de la tabla copiada. |

## Notas de la ronda 37 (R119-R121)

Luis (vía la maestra, 04-11): «Notas R119-R122». R122 (CLAUDE.md de la app) espera su aclaración.

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R119** Cuentas sin rol al desinstalar | hecho | README, «Instalar, actualizar y desinstalar»: las cuentas de la app se quedan sin un rol que exista (no pueden hacer nada) hasta reinstalar, cuando lo recuperan solas; en el servidor real no se desinstala, se desactiva. |
| **R120** Cuentas con dos roles | hecho | `migrar_meta_cuentas()` salta las cuentas cuyo `ZN_Roles::rol_app()` es nulo (un rol de la app y otro de WordPress no es una cuenta de la app, R37): su `phone` se queda como está. Prueba escrita antes (fallaba): `tests/instalacion.php`, ahora 28. |
| **R121** Puerto de Local | hecho | README: `http://localhost:10004` en este PC (lo saca `todas.mjs` de Local). Las `.mjs` siguen con `10010` como valor por defecto si no se les pasa `ZN_URL` ni la dirección; `todas.mjs` siempre se la pasa. |

## Instalación, desinstalación y prefijo zn_ (R68)

Luis (vía la maestra, 04-11): bloque 1, parte C. Diff: `git diff 237719b HEAD`. Pruebas escritas antes
(`tests/instalacion.php`, 27). **Toca la instalación y datos de cuentas: revisor principal + uno de seguridad, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Una sola rutina** | hecho | `ZN_Instalacion::instalar()` en `plugins_loaded` y al activar: tablas de tokens y de intentos (cada una con su versión de tabla, como antes) y, una vez por versión (`zn_version`), los cambios de datos. `ZN_Tokens` y `ZN_Limite` ya no se enganchan cada uno por su cuenta. Los roles siguen en `init` (`ZN_Roles::actualizar`, con `zn_roles_version`): necesitan los tipos de entrada registrados. |
| **`uninstall.php`** | hecho | Solo al borrar el plugin desde wp-admin (`WP_UNINSTALL_PLUGIN`); no carga el plugin, solo roles, tokens, límite e instalación. `desinstalar()`: `DROP TABLE` de las dos tablas, `delete_option` de `OPCIONES` (`zn_version`, `zn_tokens_tabla`, `zn_limites_tabla`, `zn_roles_version`, `zn_huella_relleno`), avisos temporales `zn_avisos_*` y `zn_panel_avisos_*`, `remove_role` de los 5 roles y, en todos los roles, las capacidades del plugin (`zn:…`, `zn_panel_ver` y las de `zn_negocio(s)`, `zn_evento(s)`, `zn_sorteo(s)`). No borra contenido ni cuentas. Une R26 y R52 (instalación y limpieza). |
| **Prefijo `zn_`** | hecho | Cuentas: `phone`, `latitude`, `longitude` → `zn_phone`, `zn_latitude`, `zn_longitude` (esquema `cuenta`, registro, `PATCH` y `GET /user/{id}`, exportar y borrar datos personales). `migrar_meta_cuentas()` las pasa una vez (solo cuentas con rol de la app; si ya hay valor nuevo, gana el nuevo; la vieja se quita). Los negocios conservan `phone`/`latitude`/`longitude` (columnas del Laravel). |
| **Pruebas** | hecho | `tests/instalacion.php` (27): no desinstala de verdad (borraría el Local); comprueba que las listas de `desinstalar()` cubren todo lo que escribe el plugin (busca `update_option`/`add_option` y `set_transient` en el código), que `uninstall.php` tiene la guarda y la migración. `mis-datos`, `registro` y `privacidad` usan ya las claves `zn_`; `tokens` y `limite` miran el gancho de `ZN_Instalacion`. |
| **Probar el `uninstall.php` de verdad** | pendiente de Luis | Solo se puede borrando el plugin en un WordPress (en el Local, perdería tokens, intentos y roles hasta reactivar). Propuesta: hacerlo en un sitio de Local nuevo y vacío, o al montar el servidor de pruebas. |

## Solo el nombre de pila (R66)

Luis (vía la maestra, 04-11): bloque 1, «R66, R68 y D4, D6». Diff: `git diff 89521b9 HEAD`. Pruebas escritas antes
(`tests/nombre-de-pila.php`, 22). **Toca datos personales: revisor principal + uno de seguridad, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Nombre de pila** | hecho | `ZN_API::usuario_publico()` (la única salida pública de cuentas: `includesUser` de reseñas, preguntas, negocios, novedades y sorteos): si el rol es `user`, `nombre_de_pila()` (primera palabra; «Ana María López» → «Ana»); también el nombre guardado en comentarios sin cuenta. `nombre_publico()` sigue entero para `/user/{id}` y el panel. |
| **Comercio, asociación, federación** | hecho (Luis: «Sí, nombre completo») | Salen enteros: su «nombre» es el del negocio o entidad («Bar do Porto»; cortarlo daría «Bar»). `tests/seguridad.php` ya lo esperaba así. |
| **`/review?user=`** | hecho | Solo con el token de esa misma cuenta («Mis reseñas», `da70e6a`): sin sesión 401 «Unauthenticated.»; otra cuenta (o una que no existe) 401 «This action is unauthorized.». Con `Cache-Control: no-store, private`. Sin `?user=`, sigue público. La app manda ya el token en esa lectura (`Zona-Nautico`, mismo bloque). |
| **Pruebas** | hecho | `tests/nombre-de-pila.php` (22, también por HTTP); `contrato-resenas.mjs`: `?user=` sin token da 401 (antes, lista vacía). |

## Cambio de equipo (ronda 31)

Commit `3013a0b`, solo `tests/`: `todas.mjs` lee de `sites.json` de Local el PHP, la carpeta `run`, el puerto de
MySQL y la ruta del sitio, y pregunta la dirección al propio WordPress (`ZN_URL` y `ZN_SITIO_LOCAL` para
cambiarlos); las `.mjs` aceptan `ZN_URL`; `tokens.php` simula la subcarpeta sobre la portada real; `comentarios.php`
toma el dueño de `user_id`. En el PC nuevo, 1074 bien en 24 juegos. El revisor la dio por buena sin fallos.

## Eliminar cuenta-bis (R110, R111)

Luis (vía la maestra, 04-11): R110 «Sí, se debe pedir la contraseña actual para poder borrar la cuenta, como cuando
quieres cambiar la contraseña»; R111, aviso «Tu cuenta de Zona Náutico se ha borrado. Si no has sido tú, escríbenos
a…», y «De momento no hay correo de contacto, eso lo añadiremos al final». Diff: `git diff a7332e4 HEAD`. Pruebas
escritas antes (`tests/borrar-cuenta.php`, ahora 39).

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R110** Contraseña actual | hecho | `borrar()` pide `currentPassword` en el cuerpo, como `cambiar()`: tras el permiso y el límite de escrituras, sin ella (o "") 422 «The current password field is required.», si no es texto 422 `string`; luego `ZN_Limite::intentar( 'datos' )` (429; 5 malas desde una IP bloquean aunque la 6.ª sea buena) y `wp_check_password` (mala: 422 «The password is incorrect.»). Con la buena se olvidan los intentos. Sin sesión, 401 antes de mirar nada. |
| **R111** Correo de aviso | hecho | Tras `wp_delete_user()`, `wp_mail()` a su correo: «Zona Náutico: tu cuenta se ha borrado», texto de `ZN_API_Usuarios::texto_cuenta_borrada()`. La frase «Si no has sido tú, escríbenos a …» solo con un correo válido en `ZN_CORREO_CONTACTO` (como `linea_contacto()` de R103); sin él, no aparece. Fuera de producción solo sale a `@ejemplo.invalid` (R57). Anotado en el README, en «Subir al servidor». |
| **App** | hecho | `Zona-Nautico`: campo «Contraseña actual» en la confirmación de borrar la cuenta, mandado como `currentPassword` en el DELETE. |

## Eliminar cuenta (DELETE /user/{id})

Luis (04-11, 09:02): sí a `DELETE /user/{id}`. La app lo llama para «Eliminar cuenta» (`Zona-Nautico/src/api/user.ts:63`)
y el plugin no lo tenía (D1). Diff: `git diff 3013a0b HEAD`. Pruebas escritas antes (`tests/borrar-cuenta.php`).
**Borra cuentas y datos: revisor principal + uno de seguridad, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Ruta** | hecho | `DELETE /user/{id}` en `class-zn-api-usuarios.php` (`borrar()`), con `con_sesion_de_la_app` en la entrada. Cuenta que no existe o que no es de la app → 404 «Not found.». 200 `{status: "success"}` (lo que espera la app), `no-store`. |
| **Quién (R44)** | hecho | Solo la propia cuenta y solo si su rol tiene `user:delete_self` (hoy, solo `user`). Se mira el permiso exacto, no `ZN_Roles::puede( 'user:delete' )`, porque la administración tiene `user:delete` general y con él se borraría a sí misma. Otra cuenta (también la padre o la administración), comercio, asociación, federación o administración → 401 «This action is unauthorized.». |
| **Cascada (R65, R95, R96)** | hecho | `wp_delete_user()` sin reasignar: los ganchos `delete_user` de `ZN_Privacidad` (reseñas y preguntas fuera, puntuaciones recalculadas; negocios, novedades y sorteos a borrador y sin dueño) y de `ZN_Tokens` (todos sus tokens anulados) son los mismos que al borrar en Usuarios. Las entradas de la app tienen `post_author` 0, así que WordPress no borra nada más por detrás. |
| **Límite (R94)** | hecho | `ZN_API::limite_de_escritura()` antes de borrar: pasado el tope, 429 «Too Many Attempts.» con `Retry-After` y la cuenta sigue. No pide la contraseña actual (la app no la manda). |
| **Correos** | hecho | No manda ninguno (probado). |
| **Pruebas** | hecho | `tests/borrar-cuenta.php` (26), también por HTTP con tokens de verdad; añadida a `tests/todas.mjs`. |

Pregunta abierta para Luis: ¿un aviso por correo de «tu cuenta se ha borrado»? Ahora no se manda nada (R111).

Ronda 32: revisada sin fallos. Pruebas pasadas en Local el 04-11, de una en una: `borrar-cuenta.php` 26 bien,
`privacidad.php` 29 bien, `mis-datos.php` 78 bien (y `tokens.php` 60, `roles.php` 90), todas «Todo bien». No se pasó
`todas.mjs` completa. R110 (pedir la contraseña para borrar) y R111 (el correo), pendientes de Luis.

## Cambiar mis datos-bis (R105-R108)

Ronda 29: sin fallos importantes. Luis (02-11, 13:42): sí a las cuatro, como se recomendaron. Diff: `git diff f5ee0b4 75fc042`.
Pruebas escritas antes (5 fallaban). Luis decidió también (13:39) que nombre y teléfono **no** pidan la contraseña actual.
**Un solo revisor, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R105** Correos a cualquier dirección | hecho | Regla nueva `ZN_Limite::REGLAS['correo']` = 3 por cuenta al día; se cuenta tras la contraseña actual buena y el `unique`, antes de mandar nada (429 sin correo ni cambio del pendiente). El correo nuevo dice «Si no tienes cuenta en Zona Náutico o no lo has pedido tú, ignora este correo y no pulses nada». |
| **R106** Cancelar el pendiente | hecho | `email` = su correo actual (sin distinguir mayúsculas) → `anular_cambio_de_correo()`; sin pedir la contraseña actual y sin correos. |
| **R107** Aviso al confirmar | hecho | Tras confirmar, al correo viejo: «el correo de tu cuenta ha cambiado» (sin el nuevo). |
| **R108** Carrera | hecho | Tras `wp_update_user`, si hay más de una fila en `users` con ese correo, se vuelve al correo viejo y 400 `{status: "error"}`. Probado colando otra fila en `profile_update`. |
| `tests/limite.php` | ajuste | La fila caducada de R77 se crea pasada la ventana más larga de `REGLAS` (ahora un día), no 2 horas fijas. |

`node tests/todas.mjs`: 1073 bien en 24 juegos; solo cayó la de R77 en `limite.php` por la ventana nueva; ajustada y
repetida: «Todo bien».

## Cambiar mis datos (R69)

Luis (02-11, 13:23): sí a «cambiar mis datos». Diff: `git diff 6a197c2 2fa7c1d`. Pruebas escritas antes
(`tests/mis-datos.php`). **Toca cuentas, contraseñas y correos: revisor principal + uno de seguridad, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Ruta** | hecho | `PUT\|PATCH /user/{id}` en `class-zn-api-usuarios.php` (`cambiar()`), con `con_sesion_de_la_app` en la entrada. Solo la propia cuenta; otra (también la administración) o `roleId` → 401 «This action is unauthorized.»; cuenta que no existe → 404. Límite de escrituras de la cuenta (R94). 200 `{data}` como `GET /user/{id}`, `no-store`. |
| **Campos** | hecho | `name`, `phone`, `email`, `password` con el esquema `cuenta` (R58). Ausente = no tocar; null borra solo el teléfono (nombre, correo y contraseña → 422); "" → 422 (decisión a). Lo demás (ubicación, código, `verified`…) se ignora. Nombre en `first_name`, apellidos vacíos (R53). |
| **R69 = A** Contraseña actual | hecho | Correo o contraseña piden `currentPassword`: sin ella 422 «The current password field is required.»; mala 422 «The password is incorrect.». Orden: formato (422, no gasta intentos) → `ZN_Limite::intentar( 'datos' )` (cuenta+IP 5/15 min, cuenta 20/h; 429) → `wp_check_password`. Nombre y teléfono, sin ella (pregunta abierta a Luis). |
| **Correo ocupado** | hecho | El `unique` del correo se mira solo después de la contraseña actual buena: con la mala, el error es solo `currentPassword`. Su mismo correo (sin distinguir mayúsculas) = nada que hacer. |
| **Contraseña** | hecho | `wp_set_password()`: el gancho de `ZN_Tokens` anula los demás tokens y deja el de quien la cambia (R56). Borra el código de recuperación y el cambio de correo pendiente (gancho `wp_set_password` en `ZN_API_Cuenta`, también al recuperar con código). Aviso por correo sin la contraseña. |
| **Correo nuevo** | hecho | Queda pendiente (`zn_email_nuevo`, huella SHA-256 del token de 32 bytes, 48 h). Al nuevo, enlace `GET /verify-email?id=&token=` (página con botón, como R88; `&format=json` da JSON); al viejo, aviso sin enlace. `POST /verify-email` confirma: cuenta de la app, no de baja, token vigente; si otra cuenta se ha quedado el correo entre medias → 400 y se anula. `wp_update_user` sin el correo propio de WordPress; se anulan **todos** los tokens (se vuelve a entrar con el correo nuevo); sigue verificada. Un solo uso. |
| **Privacidad** | hecho | Exportador: «Correo nuevo sin confirmar». Borrador: quita el pendiente y su token. |

`node tests/todas.mjs`: **1067 bien en 24 juegos, todo bien a la primera** (`mis-datos` nuevo).

## Panel R40, parte 2b-bis (R102, R103, R104)

Ronda 27: 2b sin fallos importantes. Luis (02-11, 14:12): R102 dejarlo así (A), R103 sí con el correo de contacto
**pendiente**, R104 sí. Diff: `git diff 0886af0 2864e30`. Pruebas escritas antes (fallaban). **Un solo revisor, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R102** Baja de una asociación | hecho (Luis: A) | Sin cambio de comportamiento: sus comercios se quedan sin gestor en el panel hasta que la administración los reasigne. El `confirm()` de la federación lo dice (texto con `esc_js`). README. |
| **R103** Correo de alta | hecho, correo pendiente | `ZN_Panel::linea_contacto()`: «Si no conoces a quien te ha dado de alta… escribe a <ZN_CORREO_CONTACTO>»; sin la constante (o si no es un correo), «avisa a Zona Náutico» sin dirección. `nombre_para_correo()`: sin etiquetas, sin nada con `://`, `www.` ni forma de dominio, una sola línea, 100 caracteres como mucho. |
| **R104** Códigos a cuentas dadas de baja | hecho | `ZN_API_Cuenta::cuenta_de_la_app()` ignora las cuentas con `zn_desactivada`: no reciben código ni reenvío de verificación; la respuesta sigue siendo la de siempre (200). |

`node tests/todas.mjs`: todas las de PHP bien (`panel` 177); `contrato-negocios`, `contrato-eventos` y
`contrato-sorteos` fallaron en bloque por HTTP (el PHP de Local reiniciándose) y, repetidas una a una, **las tres
«Todo bien»**. 20 juegos limpios a la primera y 3 repetidos bien.

## Panel R40, parte 2b: cuentas (alta, renombrar, baja)

Luis (02-11, 13:18 y 13:56): sí al 2b; **alta con correo** y **baja desactivando**. Diff: `git diff a26168e 1611c4f`.
Pruebas escritas antes (fallaban). **Toca cuentas y datos personales: revisor principal + uno de seguridad, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Quién y a quién** | hecho | `ZN_Panel::ROL_HIJO`: la asociación gestiona solo `zn_comercio`; la federación, solo `zn_asociacion`. `gestionable( yo, id, 'save'\|'delete' )`: un único rol y ese, `zn_parent_id` = él, no desactivada y `ZN_Roles::puede( 'user:<acción>' )` (user:save_/delete_<rol> de la app). Ni usuarios normales, ni a sí mismo, ni el 2.º nivel de la federación. Alta: `puede_dar_de_alta()` = `user:register-<rol>`. |
| **Alta** | hecho | `dar_de_alta( yo, {name, email} )`: límite de escrituras de la app (R94) antes de nada; nombre y correo con el esquema de cuenta (`comprobar`, avisos en español) y correo no repetido. Como `/register`: `user_login` y `display_name` `zn…` al azar, nombre en `first_name` (R53). **Contraseña al azar de 32 que nadie conoce, sin `zn_verificado`.** `zn_parent_id` = él. Correo «se ha creado tu cuenta… pulsa "Olvidé la contraseña"» (texto real del botón de la app, `acceso.olvido`); el código de `/reset-password` pone la contraseña y verifica el correo. Fuera de producción solo sale a @ejemplo.invalid (R57). |
| **Renombrar** | hecho | `renombrar()`: solo el nombre (`first_name`; `last_name` vacío). El correo no se cambia en el panel (lo cambia su dueño desde la app, R69). Cuenta para el límite. |
| **Baja = desactivar** | hecho | `desactivar()`: meta `zn_desactivada`; `ZN_Tokens::anular_todos()` y `destroy_all()` de sus sesiones; sus negocios, novedades y sorteos (publicados, programados, pendientes o privados) a borrador. No se borra nada. `POST /login` la trata como contraseña mala (mismo 401); `puede_entrar()` la deja fuera del panel; sus cosas ya no se editan desde el panel (`editable()`). |
| **Reactivar** | hecho | Solo la administración: enlace «Reactivar» en Usuarios (`user_row_actions`, `edit_users`), `admin-post.php?action=zn_reactivar` con nonce `zn_reactivar_<id>` y `edit_users`. Sus cosas siguen en borrador (las publica la administración, como R99). |
| **Formularios** | hecho | `admin.php?page=zn-panel&cuenta=nueva\|<id>`, POST con `zn_hacer` (alta\|guardar\|baja), nonce `zn_panel_cuenta_<nueva\|id>`, vuelta con GET. Sin campo de contraseña; el correo solo en el alta. Tabla de cuentas con columna «Estado» (Activa / Sin verificar / Desactivada) y «Editar» solo en las que gestiona. |

`node tests/todas.mjs`: **991 comprobaciones bien en 23 juegos** (`panel` 172). Por HTTP, una vez, con la cuenta de
prueba: alta → 302 y «Cuenta creada»; sin sello → 403. Queda en Local la cuenta inventada «Náutica del muelle
(prueba)» (muelle-prueba@ejemplo.invalid), hija de asociacion-prueba, sin verificar.

## Panel R40, parte 2a-bis (R99, R100, R101)

Ronda 25: 2a sin agujeros. Luis (02-11, 13:08 y 13:10): **R99 = B** (desde el panel no se publica; solo pasar a
borrador), **R100 = A** (papelera, también para novedades y sorteos) y R101 sí.
Diff: `git diff f940cec fe75dbb`. Pruebas escritas antes (7 fallaban). **Un solo revisor, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R99** Publicar | hecho (Luis: B) | `guardar_edicion()`: `estado = publish` solo vale si ya está publicado (no es un cambio); si no, aviso «Publicar lo hace la administración» y no cambia. El formulario solo ofrece «Publicado» si ya lo está. Pasar a borrador, sí. |
| **R100** Borrar | hecho (Luis: A) | `borrar()`: `wp_trash_post()` en los tres tipos (se recupera desde wp-admin). Si es un negocio, sus novedades con ese `business_id` (publicadas, programadas, pendientes o privadas) pasan a borrador; reseñas y preguntas se quedan con él. Lo que está en la papelera no se edita ni se vuelve a borrar (`editable()`). Texto del botón cambiado. |
| **R101** Fechas | hecho | El «fin antes del inicio» se comprueba con `ZN_Esquema::validar()` con lo guardado, como el `PATCH` de la API; si falla, no cambia ninguna de las dos fechas. |

`node tests/todas.mjs`: **947 comprobaciones bien en 23 juegos** (`panel` 128).

## Panel R40, parte 2a: editar el contenido desde «Mi zona»

Luis (02-11, 12:56): sí a la parte 2 por trozos; este es el **2a, contenido**. Después irán 2b (cuentas) y 2c
(fotos), cada uno con su sí. Diff: `git diff 00b7732 9eb02bc`. Pruebas escritas antes (fallaban).
**Como escribe desde wp-admin: revisor principal + uno de seguridad, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Quién edita qué** | hecho | `ZN_Panel::editable( yo, id, 'save'\|'delete' )`: solo negocio, novedad o sorteo, no en la papelera ni `auto-draft`, y `ZN_Roles::puede( '<recurso>:<acción>', entrada, yo )`, es decir, exactamente lo que la app le deja (un nivel; en sorteos sin herencia). Lo del segundo nivel de la federación se ve pero no se edita. Comercios y demás, nada (`puede_entrar`). |
| **Formulario** | hecho | `admin.php?page=zn-panel&editar=<id>`; se envía por POST a la misma dirección con `zn_hacer` (guardar\|borrar), nunca `action` (R97). Se procesa en `load-toplevel_page_zn-panel` con `check_admin_referer( 'zn_panel_<id>' )` y vuelve con GET (avisos por transient de 60 s). Todo escapado (`esc_attr`, `esc_textarea`, `esc_html`, `esc_url`). |
| **Campos** | hecho | Solo los de `ZN_Panel::CAMPOS` (textos, categoría, descripción/horario, fechas, ganadores, bases). Sin dueño (aunque se mande, no se lee), fotos, vídeos, coordenadas ni negocio de la novedad. Cada valor con `ZN_Esquema::comprobar()` y aviso en español (`aviso()`); vacío = borrar si no es obligatorio. Web y bases sin protocolo → `https://`. Fechas del calendario a `Y-m-d H:i:s`; si el fin queda antes del inicio (con lo guardado), no cambia ninguna fecha. Guarda con `ZN_Esquema::guardar()` (R80; `post_author` sigue en 0). |
| **Estado** | hecho | Solo «Publicado» o «Borrador» (otro valor: aviso). Si estaba en otro estado (pendiente, privado), sale «(sin cambiar)». **Para Luis:** así una asociación puede publicar un borrador de su comercio; si un borrador significa «pendiente de aprobar por la administración», habría que quitarlo. |
| **Borrar** | cambiado en 2a-bis | `ZN_Roles::puede( '<recurso>:delete' )`; ahora a la papelera (R100). |
| **Límite** | hecho | `ZN_Limite::intentar( 'escritura', correo )` antes de mirar los datos: las 20 por hora y cuenta de la app (R94), compartidas con la app. Borrar no cuenta (como en la app). |

`node tests/todas.mjs`: **938 comprobaciones bien en 23 juegos** (`panel` 119). Por HTTP, una vez, con la cuenta
de prueba: el formulario sale con su sello; guardar → 302 y «Cambios guardados»; sin sello → 403.

## Panel R40: nota de la ronda 24 (correo del segundo nivel)

Ronda 24: 1-ter OK. Sobre la nota de privacidad, Luis (02-11, 12:47): la federación sigue viendo los comercios de
sus asociaciones, pero **sin su correo**. Diff: `git diff 42d15c8 b7f2297`. Prueba escrita antes (fallaba).

| Punto | Estado | Qué se hizo |
|---|---|---|
| Correo en «Mi zona» | hecho | Solo si `ZN_Roles::puede( 'user:read', cuenta, yo )`: la federación ve el de sus asociaciones (`user:read_association`), no el de los comercios de estas (sale «—»); la asociación sigue viendo el de los suyos. Así nadie ve más datos personales que en el Laravel. |

`node tests/todas.mjs`: **907 comprobaciones bien en 23 juegos** (`panel` 88). Cuenta de prueba nueva en Local:
`federacion-prueba@ejemplo.invalid`, madre de `asociacion-prueba` (comprobado una vez por HTTP: ve la
asociación con su correo y el comercio sin él).

## Panel R40, parte 1-ter (la federación ve dos niveles)

Ronda 23: 1-bis OK. Luis (02-11, 12:38) eligió **A**: en el panel la federación VE dos niveles (sus asociaciones
y los comercios de estas), solo para leer; editar, en la parte 2, un nivel como en la app.
Diff de la ronda: `git diff c57d68e 2cece16`. Pruebas escritas antes (4 fallaban). **Un solo revisor, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Cuentas** | hecho | `ZN_Panel::cuentas()`: las hijas que puede leer (como antes) y, si es federación, los **comercios** (no los usuarios) que cuelgan de sus asociaciones (`hijas()`, una consulta con `IN`). Tres niveles, no. La asociación sigue con uno. |
| **Contenido** | hecho | Negocios, novedades y sorteos de él y de todas esas cuentas (sale solo de `cuentas()`). |
| **Página** | hecho | Columna «Depende de» en las cuentas (nombre de pila de la cuenta padre, o «Tú»). |
| Permisos | sin cambios | `ZN_Roles::puede()` no cambia: solo es lo que se ve en el panel. |

`node tests/todas.mjs`: **904 comprobaciones bien en 23 juegos** (`panel` 85).

## Panel R40, parte 1-bis (R97, R98 y nombres)

El revisor dio por buena la parte 1 (ronda 22). Luis (02-11, 12:33): R97 sí, **R98 = A**, nombres sí.
Diff de la ronda: `git diff 3cb5532 b11dd54`. Pruebas escritas antes (5 fallaban). **Un solo revisor, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **R97** Página por `$_GET` | hecho | `ZN_Panel::pagina_permitida( $pagina, $get, $post )`: en `admin.php`, `page` solo de la dirección y ninguna `action` ni en la dirección ni en el cuerpo; en `admin-ajax.php`, la `action` como la lee WordPress (cuerpo antes que dirección). `vigilar()` le pasa `$_GET` y `$_POST`. |
| **R98** Sesión del panel al entrar en la app | hecho (Luis: A) | `POST /login` solo hace `destroy_all()` si la cuenta no puede entrar al panel (`ZN_Panel::puede_entrar`). La cookie del panel sigue sin valer en `/zonanautico/v1`. Prueba: asociación y federación con una sesión de WordPress → login 200 y la sesión sigue; comercio → se cierra (R39). |
| **Detalle** Nombres | hecho | «Mi zona» usa `ZN_API::nombre_publico()` (nombre y apellidos, R53/R10), nunca `display_name`; las cuentas se ordenan por id. |

### Resultado de las pruebas (parte 1-bis, 02-11)

`node tests/todas.mjs`: **900 comprobaciones bien en 23 juegos** (`panel` 81; la prueba ahora crea las cuentas
como `/register`, con `display_name` interno y el nombre en `first_name`).

## Panel de wp-admin para asociación y federación (R40, parte 1: solo ver)

Luis dio el sí (02-11, 11:09) solo a la **parte 1**: entrar y ver lo suyo, sin editar nada. La parte 2
(editar) necesita otro sí. Diff de la ronda: `git diff cfc3eb8 20959b2`. Pruebas escritas antes
(`tests/panel.php` y el cambio de `tests/roles.php` fallaban). **Revisor principal + uno de seguridad, solo el diff.**

| Punto | Estado | Qué se hizo |
|---|---|---|
| **Quién entra** | hecho | `ZN_Panel::puede_entrar()`: rol único `zn_asociacion` o `zn_federacion` (R37) y `zn_verificado = 1`. Esos dos roles reciben de WordPress solo `read` y `zn_panel_ver` (`ZN_Roles::ROLES_PANEL`, `PERMISOS_PANEL`); los otros tres siguen sin ninguno. `ZN_Puertas::sin_panel_para_la_app` (antes de mirar la contraseña) deja pasar solo a esas cuentas y llama a `ZN_Limite::intentar( 'login', correo )`: wp-login.php y `POST /login` cuentan juntos; al entrar bien (`wp_login`) se olvidan, como en la app. Mismo aviso genérico para todo. «¿Has olvidado tu contraseña?» sigue cerrado: se recupera desde la app. |
| **Qué ve** | hecho | Página «Mi zona» (`admin.php?page=zn-panel`). «Lo suyo» = él y las cuentas que cuelgan directamente de él y puede leer (`ZN_Roles::puede( 'user:read', … )`: asociación → sus comercios y usuarios; federación → sus asociaciones; un nivel, como los permisos de la app). Tablas de esas cuentas (nombre, correo, tipo, verificada) y de negocios, novedades y sorteos cuyo `user_id` es él o una de ellas (también borradores; sin papelera). Máximo 200 filas por tabla. Todo con `esc_html`, sin enlaces ni formularios. |
| **Nada más de wp-admin** | hecho | `admin_init` (prioridad 0): una cuenta de la app que ya no puede entrar (otro rol, sin verificar) se queda sin sesión; si puede, solo su página y el latido de admin-ajax; lo demás (escritorio, perfil, admin-post, otras acciones de admin-ajax) vuelve al panel o da 403. Las páginas de tipos y ajustes ya las corta WordPress por permisos (403). Sin «Escritorio» ni «Perfil» en el menú; la barra de arriba sin perfil, sitio ni «Añadir»; solo «Salir». Sin barra en la parte pública. Al entrar va directo al panel. |
| **Sin editar** | hecho | `map_meta_cap`: `edit_user` denegado a toda cuenta de la app, también sobre sí misma (perfil y `/wp/v2/users/me`). Sin `edit_posts` ni permisos de los tipos (comprobado en la prueba). |
| **Cookie y API de la app** | hecho | `ZN_Tokens::usuario_del_token`: en `/zonanautico/v1` la cookie de una cuenta de la app no abre sesión (allí solo el token); la de un administrador de WordPress, como antes. Así la sesión del panel no sirve para escribir por la API (ni con el nonce de wp-admin). |
| Aviso | resuelto en 1-bis | `POST /login` cerraba también la sesión del panel (R39). Ahora no (R98 = A). |

### Resultado de las pruebas (panel, 02-11)

`node tests/todas.mjs`: **894 comprobaciones bien en 23 juegos** (`panel` 75) (`panel` nueva, `roles` cambiada: asociación y federación tienen `read` y
`zn_panel_ver`). Comprobado también por HTTP, una vez, con la cuenta de prueba: wp-login → panel; `profile.php` →
panel; `edit.php?post_type=negocio` → 403; la página no enlaza a `profile.php` ni a crear nada.

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
| **R58, R59** | hecho (después) | Segundo bloque, `100e42b` (v0.13.0); ver «Segundo bloque». |
| **R62, R65** | hecho (después) | R62 con el registro (Login B, `f1722fd`); R65 con «Escrituras y RGPD» (`3e598f5`). |
| **R66** | hecho (después) | Bloque 1, parte B (v0.24.0); ver «Solo el nombre de pila (R66)». |

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

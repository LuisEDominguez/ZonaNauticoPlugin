# Respuesta a la revisión del plugin Zona Náutico

Lo escribe solo el hilo que desarrolla; es la respuesta a `zonanautico-plugin-revision/REVISION.md`
(que escribe solo el hilo revisor). Un único archivo que se actualiza en cada ronda.

**Para los revisores:** cada punto dice qué se hizo y en qué commit, o por qué sigue pendiente. Con
esto podéis marcar en `REVISION.md` qué queda «arreglado» o «descartado». Ningún cambio se aplica sin
el sí de Luis.

Estados: **hecho** (pendiente de que el revisor lo compruebe) · **pendiente** · **espera decisión** ·
**se propone descartar**.

## Historial

| Ronda | Fecha | Commit revisado | Respuesta en commit | Resumen |
|---|---|---|---|---|
| 1 | 30-10-2026 | `ea9c0bc` (v0.7.0) | `47e5ca8` (v0.8.0) | Bloque 1 (graves y lo urgente de importantes) hecho; el resto, pendiente o a decidir |

---

## Ronda 1

### Pruebas que pidieron los revisores (hechas en Local; datos temporales borrados)

| Prueba | Resultado |
|---|---|
| R11: negocio con contraseña → `GET /business/{id}` y `/business` | **Confirmado**: salía en los dos (200) |
| R19: reseña sin meta y después `add_comment_meta score=5` | **Confirmado**: la nota se quedaba en 1.5 (debía ser 4) |
| R3: usuario Autor de prueba | **Confirmado**: creaba y publicaba negocios y editaba los suyos (no los ajenos); `ZN_Admin_Eventos::limpiar` aceptaba un negocio ajeno |

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
| **R12** Formato de errores | pendiente | De acuerdo. Obligatorio antes de cualquier escritura; se hará al empezar esa fase. |
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

<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Piezas de las escrituras: el dueño con permisos (R79) y el guardado común (R80).
// Uso: wp eval-file tests/escrituras.php. Crea cuentas, negocios, novedades y comentarios «temporal-escrituras»
// y los borra al terminar.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

$cuentas  = array();
$entradas = array();
$cuenta   = static function ( string $rol_wp, int $padre = 0 ) use ( &$cuentas ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-temporal-escrituras';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => 'Temporal' ) );
	if ( $padre ) {
		update_user_meta( $id, 'zn_parent_id', $padre );
	}
	$cuentas[] = $id;
	return $id;
};
$v = static fn( string $recurso, array $datos, ?WP_Post $elemento, int $quien ) => ZN_Esquema::permitir_dueno(
	$recurso,
	ZN_Esquema::validar( $recurso, $datos, $elemento ? array() : null ),
	$elemento,
	$quien
);

try {
	$admin_app  = $cuenta( 'zn_administrador' );
	$asociacion = $cuenta( 'zn_asociacion' );
	$comercio   = $cuenta( 'zn_comercio', $asociacion );
	$ajeno      = $cuenta( 'zn_comercio' );

	// --- R80: guardar() reparte cada valor a su sitio ---
	$r  = ZN_Esquema::validar( 'negocio', array( 'name' => 'Negocio temporal-escrituras', 'description' => "Línea 1\nLínea 2", 'userId' => $comercio, 'city' => 'Vigo', 'photos' => array( 'https://ejemplo.invalid/a.jpg' ) ) );
	$id = ZN_Esquema::guardar( 'negocio', null, $r['valores'], array( 'post_status' => 'publish' ) );
	$entradas[] = $id;
	$entrada    = get_post( $id );
	$ok( 'R80: crear negocio: título, contenido, publicado y del tipo negocio', is_int( $id ) && 'Negocio temporal-escrituras' === $entrada->post_title && "Línea 1\nLínea 2" === $entrada->post_content && 'publish' === $entrada->post_status && ZN_Negocios::TIPO === $entrada->post_type );
	$ok( 'R80: y sus campos personalizados (dueño, ciudad, foto)', $comercio === ZN_Negocios::dueno( $id ) && 'Vigo' === get_post_meta( $id, 'city', true ) && 'https://ejemplo.invalid/a.jpg' === get_post_meta( $id, 'photo_0', true ) );
	$ok( 'R80: con comillas y barras no se pierde nada (wp_slash)', is_int( ZN_Esquema::guardar( 'negocio', $id, array( 'address' => 'Rúa "O" \\ 1' ) ) ) && 'Rúa "O" \\ 1' === get_post_meta( $id, 'address', true ) );
	ZN_Esquema::guardar( 'negocio', $id, array( 'city' => null, 'post_title' => 'Negocio temporal-escrituras 2', 'inventado' => 'x' ) );
	$ok( 'R80: PATCH: null borra, el título cambia y un campo que no está en el esquema no se escribe', '' === get_post_meta( $id, 'city', true ) && 'Negocio temporal-escrituras 2' === get_the_title( $id ) && '' === get_post_meta( $id, 'inventado', true ) );
	$borrador   = ZN_Esquema::guardar( 'negocio', null, array( 'post_title' => 'Borrador temporal-escrituras' ) );
	$entradas[] = $borrador;
	$ok( 'sin post_status, una entrada nueva queda en borrador (un olvido no publica nada)', is_int( $borrador ) && 'draft' === get_post_status( $borrador ) );
	$ok( 'R80: no guarda en una entrada de otro tipo', is_wp_error( ZN_Esquema::guardar( 'sorteo', $id, array( 'winners' => 2 ) ) ) );

	$resena = ZN_Esquema::guardar( ZN_Comentarios::RESENA, null, array( 'comment_post_ID' => $id, 'comment_content' => 'me encantó <3', 'score' => '5' ), array( 'user_id' => $ajeno, 'comment_author' => 'Temporal' ) );
	$ok( 'R80: reseña nueva: comentario sin aprobar, con su puntuación', is_int( $resena ) && '0' === get_comment( $resena )->comment_approved && '5' === get_comment_meta( $resena, 'score', true ) && ZN_Comentarios::RESENA === get_comment( $resena )->comment_type );
	ZN_Esquema::guardar( ZN_Comentarios::RESENA, $resena, array(), array( 'comment_approved' => 1 ) );
	$ok( 'R80: aprobar con wp_set_comment_status(): sus ganchos recalculan la puntuación del negocio', '1' === get_comment( $resena )->comment_approved && 5.0 === (float) get_post_meta( $id, 'score', true ) );
	$pregunta = ZN_Esquema::guardar( ZN_Comentarios::PREGUNTA, null, array( 'comment_post_ID' => $id, 'comment_content' => '¿Abrís?', 'user_id' => $comercio, 'comment_approved' => 1 ), array( 'user_id' => $ajeno ) );
	$ok( 'R80: pregunta: destinatario en su campo, quien pregunta en el comentario, publicada con wp_set_comment_status()', is_int( $pregunta ) && (string) $comercio === get_comment_meta( $pregunta, 'user_id', true ) && $ajeno === (int) get_comment( $pregunta )->user_id && '1' === get_comment( $pregunta )->comment_approved );
	// R82: editar el texto no pasa por kses (como en R20). Se edita como una cuenta de la app (sin unfiltered_html).
	wp_set_current_user( $ajeno );
	kses_init();
	$filtros = $GLOBALS['wp_filter']['wp_update_comment_data'] ?? null;
	$antes   = $filtros ? count( $filtros->callbacks, COUNT_RECURSIVE ) : 0;
	$editada = ZN_Esquema::guardar( ZN_Comentarios::RESENA, $resena, array( 'comment_content' => 'me encantó <3 volveré; menores <12 gratis y >65' ) );
	$ok( 'R82: al editar, «<3 volveré» y «<12 … >65» salen enteros', is_int( $editada ) && 'me encantó <3 volveré; menores <12 gratis y >65' === get_comment( $resena )->comment_content );
	$filtros = $GLOBALS['wp_filter']['wp_update_comment_data'] ?? null;
	$ok( 'R82: y el arreglo no queda puesto después (solo vale durante esa llamada)', $antes === ( $filtros ? count( $filtros->callbacks, COUNT_RECURSIVE ) : 0 ) );
	wp_update_comment( array( 'comment_ID' => $resena, 'comment_content' => 'por otra vía <b>x</b> <3' ) );
	$ok( 'R82: una edición por fuera de ZN_Esquema sigue pasando por kses de WordPress', false !== strpos( get_comment( $resena )->comment_content, '&lt;3' ) );
	wp_set_current_user( 0 );
	$ok( 'R80: no guarda una reseña como pregunta', is_wp_error( ZN_Esquema::guardar( ZN_Comentarios::PREGUNTA, $resena, array( 'answer' => 'x' ) ) ) );

	// --- R79: crear ---
	$r = $v( 'negocio', array( 'name' => 'X', 'userId' => $comercio ), null, $comercio );
	$ok( 'R79 POST: el comercio crea un negocio a su nombre', empty( $r['sin_permiso'] ) && ! $r['errores'] );
	$r = $v( 'negocio', array( 'name' => 'X', 'userId' => $ajeno ), null, $comercio );
	$ok( 'R79 POST: a nombre de otro comercio, no (401)', ! empty( $r['sin_permiso'] ) );
	$r = $v( 'negocio', array( 'name' => 'X', 'userId' => $comercio ), null, $asociacion );
	$ok( 'R79 POST: la asociación, a nombre de su comercio, sí', empty( $r['sin_permiso'] ) );
	$r = $v( 'negocio', array( 'name' => 'X', 'userId' => $ajeno ), null, $asociacion );
	$ok( 'R79 POST: la asociación, a nombre de un comercio que no es suyo, no', ! empty( $r['sin_permiso'] ) );

	// --- R79: modificar ---
	$negocio = get_post( $id );
	$r       = $v( 'negocio', array( 'userId' => $asociacion, 'city' => 'Cangas' ), $negocio, $asociacion );
	$ok( 'R79 PATCH: la asociación que edita el negocio de su comercio no se lo queda (userId ignorado)', ! array_key_exists( 'user_id', $r['valores'] ) && 'Cangas' === $r['valores']['city'] && empty( $r['sin_permiso'] ) );
	$r = $v( 'negocio', array( 'userId' => $ajeno ), $negocio, $comercio );
	$ok( 'R79 PATCH: con _own no se puede regalar el negocio a otro (H15)', ! array_key_exists( 'user_id', $r['valores'] ) );
	$r = $v( 'negocio', array( 'userId' => $ajeno ), $negocio, $admin_app );
	$ok( 'R79 PATCH: la administración de la app sí cambia el dueño', $ajeno === ( $r['valores']['user_id'] ?? null ) );
	$r = $v( 'negocio', array( 'userId' => $comercio ), $negocio, $admin_app );
	$ok( 'R79 PATCH: el mismo dueño que ya tiene no se reescribe', ! array_key_exists( 'user_id', $r['valores'] ) );

	// --- R79 + R70 en novedades ---
	$r = $v( 'evento', array( 'name' => 'Novedad', 'userId' => $ajeno, 'businessId' => $id ), null, $ajeno );
	$ok( 'R70 POST: un comercio no cuelga una novedad suya del negocio de otro (422 en businessId)', isset( $r['errores']['businessId'] ) );
	$r = $v( 'evento', array( 'name' => 'Novedad', 'userId' => $asociacion, 'businessId' => $id ), null, $asociacion );
	$ok( 'R70 POST: la asociación sí, en el negocio de su comercio', ! $r['errores'] && empty( $r['sin_permiso'] ) );
	$novedad    = ZN_Esquema::guardar( 'evento', null, array( 'post_title' => 'Novedad temporal-escrituras', 'user_id' => $comercio, 'business_id' => $id ) );
	$entradas[] = $novedad;
	$r          = $v( 'evento', array( 'userId' => $asociacion, 'name' => 'Cambio' ), get_post( $novedad ), $asociacion );
	$ok( 'R79 PATCH: la asociación que edita la novedad de su comercio no se la queda', ! array_key_exists( 'user_id', $r['valores'] ) && 'Cambio' === $r['valores']['post_title'] );
	$otro_negocio = ZN_Esquema::guardar( 'negocio', null, array( 'post_title' => 'Otro temporal-escrituras', 'user_id' => $ajeno ), array( 'post_status' => 'publish' ) );
	$entradas[]   = $otro_negocio;
	$r            = $v( 'evento', array( 'businessId' => $otro_negocio ), get_post( $novedad ), $admin_app );
	$ok( 'R70 PATCH: mover la novedad a un negocio de otro dueño → 422', isset( $r['errores']['businessId'] ) );
} finally {
	foreach ( $entradas as $entrada ) {
		if ( is_int( $entrada ) ) {
			wp_delete_post( $entrada, true );
		}
	}
	foreach ( $cuentas as $id ) {
		if ( get_userdata( $id ) ) {
			wp_delete_user( $id );
		}
	}
}
$quedan = array_filter( $entradas, static fn( $id ) => is_int( $id ) && get_post( $id ) );
$ok( sprintf( 'limpieza: borradas %d entradas (y sus comentarios) y %d cuentas', count( $entradas ), count( $cuentas ) ), ! $quedan && ! array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Datos personales (R65): al borrar una cuenta se borran sus reseñas y preguntas y se recalculan las puntuaciones;
// las herramientas de privacidad de WordPress (exportar y borrar datos personales) incluyen los datos de la app; y
// los comentarios no guardan la IP ni el navegador.
// Uso: wp eval-file tests/privacidad.php. Crea cuentas, negocios y comentarios «temporal-privacidad» y los borra.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

$cuentas  = array();
$entradas = array();
$cuenta   = static function ( string $rol_wp, string $nombre = 'Temporal' ) use ( &$cuentas ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-privacidad';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => $nombre ) );
	$cuentas[] = $id;
	return $id;
};
$negocio = static function ( int $dueno ) use ( &$entradas ) {
	$id         = ZN_Esquema::guardar( 'negocio', null, array( 'post_title' => 'Negocio temporal-privacidad', 'user_id' => $dueno ), array( 'post_status' => 'publish' ) );
	$entradas[] = $id;
	return $id;
};
$resena = static function ( int $negocio, int $autor, int $nota, string $texto = 'Reseña (prueba)' ) {
	return ZN_Esquema::guardar( ZN_Comentarios::RESENA, null, array( 'comment_post_ID' => $negocio, 'comment_content' => $texto, 'score' => (string) $nota ), array( 'user_id' => $autor, 'comment_approved' => 1 ) );
};
$pregunta = static function ( int $negocio, int $autor, int $comercio, string $texto = '¿Pregunta (prueba)?' ) {
	return ZN_Esquema::guardar( ZN_Comentarios::PREGUNTA, null, array( 'comment_post_ID' => $negocio, 'comment_content' => $texto, 'user_id' => $comercio, 'answer' => 'Respuesta (prueba)' ), array( 'user_id' => $autor, 'comment_approved' => 1 ) );
};
$existe = static fn( $id ) => is_int( $id ) && null !== get_comment( $id );

try {
	$comercio = $cuenta( 'zn_comercio', 'Comercio' );
	$otro_com = $cuenta( 'zn_comercio', 'Otro' );
	$ana      = $cuenta( 'zn_usuario', 'Ana' );
	$luis     = $cuenta( 'zn_usuario', 'Luis' );
	update_user_meta( $ana, 'phone', '+34 600 000 001' );
	update_user_meta( $ana, 'latitude', '42.2' );
	update_user_meta( $ana, 'longitude', '-8.7' );
	$tienda = $negocio( $comercio );
	$ajena  = $negocio( $otro_com );

	// --- No se guarda la IP ni el navegador, ni aunque se pidan ---
	$con_ip = ZN_Esquema::guardar( ZN_Comentarios::RESENA, null, array( 'comment_post_ID' => $tienda, 'comment_content' => 'x', 'score' => '3' ), array( 'user_id' => $luis, 'comment_author_IP' => '192.0.2.9', 'comment_agent' => 'Navegador (prueba)', 'comment_author_email' => 'luis@ejemplo.invalid' ) );
	$c      = get_comment( $con_ip );
	$ok( 'R65: guardar() no escribe IP, navegador ni correo aunque se le pasen', $c && '' === $c->comment_author_IP && '' === $c->comment_agent && '' === $c->comment_author_email );
	$directo = wp_insert_comment( array( 'comment_post_ID' => $tienda, 'comment_type' => ZN_Comentarios::RESENA, 'comment_content' => 'y', 'user_id' => $luis, 'comment_author_IP' => '192.0.2.9', 'comment_agent' => 'Navegador (prueba)', 'comment_approved' => 0 ) );
	$c       = get_comment( $directo );
	$ok( 'R65: tampoco por wp_insert_comment() directo (se vacían al guardar)', $c && '' === $c->comment_author_IP && '' === $c->comment_agent );
	wp_update_comment( array( 'comment_ID' => $directo, 'comment_author_IP' => '192.0.2.10', 'comment_agent' => 'Otro (prueba)' ) );
	$c = get_comment( $directo );
	$ok( 'R65: ni al editar el comentario', $c && '' === $c->comment_author_IP && '' === $c->comment_agent );
	wp_delete_comment( $con_ip, true );
	wp_delete_comment( $directo, true );
	$normal = wp_insert_comment( array( 'comment_post_ID' => $tienda, 'comment_type' => 'comment', 'comment_content' => 'z', 'comment_author_IP' => '192.0.2.11', 'comment_approved' => 0 ) );
	$ok( 'los comentarios que no son de la app no se tocan', '192.0.2.11' === get_comment( $normal )->comment_author_IP );
	wp_delete_comment( $normal, true );

	// --- Datos de Ana ---
	$r_ana       = $resena( $tienda, $ana, 1, 'Mal (prueba)' );
	$r_ana_ajena = $resena( $ajena, $ana, 2 );
	$r_luis      = $resena( $tienda, $luis, 5 );
	$p_ana       = $pregunta( $tienda, $ana, $comercio, '¿Abrís el domingo? (prueba)' );
	$p_luis      = $pregunta( $tienda, $luis, $comercio );
	$p_al_otro   = $pregunta( $ajena, $luis, $otro_com );
	$ok( 'antes: la nota de la tienda es la media de 1 y 5 (3)', 3.0 === (float) get_post_meta( $tienda, 'score', true ) );

	// --- Exportar ---
	$exportadores = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$ok( 'la herramienta «Exportar datos personales» tiene el exportador de la app', isset( $exportadores['zona-nautico'] ) );
	$email_ana = get_userdata( $ana )->user_email;
	$export    = isset( $exportadores['zona-nautico'] ) ? call_user_func( $exportadores['zona-nautico']['callback'], $email_ana, 1 ) : array();
	$grupos    = array();
	foreach ( (array) ( $export['data'] ?? array() ) as $elemento ) {
		$grupos[ $elemento['group_id'] ][] = wp_list_pluck( $elemento['data'], 'value', 'name' );
	}
	$todo = wp_json_encode( $export, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$ok( 'exportar: termina (done) y trae la cuenta, sus reseñas y sus preguntas', true === ( $export['done'] ?? null ) && isset( $grupos['zn-cuenta'], $grupos['zn-resenas'], $grupos['zn-preguntas'] ) );
	$ok( 'exportar: cuenta con nombre, correo, teléfono y ubicación', isset( $grupos['zn-cuenta'] ) && 1 === count( $grupos['zn-cuenta'] ) && false !== strpos( $todo, '+34 600 000 001' ) && false !== strpos( $todo, $email_ana ) && false !== strpos( $todo, '42.2' ) );
	$ok( 'exportar: sus 2 reseñas (con su texto) y su pregunta, nada de Luis', 2 === count( $grupos['zn-resenas'] ?? array() ) && 1 === count( $grupos['zn-preguntas'] ?? array() ) && false !== strpos( $todo, 'Mal (prueba)' ) && false !== strpos( $todo, '¿Abrís el domingo? (prueba)' ) && false === strpos( $todo, 'Luis' ) );
	$ok( 'exportar: nada de huellas de contraseña, tokens ni códigos', false === strpos( $todo, get_userdata( $ana )->user_pass ) && false === stripos( $todo, 'zn_verificar' ) );
	$nadie = isset( $exportadores['zona-nautico'] ) ? call_user_func( $exportadores['zona-nautico']['callback'], 'nadie-temporal-privacidad@ejemplo.invalid', 1 ) : null;
	$ok( 'exportar: un correo sin cuenta → vacío y terminado', is_array( $nadie ) && array() === $nadie['data'] && true === $nadie['done'] );

	// --- Borrar datos personales (herramienta de WordPress) ---
	$borradores = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	$ok( 'la herramienta «Borrar datos personales» tiene el borrador de la app', isset( $borradores['zona-nautico'] ) );
	$borrado = isset( $borradores['zona-nautico'] ) ? call_user_func( $borradores['zona-nautico']['callback'], $email_ana, 1 ) : array();
	$ok( 'borrar: dice que ha quitado datos, que termina y que la cuenta sigue (se borra en Usuarios)', true === ( $borrado['items_removed'] ?? null ) && true === ( $borrado['done'] ?? null ) && true === ( $borrado['items_retained'] ?? null ) && ! empty( $borrado['messages'] ) );
	$ok( 'borrar: se van sus reseñas y su pregunta', ! $existe( $r_ana ) && ! $existe( $r_ana_ajena ) && ! $existe( $p_ana ) );
	$ok( 'borrar: lo de Luis sigue', $existe( $r_luis ) && $existe( $p_luis ) && $existe( $p_al_otro ) );
	$ok( 'borrar: se recalcula la nota (solo queda el 5 de Luis; la otra tienda, 0)', 5.0 === (float) get_post_meta( $tienda, 'score', true ) && 0.0 === (float) get_post_meta( $ajena, 'score', true ) );
	$ok( 'borrar: se quitan teléfono y ubicación de la cuenta', '' === get_user_meta( $ana, 'phone', true ) && '' === get_user_meta( $ana, 'latitude', true ) && '' === get_user_meta( $ana, 'longitude', true ) );
	$otra_vez = isset( $borradores['zona-nautico'] ) ? call_user_func( $borradores['zona-nautico']['callback'], $email_ana, 1 ) : array();
	$ok( 'borrar otra vez: nada que quitar', false === ( $otra_vez['items_removed'] ?? null ) && true === ( $otra_vez['done'] ?? null ) );

	// --- Borrar la cuenta ---
	$r_luis_ajena = $resena( $ajena, $luis, 4 );
	$ok( 'antes: la otra tienda tiene 4', 4.0 === (float) get_post_meta( $ajena, 'score', true ) );
	wp_delete_user( $luis );
	$ok( 'R65: al borrar la cuenta se borran sus reseñas', ! $existe( $r_luis ) && ! $existe( $r_luis_ajena ) );
	$ok( 'R65: y sus preguntas', ! $existe( $p_luis ) && ! $existe( $p_al_otro ) );
	$ok( 'R65: y se recalculan las puntuaciones (las dos tiendas a 0)', 0.0 === (float) get_post_meta( $tienda, 'score', true ) && 0.0 === (float) get_post_meta( $ajena, 'score', true ) );

	$p_a_comercio = $pregunta( $tienda, $ana, $comercio );
	$r_a_comercio = $resena( $tienda, $ana, 3 );
	wp_delete_user( $comercio );
	$ok( 'al borrar un comercio se borran las preguntas que le hicieron (como el Laravel)', ! $existe( $p_a_comercio ) );
	$ok( 'las reseñas de sus negocios siguen mientras el negocio exista (el negocio se borra aparte)', $existe( $r_a_comercio ) );
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
$quedan      = array_filter( $entradas, static fn( $id ) => is_int( $id ) && get_post( $id ) );
$comentarios = get_comments( array( 'post__in' => array_filter( $entradas, 'is_int' ) ?: array( 0 ), 'status' => 'all', 'count' => true ) );
$ok( sprintf( 'limpieza: borradas %d entradas (y sus comentarios) y %d cuentas', count( $entradas ), count( $cuentas ) ), ! $quedan && 0 === (int) $comentarios && ! array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

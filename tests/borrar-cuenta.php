<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// «Eliminar cuenta» desde la app: DELETE /user/{id}. Solo la propia cuenta y solo con user:delete_self (hoy, solo el
// rol user, R44). Borra como wp_delete_user(): reseñas y preguntas fuera, negocios, novedades y sorteos a borrador y
// sin dueño (R65, R95, R96) y todos sus tokens anulados. Cuenta en el límite de escrituras (R94).
// Uso: wp eval-file tests/borrar-cuenta.php. Crea cuentas «…-temporal-borrarcuenta@ejemplo.invalid», negocios y
// comentarios de prueba, llama a la ruta por dentro (con IPs de documentación) y unas pocas veces por HTTP con
// tokens de verdad. No manda correos. Al terminar borra lo que queda, sus tokens y sus filas de intentos.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$servidor = $_SERVER;
$cuentas  = array();
$emails   = array(); // Los correos de las cuentas, para borrar sus filas de intentos aunque ya no existan.
$entradas = array();
$cuenta   = static function ( string $rol_wp = 'zn_usuario', int $padre = 0 ) use ( &$cuentas, &$emails ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-borrarcuenta';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => 'Temporal' ) );
	update_user_meta( $id, 'zn_verificado', '1' );
	if ( $padre ) {
		update_user_meta( $id, 'zn_parent_id', $padre );
	}
	$cuentas[] = $id;
	$emails[]  = "$login@ejemplo.invalid";
	return $id;
};
$negocio = static function ( int $dueno ) use ( &$entradas ) {
	$id         = ZN_Esquema::guardar( 'negocio', null, array( 'post_title' => 'Negocio temporal-borrarcuenta', 'user_id' => $dueno ), array( 'post_status' => 'publish', 'post_author' => 0 ) );
	$entradas[] = $id;
	return $id;
};
$resena = static function ( int $negocio, int $autor, int $nota ) {
	return ZN_Esquema::guardar( ZN_Comentarios::RESENA, null, array( 'comment_post_ID' => $negocio, 'comment_content' => 'Reseña (prueba)', 'score' => (string) $nota ), array( 'user_id' => $autor, 'comment_approved' => 1 ) );
};
$pregunta = static function ( int $negocio, int $autor, int $comercio ) {
	return ZN_Esquema::guardar( ZN_Comentarios::PREGUNTA, null, array( 'comment_post_ID' => $negocio, 'comment_content' => '¿Pregunta (prueba)?', 'user_id' => $comercio ), array( 'user_id' => $autor, 'comment_approved' => 1 ) );
};
$existe  = static fn( $id ) => is_int( $id ) && null !== get_comment( $id );
$vivos   = static fn( int $id ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZN_Tokens::tabla() . ' WHERE user_id = %d AND anulado IS NULL', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
// Llamada por dentro como la cuenta $quien (0 = sin sesión), pasando por rest_post_dispatch como al servir.
$borrar = static function ( int $id, int $quien, string $ip = '192.0.2.90' ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	wp_set_current_user( $quien );
	$peticion  = new WP_REST_Request( 'DELETE', '/' . ZN_NAMESPACE . "/user/$id" );
	$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $peticion ) ), rest_get_server(), $peticion );
	wp_set_current_user( 0 );
	clean_user_cache( $id );
	return array( $respuesta->get_status(), $respuesta->get_data(), $respuesta->get_headers() );
};
$base = rest_url( ZN_NAMESPACE );
// El Local de Windows reinicia su PHP tras muchas peticiones seguidas (502 unos segundos): se espera y se repite.
$http = static function ( string $metodo, string $ruta, ?string $token = null ) use ( $base ) {
	$cabeceras = array( 'Accept' => 'application/json' );
	if ( $token ) {
		$cabeceras['Authorization'] = "Bearer $token";
	}
	for ( $intento = 0; $intento < 3; $intento++ ) {
		usleep( 400000 );
		$respuesta = wp_remote_request( $base . $ruta, array( 'method' => $metodo, 'timeout' => 60, 'headers' => $cabeceras ) );
		if ( ! is_wp_error( $respuesta ) && 502 !== wp_remote_retrieve_response_code( $respuesta ) ) {
			break;
		}
		sleep( 15 );
	}
	return is_wp_error( $respuesta )
		? array( 0, null )
		: array( wp_remote_retrieve_response_code( $respuesta ), json_decode( wp_remote_retrieve_body( $respuesta ), true ) );
};
$no_autenticado = array( 'message' => 'Unauthenticated.' );
$sin_permiso    = array( 'message' => 'This action is unauthorized.' );
// Sin correos: ninguna de estas rutas debería mandar ninguno.
$correos = array();
add_filter(
	'pre_wp_mail',
	static function ( $resultado, $correo ) use ( &$correos ) {
		$correos[] = $correo;
		return true;
	},
	PHP_INT_MAX,
	2
);

try {
	// --- La ruta ---
	$metodos = array();
	foreach ( rest_get_server()->get_routes( ZN_NAMESPACE )[ '/' . ZN_NAMESPACE . '/user/(?P<id>\d+)' ] ?? array() as $manejador ) {
		$metodos = array_merge( $metodos, array_keys( array_filter( (array) $manejador['methods'] ) ) );
	}
	$ok( 'existe DELETE /user/{id}, y siguen GET y PATCH', in_array( 'DELETE', $metodos, true ) && in_array( 'GET', $metodos, true ) && in_array( 'PATCH', $metodos, true ) );

	// --- Quién puede ---
	$ana        = $cuenta( 'zn_usuario' );
	$admin      = $cuenta( 'zn_administrador' );
	$asociacion = $cuenta( 'zn_asociacion' );
	$comercio   = $cuenta( 'zn_comercio', $asociacion );
	list( $estado, $cuerpo ) = $borrar( $ana, 0 );
	$ok( 'sin sesión: 401 «Unauthenticated.» y la cuenta sigue', 401 === $estado && $no_autenticado === $cuerpo && get_userdata( $ana ) );
	list( $estado, $cuerpo ) = $borrar( $ana, $admin );
	$ok( 'con la sesión de otra cuenta (aunque sea la administración): 401 «This action is unauthorized.» y sigue', 401 === $estado && $sin_permiso === $cuerpo && get_userdata( $ana ) );
	list( $estado, $cuerpo ) = $borrar( $comercio, $asociacion );
	$ok( 'la asociación tampoco borra a su comercio hijo desde aquí: 401 y sigue', 401 === $estado && $sin_permiso === $cuerpo && get_userdata( $comercio ) );
	list( $estado, $cuerpo ) = $borrar( $comercio, $comercio );
	$ok( 'R44: un comercio no puede borrar su propia cuenta (sin user:delete_self): 401 y sigue', 401 === $estado && $sin_permiso === $cuerpo && get_userdata( $comercio ) );
	list( $estado, $cuerpo ) = $borrar( $asociacion, $asociacion );
	$ok( 'R44: una asociación tampoco: 401 y sigue', 401 === $estado && $sin_permiso === $cuerpo && get_userdata( $asociacion ) );
	list( $estado ) = $borrar( $admin, $admin );
	$ok( 'R44: ni la administración de la app: 401 y sigue', 401 === $estado && get_userdata( $admin ) );
	list( $estado, $cuerpo ) = $borrar( 999999999, $ana );
	$ok( 'cuenta que no existe: 404 «Not found.»', 404 === $estado && array( 'message' => 'Not found.' ) === $cuerpo );
	$de_wp = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
	if ( $de_wp ) {
		list( $estado ) = $borrar( $de_wp, $ana );
		$ok( 'una cuenta de WordPress que no es de la app: 404 y sigue', 404 === $estado && get_userdata( $de_wp ) );
	}

	// --- Borrar la propia cuenta, con la cascada de R65, R95 y R96 ---
	$tienda   = $negocio( $comercio );
	$luis     = $cuenta( 'zn_usuario' );
	$r_ana    = $resena( $tienda, $ana, 1 );
	$r_luis   = $resena( $tienda, $luis, 5 );
	$p_ana    = $pregunta( $tienda, $ana, $comercio );
	$suyo     = $negocio( $ana ); // Un usuario no tiene negocios desde la app, pero la administración puede ponérselos.
	$sorteo   = ZN_Esquema::guardar( 'sorteo', null, array( 'post_title' => 'Sorteo temporal-borrarcuenta', 'user_id' => $ana, 'winners' => 1 ), array( 'post_status' => 'publish', 'post_author' => 0 ) );
	$entradas[] = $sorteo;
	ZN_Tokens::emitir( $ana );
	ZN_Tokens::emitir( $ana );
	$ok( 'antes: la nota de la tienda es la media de 1 y 5 (3) y Ana tiene 2 tokens', 3.0 === (float) get_post_meta( $tienda, 'score', true ) && 2 === $vivos( $ana ) );
	$correos = array();
	list( $estado, $cuerpo, $cabeceras ) = $borrar( $ana, $ana );
	$ok( 'la propia cuenta (rol user): 200 {status: "success"}', 200 === $estado && array( 'status' => 'success' ) === $cuerpo );
	$ok( 'respuesta sin caché (Cache-Control: no-store)', false !== strpos( (string) ( $cabeceras['Cache-Control'] ?? '' ), 'no-store' ) );
	$ok( 'la cuenta ya no existe', false === get_userdata( $ana ) );
	$ok( 'R65: sus reseñas y preguntas se borran; las de otros siguen', ! $existe( $r_ana ) && ! $existe( $p_ana ) && $existe( $r_luis ) );
	$ok( 'R65: la nota de la tienda se recalcula (solo queda el 5)', 5.0 === (float) get_post_meta( $tienda, 'score', true ) );
	$ok( 'R95, R96: su negocio y su sorteo pasan a borrador y sin dueño (no se borran)', 'draft' === get_post_status( $suyo ) && '' === get_post_meta( $suyo, 'user_id', true ) && 'draft' === get_post_status( $sorteo ) && '' === get_post_meta( $sorteo, 'user_id', true ) );
	$ok( 'el negocio del comercio no se toca', 'publish' === get_post_status( $tienda ) && (string) $comercio === get_post_meta( $tienda, 'user_id', true ) );
	$ok( 'todos sus tokens quedan anulados', 0 === $vivos( $ana ) );
	$ok( 'no se manda ningún correo', ! $correos );
	list( $estado ) = $borrar( $ana, $ana );
	$ok( 'borrarla otra vez (ya no hay sesión de esa cuenta): 401', 401 === $estado );

	// --- Límite de escrituras de la cuenta (R94) ---
	$pepa = $cuenta( 'zn_usuario' );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.91';
	wp_set_current_user( $pepa );
	for ( $i = 0; $i < ZN_Limite::REGLAS['escritura']['cuenta'][0]; $i++ ) {
		ZN_API::limite_de_escritura();
	}
	wp_set_current_user( 0 );
	list( $estado, $cuerpo, $cabeceras ) = $borrar( $pepa, $pepa, '192.0.2.91' );
	$ok( 'pasado el límite de escrituras de la cuenta: 429 «Too Many Attempts.» con Retry-After, y la cuenta sigue', 429 === $estado && 'Too Many Attempts.' === ( $cuerpo['message'] ?? null ) && isset( $cabeceras['Retry-After'] ) && get_userdata( $pepa ) );

	// --- Por HTTP de verdad, con tokens ---
	$rosa = $cuenta( 'zn_usuario' );
	$t_rosa = ZN_Tokens::emitir( $rosa );
	$otro   = $cuenta( 'zn_usuario' );
	list( $estado ) = $http( 'DELETE', "/user/$otro", $t_rosa );
	$ok( 'HTTP: con el token de otra cuenta: 401 y sigue', 401 === $estado && get_userdata( $otro ) );
	list( $estado ) = $http( 'DELETE', "/user/$rosa" );
	$ok( 'HTTP: sin token: 401', 401 === $estado );
	list( $estado, $cuerpo ) = $http( 'DELETE', "/user/$rosa", $t_rosa );
	clean_user_cache( $rosa );
	$ok( 'HTTP: con su token: 200 {status: "success"} y la cuenta se borra', 200 === $estado && array( 'status' => 'success' ) === $cuerpo && false === get_userdata( $rosa ) );
	$ok( 'HTTP: su token ya no vale', 401 === $http( 'GET', "/user/$rosa", $t_rosa )[0] );
} finally {
	// Filas de intentos de las cuentas, también de las ya borradas (por su correo).
	$claves = array();
	foreach ( array_keys( ZN_Limite::REGLAS ) as $accion ) {
		foreach ( array_merge( array( '127.0.0.1', '::1' ), array_map( static fn( $i ) => "192.0.2.$i", range( 90, 91 ) ) ) as $ip ) {
			$_SERVER['REMOTE_ADDR'] = $ip;
			foreach ( $emails as $direccion ) {
				$claves = array_merge( $claves, array_keys( ZN_Limite::claves( $accion, $direccion ) ) );
			}
			foreach ( $cuentas as $id ) {
				$u = get_userdata( $id );
				if ( $u ) {
					$claves = array_merge( $claves, array_keys( ZN_Limite::claves( $accion, $u->user_email ) ) );
				}
			}
			$claves = array_merge( $claves, array_keys( ZN_Limite::claves( $accion, null ) ) );
		}
	}
	$_SERVER = $servidor;
	foreach ( $entradas as $entrada ) {
		if ( is_int( $entrada ) && $entrada ) {
			wp_delete_post( $entrada, true );
		}
	}
	foreach ( $cuentas as $id ) {
		if ( get_userdata( $id ) ) {
			wp_delete_user( $id );
		}
	}
	if ( $cuentas ) {
		$wpdb->query( 'DELETE FROM ' . ZN_Tokens::tabla() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $cuentas ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	foreach ( array_chunk( array_unique( $claves ), 100 ) as $trozo ) {
		$wpdb->query( 'DELETE FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', $trozo ) ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
$quedan   = array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) );
$sueltas  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email LIKE '%-temporal-borrarcuenta@ejemplo.invalid'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$tokens   = $cuentas ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Tokens::tabla() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $cuentas ) ) . ')' ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$intentos = $claves ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', array_unique( $claves ) ) ) . "')" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas temporales, %d entradas, sus tokens y sus filas de intentos', count( $cuentas ), count( $entradas ) ), ! $quedan && 0 === $sueltas && 0 === $tokens && 0 === $intentos && ! array_filter( $entradas, static fn( $id ) => is_int( $id ) && get_post( $id ) ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

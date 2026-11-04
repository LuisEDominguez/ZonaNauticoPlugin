<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Favoritos (bloque 2, parte C): tabla {prefijo}zn_favoritos (user_id, business_id, como business_favorites del
// traspaso). GET /favorite (los míos, con el negocio), POST /favorite {businessId}, DELETE /favorite/{businessId}.
// Toda cuenta de la app con sesión y solo lo suyo; sin contadores públicos. RGPD (R65): al borrar la cuenta o el
// negocio se borran; exportar y borrar datos personales los incluyen. Límite de escrituras (R94).
// Uso: wp eval-file tests/favoritos.php. Crea cuentas «…-temporal-favoritos@ejemplo.invalid» y negocios, también
// por HTTP con tokens, y lo borra todo.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$servidor = $_SERVER;
$cuentas  = array();
$emails   = array();
$entradas = array();
$cuenta   = static function ( string $rol_wp = 'zn_usuario' ) use ( &$cuentas, &$emails ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-favoritos';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => 'Temporal' ) );
	update_user_meta( $id, 'zn_verificado', '1' );
	$cuentas[] = $id;
	$emails[]  = "$login@ejemplo.invalid";
	return $id;
};
$negocio = static function ( string $estado = 'publish' ) use ( &$entradas ) {
	$id         = ZN_Esquema::guardar( 'negocio', null, array( 'post_title' => 'Negocio temporal-favoritos ' . count( $entradas ) ), array( 'post_status' => $estado, 'post_author' => 0 ) );
	$entradas[] = $id;
	return $id;
};
$llamar = static function ( string $metodo, string $ruta, int $quien, ?array $cuerpo = null, string $ip = '192.0.2.96' ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	wp_set_current_user( $quien );
	$peticion = new WP_REST_Request( $metodo, '/' . ZN_NAMESPACE . $ruta );
	if ( null !== $cuerpo ) {
		$peticion->set_header( 'Content-Type', 'application/json' );
		$peticion->set_body( wp_json_encode( $cuerpo ) );
	}
	$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $peticion ) ), rest_get_server(), $peticion );
	wp_set_current_user( 0 );
	return array( $respuesta->get_status(), $respuesta->get_data(), $respuesta->get_headers() );
};
$filas = static fn( int $usuario ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZN_Favoritos::tabla() . ' WHERE user_id = %d', $usuario ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ids    = static fn( array $cuerpo ) => array_column( $cuerpo['data'] ?? array(), '_id' );
$no_autenticado = array( 'message' => 'Unauthenticated.' );

try {
	// --- La tabla ---
	$ok( 'existe la tabla {prefijo}zn_favoritos con user_id y business_id', ZN_Favoritos::tabla() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', ZN_Favoritos::tabla() ) ) && in_array( 'business_id', $wpdb->get_col( 'SHOW COLUMNS FROM ' . ZN_Favoritos::tabla() ), true ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$ok( 'R68: desinstalar también la borra', in_array( ZN_Favoritos::tabla(), ZN_Instalacion::tablas(), true ) );
	$rutas = rest_get_server()->get_routes( ZN_NAMESPACE );
	$ok( 'existen /favorite (GET y POST) y /favorite/{businessId} (DELETE)', isset( $rutas[ '/' . ZN_NAMESPACE . '/favorite' ], $rutas[ '/' . ZN_NAMESPACE . '/favorite/(?P<businessId>\d+)' ] ) );

	// --- Sin sesión ---
	$tienda = $negocio();
	$ana    = $cuenta( 'zn_usuario' );
	list( $estado, $cuerpo ) = $llamar( 'GET', '/favorite', 0 );
	$ok( 'GET sin sesión: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	list( $estado ) = $llamar( 'POST', '/favorite', 0, array( 'businessId' => $tienda ) );
	$ok( 'POST sin sesión: 401', 401 === $estado );
	list( $estado ) = $llamar( 'DELETE', "/favorite/$tienda", 0 );
	$ok( 'DELETE sin sesión: 401', 401 === $estado );

	// --- Añadir ---
	list( $estado, $cuerpo ) = $llamar( 'GET', '/favorite', $ana );
	$ok( 'GET sin favoritos: 200 con data vacía', 200 === $estado && array() === ( $cuerpo['data'] ?? null ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/favorite', $ana, array( 'businessId' => $tienda ) );
	$ok( 'POST: 201 con el negocio como en GET /business/{id}', 201 === $estado && (string) $tienda === ( $cuerpo['data']['_id'] ?? null ) && isset( $cuerpo['data']['name'] ) );
	list( $estado ) = $llamar( 'POST', '/favorite', $ana, array( 'businessId' => $tienda ) );
	$ok( 'POST repetido: 200 y sin duplicar', 200 === $estado && 1 === $filas( $ana ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/favorite', $ana, array() );
	$ok( 'POST sin businessId: 422 en businessId', 422 === $estado && isset( $cuerpo['errors']['businessId'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/favorite', $ana, array( 'businessId' => 'abc' ) );
	$ok( 'POST con businessId que no es número: 422', 422 === $estado && isset( $cuerpo['errors']['businessId'] ) );
	$borrador = $negocio( 'draft' );
	list( $estado ) = $llamar( 'POST', '/favorite', $ana, array( 'businessId' => $borrador ) );
	$ok( 'POST de un negocio no publicado: 404 y nada guardado', 404 === $estado && 1 === $filas( $ana ) );
	list( $estado ) = $llamar( 'POST', '/favorite', $ana, array( 'businessId' => 999999999 ) );
	$ok( 'POST de un negocio que no existe: 404', 404 === $estado );
	$otra = $negocio();
	$llamar( 'POST', '/favorite', $ana, array( 'businessId' => $otra ) );
	list( $estado, $cuerpo, $cabeceras ) = $llamar( 'GET', '/favorite', $ana );
	$ok( 'GET: los míos, el último añadido primero, con el formato de /business', 200 === $estado && array( (string) $otra, (string) $tienda ) === $ids( $cuerpo ) && isset( $cuerpo['data'][0]['score'] ) );
	$ok( 'GET sin caché (no-store)', false !== strpos( (string) ( $cabeceras['Cache-Control'] ?? '' ), 'no-store' ) );

	// --- Solo lo suyo; para cualquier rol de la app ---
	$luis     = $cuenta( 'zn_usuario' );
	$comercio = $cuenta( 'zn_comercio' );
	list( , $cuerpo ) = $llamar( 'GET', '/favorite', $luis );
	$ok( 'otra cuenta no ve los de Ana', array() === $ids( $cuerpo ) );
	list( $estado ) = $llamar( 'POST', '/favorite', $comercio, array( 'businessId' => $tienda ) );
	$ok( 'un comercio también puede tener favoritos', 201 === $estado );
	list( , $cuerpo ) = $llamar( 'GET', "/business/$tienda", 0 );
	$ok( 'sin contadores públicos: la ficha del negocio no dice cuántos lo tienen', $cuerpo['data'] && ! preg_grep( '/favorit|like/i', array_keys( $cuerpo['data'] ) ) );

	// --- Un negocio que deja de verse no sale; uno borrado se lleva sus favoritos ---
	wp_update_post( array( 'ID' => $otra, 'post_status' => 'draft' ) );
	list( , $cuerpo ) = $llamar( 'GET', '/favorite', $ana );
	$ok( 'un favorito cuyo negocio pasa a borrador no sale (pero se guarda)', array( (string) $tienda ) === $ids( $cuerpo ) && 2 === $filas( $ana ) );
	wp_delete_post( $otra, true );
	$ok( 'R65: borrar el negocio borra sus favoritos', 1 === $filas( $ana ) );

	// --- Quitar ---
	list( $estado, $cuerpo ) = $llamar( 'DELETE', "/favorite/$tienda", $ana );
	$ok( 'DELETE: 200 {status: "success"} y se quita', 200 === $estado && array( 'status' => 'success' ) === $cuerpo && 0 === $filas( $ana ) );
	list( $estado ) = $llamar( 'DELETE', "/favorite/$tienda", $ana );
	$ok( 'DELETE de uno que no era favorito: 200 igual (no delata nada)', 200 === $estado );
	list( , $cuerpo ) = $llamar( 'GET', '/favorite', $comercio );
	$ok( 'quitar el de Ana no toca el del comercio', array( (string) $tienda ) === $ids( $cuerpo ) );

	// --- Límite de escrituras (R94) ---
	$pepa = $cuenta( 'zn_usuario' );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.97';
	wp_set_current_user( $pepa );
	for ( $i = 0; $i < ZN_Limite::REGLAS['escritura']['cuenta'][0]; $i++ ) {
		ZN_API::limite_de_escritura();
	}
	wp_set_current_user( 0 );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/favorite', $pepa, array( 'businessId' => $tienda ), '192.0.2.97' );
	$ok( 'pasado el límite de escrituras: POST 429 y nada guardado', 429 === $estado && 'Too Many Attempts.' === ( $cuerpo['message'] ?? null ) && 0 === $filas( $pepa ) );

	// --- RGPD (R65) ---
	$eva = $cuenta( 'zn_usuario' );
	$llamar( 'POST', '/favorite', $eva, array( 'businessId' => $tienda ) );
	$exportar = apply_filters( 'wp_privacy_personal_data_exporters', array() )['zona-nautico']['callback'];
	$export   = call_user_func( $exportar, get_userdata( $eva )->user_email, 1 );
	$grupos   = array_column( $export['data'] ?? array(), 'group_id' );
	$ok( 'exportar datos personales incluye sus favoritos (con el nombre del negocio)', in_array( 'zn-favoritos', $grupos, true ) && false !== strpos( wp_json_encode( $export, JSON_UNESCAPED_UNICODE ), get_the_title( $tienda ) ) );
	$borrar = apply_filters( 'wp_privacy_personal_data_erasers', array() )['zona-nautico']['callback'];
	call_user_func( $borrar, get_userdata( $eva )->user_email, 1 );
	$ok( 'borrar datos personales quita sus favoritos', 0 === $filas( $eva ) );
	$llamar( 'POST', '/favorite', $luis, array( 'businessId' => $tienda ) );
	wp_delete_user( $luis );
	$ok( 'R65: borrar la cuenta borra sus favoritos', 0 === $filas( $luis ) );

	// --- Por HTTP, con token ---
	$rosa  = $cuenta( 'zn_usuario' );
	$token = ZN_Tokens::emitir( $rosa );
	$http  = static function ( string $metodo, string $ruta, ?array $cuerpo = null ) use ( $token ) {
		for ( $intento = 0; $intento < 3; $intento++ ) {
			usleep( 400000 );
			$r = wp_remote_request( rest_url( ZN_NAMESPACE . $ruta ), array( 'method' => $metodo, 'timeout' => 60, 'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => "Bearer $token" ), 'body' => null === $cuerpo ? null : wp_json_encode( $cuerpo ) ) );
			if ( ! is_wp_error( $r ) && 502 !== wp_remote_retrieve_response_code( $r ) ) {
				break;
			}
			sleep( 15 );
		}
		return is_wp_error( $r ) ? array( 0, null ) : array( wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ) );
	};
	$ok( 'HTTP: POST /favorite con token: 201', 201 === $http( 'POST', '/favorite', array( 'businessId' => $tienda ) )[0] );
	list( $estado, $cuerpo ) = $http( 'GET', '/favorite' );
	$ok( 'HTTP: GET /favorite: el suyo', 200 === $estado && array( (string) $tienda ) === $ids( (array) $cuerpo ) );
	$ok( 'HTTP: DELETE /favorite/{id}: 200', 200 === $http( 'DELETE', "/favorite/$tienda" )[0] && 0 === $filas( $rosa ) );
} finally {
	$claves = array();
	foreach ( array( '127.0.0.1', '::1', '192.0.2.96', '192.0.2.97' ) as $ip ) {
		$_SERVER['REMOTE_ADDR'] = $ip;
		foreach ( $emails as $direccion ) {
			$claves = array_merge( $claves, array_keys( ZN_Limite::claves( 'escritura', $direccion ) ) );
		}
		foreach ( $cuentas as $id ) {
			$u = get_userdata( $id );
			if ( $u ) {
				$claves = array_merge( $claves, array_keys( ZN_Limite::claves( 'escritura', $u->user_email ) ) );
			}
		}
	}
	$_SERVER = $servidor;
	foreach ( $entradas as $entrada ) {
		if ( is_int( $entrada ) && get_post( $entrada ) ) {
			wp_delete_post( $entrada, true );
		}
	}
	foreach ( $cuentas as $id ) {
		if ( get_userdata( $id ) ) {
			wp_delete_user( $id );
		}
	}
	if ( $cuentas ) {
		$lista = implode( ',', array_map( 'intval', $cuentas ) );
		$wpdb->query( 'DELETE FROM ' . ZN_Tokens::tabla() . " WHERE user_id IN ($lista)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . ZN_Favoritos::tabla() . " WHERE user_id IN ($lista)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	foreach ( array_chunk( array_unique( $claves ), 100 ) as $trozo ) {
		$wpdb->query( 'DELETE FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', $trozo ) ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
$quedan   = array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) );
$sueltos  = $cuentas ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Favoritos::tabla() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $cuentas ) ) . ')' ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas, %d negocios, sus tokens, favoritos e intentos', count( $cuentas ), count( $entradas ) ), ! $quedan && 0 === $sueltos && ! array_filter( $entradas, static fn( $id ) => is_int( $id ) && get_post( $id ) ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

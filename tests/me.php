<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// GET /me (bloque 2, parte A): la cuenta del token con su rol y sus permisos, para que la app no lleve copiada la
// tabla de capacidades. Mismos campos que GET /user/{id} más `permissions` (los del Laravel, sin el prefijo zn:).
// Uso: wp eval-file tests/me.php. Crea cuentas «…-temporal-me@ejemplo.invalid» y tokens, llama a la ruta por dentro
// y por HTTP, y lo borra todo.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$cuentas = array();
$cuenta  = static function ( string $rol_wp ) use ( &$cuentas ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-me';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => 'Temporal', 'last_name' => 'Me' ) );
	update_user_meta( $id, 'zn_verificado', '1' );
	$cuentas[] = $id;
	return $id;
};
$me = static function ( int $quien ) {
	wp_set_current_user( $quien );
	$peticion  = new WP_REST_Request( 'GET', '/' . ZN_NAMESPACE . '/me' );
	$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $peticion ) ), rest_get_server(), $peticion );
	wp_set_current_user( 0 );
	return array( $respuesta->get_status(), $respuesta->get_data(), $respuesta->get_headers() );
};
$http = static function ( ?string $token ) {
	$cabeceras = array( 'Accept' => 'application/json' );
	if ( $token ) {
		$cabeceras['Authorization'] = "Bearer $token";
	}
	for ( $intento = 0; $intento < 3; $intento++ ) {
		usleep( 400000 );
		$respuesta = wp_remote_get( rest_url( ZN_NAMESPACE . '/me' ), array( 'timeout' => 60, 'headers' => $cabeceras ) );
		if ( ! is_wp_error( $respuesta ) && 502 !== wp_remote_retrieve_response_code( $respuesta ) ) {
			break;
		}
		sleep( 15 );
	}
	return is_wp_error( $respuesta ) ? array( 0, null ) : array( wp_remote_retrieve_response_code( $respuesta ), json_decode( wp_remote_retrieve_body( $respuesta ), true ) );
};
$sin_prefijo = static fn( string $rol ) => array_map( static fn( $p ) => substr( $p, 3 ), ZN_Roles::permisos( $rol ) );

try {
	$rutas = rest_get_server()->get_routes( ZN_NAMESPACE );
	$ok( 'existe GET /me', isset( $rutas[ '/' . ZN_NAMESPACE . '/me' ] ) );

	list( $estado, $cuerpo ) = $me( 0 );
	$ok( 'sin sesión: 401 «Unauthenticated.»', 401 === $estado && array( 'message' => 'Unauthenticated.' ) === $cuerpo );
	$admin_wp = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
	if ( $admin_wp ) {
		list( $estado ) = $me( $admin_wp );
		$ok( 'una cuenta de wp-admin (no de la app): 401', 401 === $estado );
	}

	$ana = $cuenta( 'zn_usuario' );
	list( $estado, $cuerpo, $cabeceras ) = $me( $ana );
	$datos = $cuerpo['data'] ?? array();
	$ok( 'usuario: 200 con los campos de GET /user/{id} y además permissions', 200 === $estado && array( '_id', 'id', 'name', 'email', 'verified', 'role', 'parent', 'avatar', 'latitude', 'longitude', 'phone', 'permissions' ) === array_keys( $datos ) );
	$ok( 'usuario: id y role de la cuenta del token', $ana === ( $datos['id'] ?? null ) && 'user' === ( $datos['role'] ?? null ) );
	$ok( 'usuario: permissions son los del Laravel, sin «zn:» (incluye user:delete_self)', $sin_prefijo( 'user' ) === ( $datos['permissions'] ?? null ) && in_array( 'user:delete_self', $datos['permissions'], true ) );
	$ok( 'sin caché (Cache-Control: no-store)', false !== strpos( (string) ( $cabeceras['Cache-Control'] ?? '' ), 'no-store' ) );

	foreach ( array( 'zn_comercio' => 'commerce', 'zn_asociacion' => 'association', 'zn_federacion' => 'federation', 'zn_administrador' => 'administrator' ) as $rol_wp => $rol ) {
		list( $estado, $cuerpo ) = $me( $cuenta( $rol_wp ) );
		$ok( "$rol: sus permisos y su rol", 200 === $estado && $rol === ( $cuerpo['data']['role'] ?? null ) && $sin_prefijo( $rol ) === ( $cuerpo['data']['permissions'] ?? null ) );
	}
	list( , $cuerpo ) = $me( $cuentas[1] );
	$ok( 'comercio: sin user:delete_self (R44)', ! in_array( 'user:delete_self', $cuerpo['data']['permissions'] ?? array( 'user:delete_self' ), true ) );
	$ok( 'nunca salen permisos de WordPress (read, zn_panel_ver…)', ! array_filter( $cuerpo['data']['permissions'] ?? array(), static fn( $p ) => ! preg_match( '/^[a-z_]+:[a-z_-]+$/', $p ) ) );

	// Una cuenta con dos roles no es de la app (R37).
	$mixta = $cuenta( 'zn_usuario' );
	( new WP_User( $mixta ) )->add_role( 'subscriber' );
	list( $estado ) = $me( $mixta );
	$ok( 'cuenta con dos roles (R37): 401', 401 === $estado );

	// Por HTTP, con token.
	$token = ZN_Tokens::emitir( $ana );
	list( $estado, $cuerpo ) = $http( $token );
	$ok( 'HTTP: con su token, 200 y sus permisos', 200 === $estado && $ana === ( $cuerpo['data']['id'] ?? null ) && in_array( 'review:save_own', $cuerpo['data']['permissions'] ?? array(), true ) );
	list( $estado, $cuerpo ) = $http( null );
	$ok( 'HTTP: sin token, 401 «Unauthenticated.»', 401 === $estado && 'Unauthenticated.' === ( $cuerpo['message'] ?? null ) );
	list( $estado ) = $http( str_repeat( 'x', 43 ) );
	$ok( 'HTTP: token inventado, 401', 401 === $estado );
} finally {
	foreach ( $cuentas as $id ) {
		if ( get_userdata( $id ) ) {
			wp_delete_user( $id );
		}
	}
	if ( $cuentas ) {
		$wpdb->query( 'DELETE FROM ' . ZN_Tokens::tabla() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $cuentas ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
$quedan = array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) );
$ok( sprintf( 'limpieza: borradas %d cuentas y sus tokens', count( $cuentas ) ), ! $quedan );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

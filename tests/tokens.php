<?php
// Token de la app (R32, R39) y GET /user/{id}. Uso, con el sitio de Local encendido: wp eval-file tests/tokens.php
// Crea cuentas y tokens temporales («temporal-tokens»), hace peticiones HTTP de verdad al sitio y lo borra todo al terminar.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$base    = rest_url( ZN_NAMESPACE );
$cuentas = array();
$cuenta  = static function ( string $rol_wp, int $padre = 0 ) use ( &$cuentas ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-temporal-tokens';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => 'Temporal', 'last_name' => 'Prueba' . count( $cuentas ) ) );
	if ( $padre ) {
		update_user_meta( $id, 'zn_parent_id', $padre );
	}
	$cuentas[] = $id;
	return $id;
};
$pedir = static function ( string $ruta, ?string $token = null ) use ( $base ) {
	$respuesta = wp_remote_get( $base . $ruta, array( 'timeout' => 60, 'headers' => $token ? array( 'Authorization' => "Bearer $token" ) : array() ) );
	if ( is_wp_error( $respuesta ) ) {
		return array( 0, null );
	}
	return array( wp_remote_retrieve_response_code( $respuesta ), json_decode( wp_remote_retrieve_body( $respuesta ), true ) );
};
$no_autenticado = array( 'message' => 'Unauthenticated.' );
$sin_permiso    = array( 'message' => 'This action is unauthorized.' );

try {
	// --- La tabla y el token ---
	$ok( 'existe la tabla de tokens', ZN_Tokens::tabla() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', ZN_Tokens::tabla() ) ) );
	$comercio = $cuenta( 'zn_comercio' );
	$token    = ZN_Tokens::emitir( $comercio );
	$ok( 'token: 43 caracteres base64url (32 bytes al azar)', is_string( $token ) && 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) );
	$ok( 'dos tokens nunca son iguales', ZN_Tokens::emitir( $comercio ) !== $token );
	$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Tokens::tabla() . ' WHERE user_id = %d ORDER BY id LIMIT 1', $comercio ) );
	$ok( 'en la base de datos solo está la huella SHA-256, nunca el token', $fila && hash( 'sha256', $token ) === $fila->hash && false === strpos( wp_json_encode( $fila ), $token ) );
	$ok( 'caduca a los 60 minutos', $fila && 3600 === strtotime( $fila->caduca . ' UTC' ) - strtotime( $fila->creado . ' UTC' ) );
	$wp_admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0];
	$ok( 'una cuenta de wp-admin no recibe token', null === ZN_Tokens::emitir( $wp_admin ) );

	// --- GET /user/{id} por HTTP ---
	list( $estado, $cuerpo ) = $pedir( "/user/$comercio" );
	$ok( 'sin token: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	list( $estado, $cuerpo ) = $pedir( "/user/$comercio", $token );
	$ok( 'con token: 200', 200 === $estado );
	$ok( 'con token: campos del UserResource del Laravel, en su orden', array( '_id', 'id', 'name', 'email', 'verified', 'role', 'parent', 'avatar', 'latitude', 'longitude', 'phone' ) === array_keys( $cuerpo['data'] ?? array() ) );
	$ok( 'con token: id numérico, _id texto, role «commerce» (src/api/user.ts)', $comercio === ( $cuerpo['data']['id'] ?? null ) && (string) $comercio === $cuerpo['data']['_id'] && 'commerce' === $cuerpo['data']['role'] );
	$ok( 'el nombre es nombre + apellidos, nunca el de acceso', 0 === strpos( $cuerpo['data']['name'], 'Temporal ' ) && false === strpos( wp_json_encode( $cuerpo ), '-temporal-tokens"' ) );

	// --- R32: token que no vale = sin sesión ---
	$wpdb->update( ZN_Tokens::tabla(), array( 'caduca' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'hash' => hash( 'sha256', $token ) ) );
	list( $estado, $cuerpo ) = $pedir( "/user/$comercio", $token );
	$ok( 'R32: token caducado en ruta privada: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	list( $estado ) = $pedir( '/business?perPage=1', $token );
	$ok( 'R32: token caducado en GET /business: 200 (como un visitante)', 200 === $estado );
	list( $estado ) = $pedir( '/business?perPage=1', 'no-es-un-token' );
	$ok( 'R32: token inventado en GET /business: 200', 200 === $estado );
	list( $estado, $cuerpo ) = $pedir( "/user/$comercio", str_repeat( 'A', 43 ) );
	$ok( 'R32: token con buena forma pero inexistente: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );

	// --- R32: token válido sin permiso ---
	$otro  = $cuenta( 'zn_comercio' );
	$token = ZN_Tokens::emitir( $comercio );
	list( $estado, $cuerpo ) = $pedir( "/user/$otro", $token );
	$ok( 'R32: token válido, cuenta ajena: 401 «This action is unauthorized.» (la app no cierra la sesión)', 401 === $estado && $sin_permiso === $cuerpo );
	list( $estado ) = $pedir( '/user/999999', $token );
	$ok( 'cuenta que no existe: 404', 404 === $estado );
	list( $estado ) = $pedir( "/user/$wp_admin", $token );
	$ok( 'una cuenta de wp-admin no se puede pedir: 404', 404 === $estado );

	// --- Padre e hija ---
	$asociacion = $cuenta( 'zn_asociacion' );
	$hija       = $cuenta( 'zn_comercio', $asociacion );
	list( $estado, $cuerpo ) = $pedir( "/user/$hija", ZN_Tokens::emitir( $asociacion ) );
	$ok( 'la asociación ve su comercio (user:read_commerce)', 200 === $estado && $hija === $cuerpo['data']['id'] );
	$ok( 'parent con _id, id, name, email y role', array( '_id', 'id', 'name', 'email', 'role' ) === array_keys( (array) $cuerpo['data']['parent'] ) && $asociacion === $cuerpo['data']['parent']['id'] );

	// --- Anular ---
	$token = ZN_Tokens::emitir( $comercio );
	ZN_Tokens::anular( $token );
	$ok( 'token anulado (cerrar sesión): ya no vale', 0 === ZN_Tokens::validar( $token ) );
	$token = ZN_Tokens::emitir( $comercio );
	wp_set_password( wp_generate_password( 24 ), $comercio );
	$ok( 'cambiar la contraseña anula sus tokens', 0 === ZN_Tokens::validar( $token ) );
	$token = ZN_Tokens::emitir( $comercio );
	wp_update_user( array( 'ID' => $comercio, 'user_pass' => wp_generate_password( 24 ) ) );
	$ok( 'cambiar la contraseña desde el perfil también', 0 === ZN_Tokens::validar( $token ) );
	$token = ZN_Tokens::emitir( $comercio );
	( new WP_User( $comercio ) )->set_role( 'zn_usuario' );
	$ok( 'cambiar el rol anula sus tokens', 0 === ZN_Tokens::validar( $token ) );
	$token = ZN_Tokens::emitir( $otro );
	wp_delete_user( $otro );
	$ok( 'borrar la cuenta anula sus tokens', 0 === ZN_Tokens::validar( $token ) );
	$token = ZN_Tokens::emitir( $hija );
	( new WP_User( $hija ) )->add_role( 'editor' );
	$ok( 'una cuenta con dos roles no tiene sesión aunque tenga token', 0 === ZN_Tokens::validar( $token ) );

	// --- R39: el token solo vale en /zonanautico/v1 ---
	$con   = $cuenta( 'zn_usuario' );
	$token = ZN_Tokens::emitir( $con );
	$antes = $_SERVER;
	$probar = static function ( string $uri, array $get = array(), string $cabecera = 'HTTP_AUTHORIZATION' ) use ( $token ) {
		$_SERVER['REQUEST_URI'] = $uri;
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$_SERVER[ $cabecera ] = "Bearer $token";
		$_GET                 = $get;
		return ZN_Tokens::usuario_del_token( false );
	};
	$ok( 'R39: vale en /wp-json/zonanautico/v1/…', $con === $probar( '/wp-json/zonanautico/v1/user/1' ) );
	$ok( 'R39: vale con ?rest_route=/zonanautico/v1/…', $con === $probar( '/', array( 'rest_route' => '/zonanautico/v1/user/1' ) ) );
	$ok( 'R39: vale con otras mayúsculas (/ZonaNautico/V1)', $con === $probar( '/wp-json/ZonaNautico/V1/user/1' ) );
	$ok( 'R39: lee REDIRECT_HTTP_AUTHORIZATION (Apache)', $con === $probar( '/wp-json/zonanautico/v1/user/1', array(), 'REDIRECT_HTTP_AUTHORIZATION' ) );
	$ok( 'R39: no vale en /wp/v2', false === $probar( '/wp-json/wp/v2/types' ) );
	$ok( 'R39: no vale en admin-ajax.php', false === $probar( '/wp-admin/admin-ajax.php' ) );
	$ok( 'R39: no vale en ?rest_route=/wp/v2', false === $probar( '/', array( 'rest_route' => '/wp/v2/types' ) ) );
	$ok( 'R39: no vale en una ruta que solo empieza igual (/zonanautico/v10)', false === $probar( '/wp-json/zonanautico/v10/x' ) );
	$_SERVER = $antes;
	$_GET    = array();
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
$tokens = $cuentas ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Tokens::tabla() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $cuentas ) ) . ')' ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas temporales y sus tokens', count( $cuentas ) ), ! $quedan && 0 === $tokens );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

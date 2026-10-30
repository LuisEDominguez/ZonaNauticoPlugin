<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
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
// El Local de Windows reinicia su PHP tras muchas peticiones seguidas (502 unos segundos): se espera y se repite.
$http = static function ( string $metodo, string $url, array $opciones ) {
	for ( $intento = 0; $intento < 3; $intento++ ) {
		usleep( 300000 );
		$respuesta = wp_remote_request( $url, $opciones + array( 'method' => $metodo, 'timeout' => 60 ) );
		if ( ! is_wp_error( $respuesta ) && 502 !== wp_remote_retrieve_response_code( $respuesta ) ) {
			return $respuesta;
		}
		sleep( 15 );
	}
	return $respuesta;
};
$pedir = static function ( string $ruta, ?string $token = null ) use ( $base, $http ) {
	$respuesta = $http( 'GET', $base . $ruta, array( 'headers' => $token ? array( 'Authorization' => "Bearer $token" ) : array() ) );
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

	// --- R39, R46, R47: el token solo vale en /zonanautico/v1, por index.php ---
	$con   = $cuenta( 'zn_usuario' );
	$token = ZN_Tokens::emitir( $con );

	// R46 por HTTP de verdad: admin-ajax con ?rest_route=/zonanautico/v1/ y el token no debe dar sesión.
	$latido = static function ( string $url ) use ( $token, $http ) {
		$r = $http( 'POST', $url, array( 'headers' => array( 'Authorization' => "Bearer $token" ), 'body' => array( 'action' => 'heartbeat', '_nonce' => 'x' ) ) );
		return json_decode( wp_remote_retrieve_body( $r ), true );
	};
	$con_truco = $latido( admin_url( 'admin-ajax.php?rest_route=/zonanautico/v1/' ) );
	$ok( 'R46: admin-ajax.php?rest_route=/zonanautico/v1/ con token: sin sesión (wp-auth-check false)', is_array( $con_truco ) && false === ( $con_truco['wp-auth-check'] ?? null ) && ! isset( $con_truco['rest_nonce'] ) );
	$ok( 'R46: y sin el truco, igual', false === ( $latido( admin_url( 'admin-ajax.php' ) )['wp-auth-check'] ?? null ) );

	$antes_server = $_SERVER;
	$antes_get    = $_GET;
	try {
		$probar = static function ( string $uri, array $get = array(), string $cabecera = 'HTTP_AUTHORIZATION', string $fichero = '/index.php' ) use ( $token ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$_SERVER['SCRIPT_NAME'] = $fichero;
			unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
			$_SERVER[ $cabecera ] = "Bearer $token";
			$_GET                 = $get;
			return ZN_Tokens::usuario_del_token( false );
		};
		$ok( 'R39: vale en /wp-json/zonanautico/v1/…', $con === $probar( '/wp-json/zonanautico/v1/user/1' ) );
		$ok( 'R39: vale con /?rest_route=/zonanautico/v1/…', $con === $probar( '/', array( 'rest_route' => '/zonanautico/v1/user/1' ) ) );
		$ok( 'R39: vale con /index.php?rest_route=/zonanautico/v1/…', $con === $probar( '/index.php', array( 'rest_route' => '/zonanautico/v1/user/1' ) ) );
		$ok( 'R39: vale con otras mayúsculas (/ZonaNautico/V1)', $con === $probar( '/wp-json/ZonaNautico/V1/user/1' ) );
		$ok( 'R39: lee REDIRECT_HTTP_AUTHORIZATION (Apache)', $con === $probar( '/wp-json/zonanautico/v1/user/1', array(), 'REDIRECT_HTTP_AUTHORIZATION' ) );
		$ok( 'R39: no vale en /wp/v2', false === $probar( '/wp-json/wp/v2/types' ) );
		$ok( 'R39: no vale en ?rest_route=/wp/v2', false === $probar( '/', array( 'rest_route' => '/wp/v2/types' ) ) );
		$ok( 'R39: no vale en una ruta que solo empieza igual (/zonanautico/v10)', false === $probar( '/wp-json/zonanautico/v10/x' ) );
		$ok( 'R46: no vale si el fichero no es index.php (admin-ajax con rest_route)', false === $probar( '/wp-admin/admin-ajax.php', array( 'rest_route' => '/zonanautico/v1/' ), 'HTTP_AUTHORIZATION', '/wp-admin/admin-ajax.php' ) );
		$ok( 'R46: ni wp-login.php ni wp-cron.php con rest_route', false === $probar( '/wp-login.php', array( 'rest_route' => '/zonanautico/v1/' ), 'HTTP_AUTHORIZATION', '/wp-login.php' ) && false === $probar( '/wp-cron.php', array( 'rest_route' => '/zonanautico/v1/' ), 'HTTP_AUTHORIZATION', '/wp-cron.php' ) );
		$ok( 'R46: ?rest_route solo en la portada o /index.php (no en /otra-pagina/)', false === $probar( '/otra-pagina/', array( 'rest_route' => '/zonanautico/v1/' ) ) );

		// R47: WordPress en una subcarpeta (/sitio): el camino tiene que empezar por la subcarpeta de verdad.
		$subcarpeta = static fn( $url ) => str_replace( '://localhost:10010/', '://localhost:10010/sitio/', $url );
		add_filter( 'home_url', $subcarpeta );
		$ok( 'R47: en una subcarpeta vale /sitio/wp-json/zonanautico/v1/…', $con === $probar( '/sitio/wp-json/zonanautico/v1/user/1' ) );
		$ok( 'R47: y no /xxxxxwp-json/zonanautico/v1 (recorte sin comprobar)', false === $probar( '/xxxxxwp-json/zonanautico/v1/user/1' ) );
		$ok( 'R47: ni /wp-json/zonanautico/v1 fuera de la subcarpeta', false === $probar( '/wp-json/zonanautico/v1/user/1' ) );
		$ok( 'R47: con /index.php delante, también (/sitio/index.php/wp-json/…)', $con === $probar( '/sitio/index.php/wp-json/zonanautico/v1/user/1' ) );
		remove_filter( 'home_url', $subcarpeta );

		// R54: si alguien pregunta por el usuario en plugins_loaded, $wp_rewrite aún no existe: sin error fatal.
		$reescritura           = $GLOBALS['wp_rewrite'];
		$GLOBALS['wp_rewrite'] = null;
		try {
			$ok( 'R54: sin $wp_rewrite (plugins_loaded), el token vale y no hay error fatal', $con === $probar( '/wp-json/zonanautico/v1/user/1' ) );
		} catch ( Throwable $e ) {
			$ok( 'R54: sin $wp_rewrite (plugins_loaded): ' . $e->getMessage(), false );
		} finally {
			$GLOBALS['wp_rewrite'] = $reescritura;
		}

		// R46, segunda barrera: si la cuenta vino del token y se despacha otra ruta, se quita la sesión.
		$probar( '/wp-json/zonanautico/v1/user/1' );
		wp_set_current_user( $con );
		ZN_Tokens::solo_en_nuestra_ruta( null, rest_get_server(), new WP_REST_Request( 'GET', '/wp/v2/types' ) );
		$ok( 'R46: segunda barrera: con otra ruta REST, se quita la sesión del token', 0 === get_current_user_id() );
	} finally {
		$_SERVER = $antes_server;
		$_GET    = $antes_get;
		wp_set_current_user( 0 );
	}

	// --- R56 y R52: al cambiar la contraseña o el correo desde la app, sigue valiendo el token de quien lo cambia ---
	$quien  = $cuenta( 'zn_usuario' );
	$en_uso = ZN_Tokens::emitir( $quien );
	$otro_t = ZN_Tokens::emitir( $quien );
	$antes_server = $_SERVER;
	$_SERVER['REQUEST_URI']        = '/wp-json/zonanautico/v1/user/' . $quien;
	$_SERVER['SCRIPT_NAME']        = '/index.php';
	$_SERVER['HTTP_AUTHORIZATION'] = "Bearer $en_uso";
	try {
		wp_set_password( wp_generate_password( 24 ), $quien );
		$ok( 'R56: tras cambiar la contraseña, el token de quien la cambia sigue valiendo', $quien === ZN_Tokens::validar( $en_uso ) );
		$ok( 'R56: y los demás tokens de la cuenta quedan anulados', 0 === ZN_Tokens::validar( $otro_t ) );
		$otro_t = ZN_Tokens::emitir( $quien );
		wp_update_user( array( 'ID' => $quien, 'user_email' => "nuevo-$quien-temporal-tokens@ejemplo.invalid" ) );
		$ok( 'R52: cambiar el correo anula los demás tokens', 0 === ZN_Tokens::validar( $otro_t ) && $quien === ZN_Tokens::validar( $en_uso ) );
	} finally {
		$_SERVER = $antes_server;
	}
	$ok( 'R56: sin petición con token (wp-admin, WP-CLI), cambiar la contraseña los anula todos', ( static function () use ( $quien, $en_uso ) {
		wp_set_password( wp_generate_password( 24 ), $quien );
		return 0 === ZN_Tokens::validar( $en_uso );
	} )() );

	// --- R64: las contraseñas del Laravel ($2y$, bcrypt) valen tal cual en WordPress ---
	$migrada = $cuenta( 'zn_usuario' );
	$wpdb->update( $wpdb->users, array( 'user_pass' => password_hash( 'Inventada-del-Laravel-1', PASSWORD_BCRYPT, array( 'cost' => 10 ) ) ), array( 'ID' => $migrada ) );
	clean_user_cache( $migrada );
	$hash = get_userdata( $migrada )->user_pass;
	$ok( 'R64: el hash se guarda tal cual ($2y$10$…, como el Laravel)', 0 === strpos( $hash, '$2y$10$' ) );
	$ok( 'R64: wp_check_password acepta la contraseña buena', wp_check_password( 'Inventada-del-Laravel-1', $hash, $migrada ) );
	$ok( 'R64: y rechaza una mala', ! wp_check_password( 'otra', $hash, $migrada ) );

	// --- R48 y R51 ---
	$ok( 'R48: la tabla se instala en plugins_loaded (antes de mirar el usuario)', false !== has_action( 'plugins_loaded', array( 'ZN_Tokens', 'instalar' ) ) );
	$ok( 'R48: índice en caduca', (bool) $wpdb->get_row( "SHOW INDEX FROM " . ZN_Tokens::tabla() . " WHERE Key_name = 'caduca'" ) );
	$ruta = rest_get_server()->get_routes( ZN_NAMESPACE )[ '/' . ZN_NAMESPACE . '/user/(?P<id>\\d+)' ][0] ?? array();
	$ok( 'R51: GET /user/{id} comprueba la sesión en la entrada', array( 'ZN_API', 'con_sesion_de_la_app' ) === ( $ruta['permission_callback'] ?? null ) );
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

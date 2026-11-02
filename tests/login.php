<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Login de la app: POST /login, /logout y /refresh (R30, R38, R39, R59). Uso: wp eval-file tests/login.php
// Crea cuentas temporales («temporal-login», contraseñas al azar), llama a las rutas por dentro (con IPs de
// documentación) y por HTTP de verdad (como la app), y al terminar borra las cuentas, sus tokens y solo las
// filas de intentos que ha usado.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$servidor = $_SERVER;
$base     = rest_url( ZN_NAMESPACE );
$cuentas  = array();
$claves   = array();
$correos  = array();
// Cuenta temporal con contraseña al azar; verificada salvo que se diga otra cosa.
$cuenta = static function ( string $rol_wp, bool $verificada = true ) use ( &$cuentas, &$correos ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-temporal-login';
	$clave = wp_generate_password( 20 );
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => $clave, 'role' => $rol_wp, 'first_name' => 'Temporal' ) );
	if ( $verificada ) {
		update_user_meta( $id, 'zn_verificado', '1' );
	}
	$cuentas[] = $id;
	$correos[] = "$login@ejemplo.invalid";
	return array( $id, "$login@ejemplo.invalid", $clave );
};
// Llamada por dentro, desde una IP de documentación: apunta las claves de intentos que toca, para borrarlas.
$entrar = static function ( array $cuerpo, string $ip = '192.0.2.60' ) use ( &$claves ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$email                  = is_string( $cuerpo['email'] ?? null ) ? $cuerpo['email'] : null;
	$claves                 = array_merge( $claves, array_keys( ZN_Limite::claves( 'login', $email ) ) );
	$peticion               = new WP_REST_Request( 'POST', '/' . ZN_NAMESPACE . '/login' );
	$peticion->set_header( 'Content-Type', 'application/json' );
	$peticion->set_body( wp_json_encode( $cuerpo ) );
	$respuesta = rest_do_request( $peticion );
	return array( $respuesta->get_status(), $respuesta->get_data(), $respuesta->get_headers() );
};
// El Local de Windows reinicia su PHP tras muchas peticiones seguidas (502 unos segundos): se espera y se repite.
$http = static function ( string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null ) use ( $base ) {
	$cabeceras = array( 'Content-Type' => 'application/json' );
	if ( $token ) {
		$cabeceras['Authorization'] = "Bearer $token";
	}
	for ( $intento = 0; $intento < 3; $intento++ ) {
		usleep( 400000 );
		$respuesta = wp_remote_request( $base . $ruta, array( 'method' => $metodo, 'timeout' => 60, 'headers' => $cabeceras, 'body' => null === $cuerpo ? null : wp_json_encode( $cuerpo ) ) );
		if ( ! is_wp_error( $respuesta ) && 502 !== wp_remote_retrieve_response_code( $respuesta ) ) {
			break;
		}
		sleep( 15 );
	}
	if ( is_wp_error( $respuesta ) ) {
		return array( 0, null, array() );
	}
	return array( wp_remote_retrieve_response_code( $respuesta ), json_decode( wp_remote_retrieve_body( $respuesta ), true ), wp_remote_retrieve_headers( $respuesta ) );
};
// Cuántas veces se comprueba una contraseña (para R38: siempre una, exista o no la cuenta).
$comprobaciones = 0;
add_filter(
	'check_password',
	static function ( $vale ) use ( &$comprobaciones ) {
		++$comprobaciones;
		return $vale;
	}
);
$credenciales_malas = array( 'status' => 'error', 'message' => 'Unauthorized' );
$no_autenticado     = array( 'message' => 'Unauthenticated.' );

try {
	list( $usuario, $email, $clave ) = $cuenta( 'zn_usuario' );

	// --- Las rutas ---
	$rutas = rest_get_server()->get_routes( ZN_NAMESPACE );
	foreach ( array( 'login', 'logout', 'refresh' ) as $nombre ) {
		$metodos = array_keys( $rutas[ '/' . ZN_NAMESPACE . "/$nombre" ][0]['methods'] ?? array() );
		$ok( "existe POST /$nombre (solo POST)", array( 'POST' ) === $metodos );
	}

	// --- Login bueno (R39: el contrato de src/api/auth.ts) ---
	list( $estado, $cuerpo, $cabeceras ) = $entrar( array( 'email' => $email, 'password' => $clave ) );
	$ok( 'login bueno: 200', 200 === $estado );
	$ok( 'login bueno: {status, token, id, role} en ese orden', array( 'status', 'token', 'id', 'role' ) === array_keys( (array) $cuerpo ) );
	$ok( 'login bueno: status «success», id numérico y role «user»', 'success' === ( $cuerpo['status'] ?? null ) && $usuario === ( $cuerpo['id'] ?? null ) && 'user' === ( $cuerpo['role'] ?? null ) );
	$ok( 'login bueno: el token vale y es de esa cuenta', $usuario === ZN_Tokens::validar( (string) ( $cuerpo['token'] ?? '' ) ) );
	$ok( 'login bueno: sin caché (Cache-Control: no-store)', false !== strpos( (string) ( $cabeceras['Cache-Control'] ?? '' ), 'no-store' ) );
	$ok( 'login bueno: no deja sesión de WordPress abierta en la petición', 0 === get_current_user_id() );
	list( $estado, $cuerpo ) = $entrar( array( 'email' => '  ' . strtoupper( $email ) . ' ', 'password' => $clave ) );
	$ok( 'el email con mayúsculas y espacios también vale', 200 === $estado && $usuario === ( $cuerpo['id'] ?? null ) );
	foreach ( array( 'zn_administrador' => 'administrator', 'zn_federacion' => 'federation', 'zn_asociacion' => 'association', 'zn_comercio' => 'commerce' ) as $rol_wp => $rol ) {
		list( $otro, $otro_email, $otra_clave ) = $cuenta( $rol_wp );
		list( $estado, $cuerpo )                 = $entrar( array( 'email' => $otro_email, 'password' => $otra_clave ) );
		$ok( "rol «{$rol}» en la respuesta", 200 === $estado && $rol === ( $cuerpo['role'] ?? null ) && $otro === ( $cuerpo['id'] ?? null ) );
	}

	// --- R38: sin wp_authenticate; una cuenta de la app entra aunque wp-login.php la rechace ---
	$ok( 'R38: wp-login.php rechaza la cuenta de la app…', is_wp_error( wp_authenticate( $email, $clave ) ) );
	list( $estado ) = $entrar( array( 'email' => $email, 'password' => $clave ) );
	$ok( 'R38: …y POST /login la deja entrar', 200 === $estado );

	// --- R30, R38: credenciales malas, todas iguales ---
	list( $wp_admin, $wp_admin_email, $wp_admin_clave ) = $cuenta( 'administrator' );
	list( $mixta, $mixta_email, $mixta_clave )          = $cuenta( 'zn_usuario' );
	get_userdata( $mixta )->add_role( 'editor' );
	list( $sin_verificar, $sin_verificar_email, $sin_verificar_clave ) = $cuenta( 'zn_usuario', false );
	$casos = array(
		'contraseña mala'                             => array( $email, 'no-es-la-buena' ),
		'email que no existe'                         => array( 'nadie-temporal-login@ejemplo.invalid', $clave ),
		'cuenta de wp-admin con su contraseña buena'  => array( $wp_admin_email, $wp_admin_clave ),
		'cuenta con rol de la app y otro de WordPress' => array( $mixta_email, $mixta_clave ),
		'sin verificar y contraseña mala (no delata)' => array( $sin_verificar_email, 'no-es-la-buena' ),
	);
	foreach ( $casos as $nombre => list( $correo, $contrasena ) ) {
		$comprobaciones           = 0;
		list( $estado, $cuerpo )  = $entrar( array( 'email' => $correo, 'password' => $contrasena ) );
		$ok( "R30: $nombre: 401 {status: «error», message: «Unauthorized»}", 401 === $estado && $credenciales_malas === $cuerpo );
		$ok( "R38: $nombre: se comprueba una contraseña (mismo tiempo exista o no)", 1 === $comprobaciones );
	}
	$ok( 'R38: una cuenta de wp-admin no recibe token', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZN_Tokens::tabla() . ' WHERE user_id = %d', $wp_admin ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// --- Sin verificar el correo (src/api/auth.ts:24-26) ---
	list( $estado, $cuerpo ) = $entrar( array( 'email' => $sin_verificar_email, 'password' => $sin_verificar_clave ) );
	$ok( 'sin verificar y contraseña buena: 401 {status: «email-not-verified»}', 401 === $estado && 'email-not-verified' === ( $cuerpo['status'] ?? null ) && isset( $cuerpo['message'] ) );
	$ok( 'sin verificar: no recibe token', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZN_Tokens::tabla() . ' WHERE user_id = %d', $sin_verificar ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// --- Datos que no valen: 422 y sin contar el intento ---
	$no_cuenta = array( 'email' => 'esto-no-es-un-correo', 'password' => 'lo-que-sea' );
	$claves_del_raro = array_keys( ZN_Limite::claves( 'login', $no_cuenta['email'] ) );
	list( $estado, $cuerpo ) = $entrar( $no_cuenta );
	$ok( '422 con el email mal escrito (texto del Laravel)', 422 === $estado && array( 'The email field must be a valid email address.' ) === ( $cuerpo['errors']['email'] ?? null ) );
	$cuenta_ip = $claves_del_raro[0] ?? '';
	$ok( 'el email mal escrito no cuenta como intento (se valida antes)', '' !== $cuenta_ip && null === $wpdb->get_var( $wpdb->prepare( 'SELECT intentos FROM ' . ZN_Limite::tabla() . ' WHERE clave = %s', $cuenta_ip ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	list( $estado, $cuerpo ) = $entrar( array( 'password' => 'x' ) );
	$ok( '422 sin email: «The email field is required.»', 422 === $estado && array( 'The email field is required.' ) === ( $cuerpo['errors']['email'] ?? null ) );
	list( $estado, $cuerpo ) = $entrar( array( 'email' => $email ) );
	$ok( '422 sin contraseña: «The password field is required.»', 422 === $estado && array( 'The password field is required.' ) === ( $cuerpo['errors']['password'] ?? null ) );
	list( $estado, $cuerpo ) = $entrar( array( 'email' => array( $email ), 'password' => array( $clave ) ) );
	$ok( '422 si llegan listas en vez de texto', 422 === $estado && isset( $cuerpo['errors']['email'], $cuerpo['errors']['password'] ) );

	// --- R59: límite de intentos, antes de mirar la contraseña ---
	list( , $email_limite, $clave_limite ) = $cuenta( 'zn_usuario' );
	for ( $i = 0; $i < 5; $i++ ) {
		$entrar( array( 'email' => $email_limite, 'password' => 'mala' ), '192.0.2.61' );
	}
	$comprobaciones                       = 0;
	list( $estado, $cuerpo, $cabeceras ) = $entrar( array( 'email' => $email_limite, 'password' => $clave_limite ), '192.0.2.61' );
	$ok( 'R59: al 6.º intento en 15 min, 429 «Too Many Attempts.» con Retry-After, aunque la contraseña sea buena', 429 === $estado && array( 'message' => 'Too Many Attempts.' ) === $cuerpo && (int) ( $cabeceras['Retry-After'] ?? 0 ) > 0 );
	$ok( 'R59: con 429 no se llega a comprobar la contraseña', 0 === $comprobaciones );
	list( $estado ) = $entrar( array( 'email' => $email_limite, 'password' => $clave_limite ), '192.0.2.62' );
	$ok( 'R75: el dueño sigue entrando desde otra IP', 200 === $estado );
	list( , $email_olvido, $clave_olvido ) = $cuenta( 'zn_usuario' );
	for ( $i = 0; $i < 4; $i++ ) {
		$entrar( array( 'email' => $email_olvido, 'password' => 'mala' ), '192.0.2.63' );
	}
	$entrar( array( 'email' => $email_olvido, 'password' => $clave_olvido ), '192.0.2.63' );
	list( $estado ) = $entrar( array( 'email' => $email_olvido, 'password' => 'mala' ), '192.0.2.63' );
	$ok( 'R59: un login bueno pone a cero los fallos de esa cuenta desde esa IP', 401 === $estado );

	// --- R39: cerrar las sesiones de WordPress que tuviera la cuenta ---
	$sesiones = WP_Session_Tokens::get_instance( $usuario );
	$sesiones->create( time() + HOUR_IN_SECONDS );
	$ok( 'R39: (antes) la cuenta tiene una sesión de WordPress', 1 === count( $sesiones->get_all() ) );
	$entrar( array( 'email' => $email, 'password' => $clave ) );
	$ok( 'R39: el login cierra las sesiones de WordPress de la cuenta', array() === WP_Session_Tokens::get_instance( $usuario )->get_all() );

	// --- Por HTTP de verdad, como la app ---
	$_SERVER = $servidor;
	list( $estado, $cuerpo, $cabeceras ) = $http( 'POST', '/login', array( 'email' => $email, 'password' => $clave ), 'basura-de-una-sesion-vieja' );
	$ok( 'HTTP: login bueno (con un token viejo en la cabecera): 200 y token', 200 === $estado && 'success' === ( $cuerpo['status'] ?? null ) && $usuario === ( $cuerpo['id'] ?? null ) && is_string( $cuerpo['token'] ?? null ) );
	$token = (string) ( $cuerpo['token'] ?? '' );
	$ok( 'HTTP: no pone cookies de WordPress', false === stripos( wp_json_encode( $cabeceras['set-cookie'] ?? '' ), 'wordpress_' ) );
	$ok( 'HTTP: sin caché', false !== strpos( (string) ( $cabeceras['cache-control'] ?? '' ), 'no-store' ) );
	list( $estado ) = $http( 'POST', '/login?' . http_build_query( array( 'email' => $email, 'password' => $clave ) ) );
	$ok( 'H8: email y contraseña en la dirección no valen (solo en el cuerpo JSON): 422', 422 === $estado );
	list( $estado, $cuerpo ) = $http( 'POST', '/login', array( 'email' => 'nadie-temporal-login@ejemplo.invalid', 'password' => 'x' ) );
	$ok( 'HTTP: email que no existe: 401 «error» (no 500, H28)', 401 === $estado && $credenciales_malas === $cuerpo );
	list( $estado ) = $http( 'GET', "/user/$usuario", null, $token );
	$ok( 'HTTP: con el token del login, GET /user/{id}: 200', 200 === $estado );

	// --- POST /refresh ---
	list( $estado, $cuerpo ) = $http( 'POST', '/refresh' );
	$ok( 'refresh sin token: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	list( $estado, $cuerpo, $cabeceras ) = $http( 'POST', '/refresh', null, $token );
	$nuevo = (string) ( $cuerpo['token'] ?? '' );
	$ok( 'refresh: 200 {status, token, id, role}, como el login', 200 === $estado && array( 'status', 'token', 'id', 'role' ) === array_keys( (array) $cuerpo ) && $usuario === $cuerpo['id'] && 'user' === $cuerpo['role'] );
	$ok( 'refresh: token nuevo que vale', '' !== $nuevo && $nuevo !== $token && $usuario === ZN_Tokens::validar( $nuevo ) );
	$ok( 'refresh: el anterior ya no vale', 0 === ZN_Tokens::validar( $token ) );
	$ok( 'refresh: sin caché', false !== strpos( (string) ( $cabeceras['cache-control'] ?? '' ), 'no-store' ) );
	list( $estado, $cuerpo ) = $http( 'POST', '/refresh', null, $token );
	$ok( 'refresh con el token ya cambiado: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	$caducado = ZN_Tokens::emitir( $usuario );
	$wpdb->update( ZN_Tokens::tabla(), array( 'caduca' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'hash' => hash( 'sha256', $caducado ) ) );
	list( $estado ) = $http( 'POST', '/refresh', null, $caducado );
	$ok( 'refresh con un token caducado: 401 (hay que entrar de nuevo)', 401 === $estado );

	// --- POST /logout ---
	$otro_movil = ZN_Tokens::emitir( $usuario );
	list( $estado, $cuerpo ) = $http( 'POST', '/logout' );
	$ok( 'logout sin token: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	list( $estado, $cuerpo ) = $http( 'POST', '/logout', null, $nuevo );
	$ok( 'logout: 200 {message}', 200 === $estado && is_string( $cuerpo['message'] ?? null ) );
	$ok( 'logout: ese token ya no vale', 0 === ZN_Tokens::validar( $nuevo ) );
	list( $estado ) = $http( 'GET', "/user/$usuario", null, $nuevo );
	$ok( 'logout: con ese token, GET /user/{id}: 401', 401 === $estado );
	$ok( 'logout: la sesión de otro móvil de la misma cuenta sigue', $usuario === ZN_Tokens::validar( $otro_movil ) );
	list( $estado ) = $http( 'POST', '/logout', null, $nuevo );
	$ok( 'logout dos veces: la segunda, 401', 401 === $estado );
} finally {
	// Las filas de intentos de las peticiones por HTTP, desde la IP con la que llega este equipo al sitio
	// (antes de borrar las cuentas: la clave de una cuenta que existe va por su número).
	foreach ( array( '127.0.0.1', '::1' ) as $ip ) {
		$_SERVER['REMOTE_ADDR'] = $ip;
		foreach ( array_merge( $correos, array( 'nadie-temporal-login@ejemplo.invalid' ) ) as $correo ) {
			$claves = array_merge( $claves, array_keys( ZN_Limite::claves( 'login', $correo ) ) );
		}
	}
	$_SERVER = $servidor;
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
$quedan = array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) );
$tokens = $cuentas ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Tokens::tabla() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $cuentas ) ) . ')' ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$intentos = $claves ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', array_unique( $claves ) ) ) . "')" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas temporales, sus tokens y sus filas de intentos', count( $cuentas ) ), ! $quedan && 0 === $tokens && 0 === $intentos );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

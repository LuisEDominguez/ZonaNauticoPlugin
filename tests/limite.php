<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Límite de intentos común (R59, R75-R77). Uso: wp eval-file tests/limite.php
// Escribe filas temporales en la tabla de intentos (correos inventados e IPs de documentación) y borra
// exactamente esas al terminar.

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$servidor = $_SERVER;
$tabla    = ZN_Limite::tabla();
$mias     = array();
// Cada intento apunta las claves que usa, para borrar solo esas al final.
$intentar = static function ( string $accion, ?string $email, string $ip ) use ( &$mias ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$mias                   = array_merge( $mias, array_keys( ZN_Limite::claves( $accion, $email ) ) );
	return ZN_Limite::intentar( $accion, $email );
};
$filas = static function ( array $claves ) use ( $wpdb, $tabla ) {
	if ( ! $claves ) {
		return 0;
	}
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tabla WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', array_unique( $claves ) ) ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
};
$victima = 'victima-temporal@ejemplo.invalid';

try {
	$ok( 'existe la tabla de intentos', $tabla === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabla ) ) );
	$ok( 'la tabla se instala en plugins_loaded', false !== has_action( 'plugins_loaded', array( 'ZN_Limite', 'instalar' ) ) );

	// --- R75: nadie bloquea a otra persona ---
	list( $max_cuenta_ip ) = ZN_Limite::REGLAS['login']['cuenta_ip'];
	$esperas = array();
	for ( $i = 0; $i < $max_cuenta_ip; $i++ ) {
		$esperas[] = $intentar( 'login', $victima, '192.0.2.10' );
	}
	$ok( "login: los $max_cuenta_ip primeros intentos de una cuenta desde una IP pasan", array( null ) === array_unique( $esperas ) );
	$espera = $intentar( 'login', 'Victima-Temporal@Ejemplo.invalid ', '192.0.2.10' );
	$ok( 'login: el siguiente no (mismo correo con mayúsculas y espacios) y dice cuánto esperar', is_int( $espera ) && $espera > 800 && $espera <= 900 );
	$ok( 'R75: el dueño de la cuenta sigue entrando desde otra IP', null === $intentar( 'login', $victima, '198.51.100.20' ) );
	ZN_Limite::olvidar( 'login', $victima );
	$ok( 'tras un acierto, esa cuenta desde esa IP vuelve a cero', null === $intentar( 'login', $victima, '198.51.100.20' ) );

	// Muchas IPs contra una cuenta: el tope por cuenta (alto) acaba parándolo.
	list( $max_cuenta ) = ZN_Limite::REGLAS['login']['cuenta'];
	$repartido          = 'repartido-temporal@ejemplo.invalid';
	$parado             = false;
	for ( $i = 0; $i <= $max_cuenta; $i++ ) {
		$parado = null !== $intentar( 'login', $repartido, '203.0.113.' . ( $i % 250 + 1 ) );
	}
	$ok( "R75: con IPs distintas, una cuenta se para pasado el tope por cuenta ($max_cuenta)", $parado );
	$ok( 'R75: …pero ese tope es mucho más alto que el de cuenta + IP', $max_cuenta >= 5 * $max_cuenta_ip );

	// Una IP probando muchas cuentas.
	list( $max_ip ) = ZN_Limite::REGLAS['login']['ip'];
	$parado         = false;
	for ( $i = 0; $i <= $max_ip; $i++ ) {
		$parado = null !== $intentar( 'login', "ip-$i-temporal@ejemplo.invalid", '192.0.2.12' );
	}
	$ok( "login: una IP que prueba $max_ip cuentas se para", $parado );
	$ok( 'las acciones no se mezclan: «recuperar» desde esa IP, sí', null === $intentar( 'recuperar', 'nuevo-temporal@ejemplo.invalid', '192.0.2.12' ) );

	// --- R77: una cuenta que existe cuenta por su número ---
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
	$_SERVER['REMOTE_ADDR'] = '192.0.2.30';
	$claves_correo          = ZN_Limite::claves( 'login', $admin->user_email );
	$claves_mayusculas      = ZN_Limite::claves( 'login', strtoupper( $admin->user_email ) );
	$ok( 'R77: una cuenta que existe da las mismas claves con su correo escrito de otra forma', array_keys( $claves_correo ) === array_keys( $claves_mayusculas ) );

	// --- R76: IPv6 por su /64 ---
	$ok( 'R76: grupo de una IPv6 = su /64', '2001:db8:1:2::/64' === ZN_Limite::grupo( '2001:db8:1:2:aaaa:bbbb:cccc:dddd' ) );
	$ok( 'R76: una IPv4 se queda igual', '192.0.2.1' === ZN_Limite::grupo( '192.0.2.1' ) );
	$ok( 'R83: una IPv4 escrita como IPv6 (::ffff:192.0.2.1) cuenta como 192.0.2.1', '192.0.2.1' === ZN_Limite::grupo( '::ffff:192.0.2.1' ) );
	$ok( 'R83: y dos de ellas no se juntan en «::/64»', ZN_Limite::grupo( '::ffff:1.2.3.4' ) !== ZN_Limite::grupo( '::ffff:9.9.9.9' ) );
	$_SERVER['REMOTE_ADDR'] = '::ffff:192.0.2.50';
	$ok( 'R83: ip() también la da en su forma normal', '192.0.2.50' === ZN_Limite::ip() );
	for ( $i = 0; $i < $max_cuenta_ip; $i++ ) {
		$intentar( 'login', 'v6-temporal@ejemplo.invalid', '2001:db8:1:2::' . dechex( $i + 1 ) );
	}
	$ok( 'R76: cambiar de dirección dentro del mismo /64 no salta el límite', null !== $intentar( 'login', 'v6-temporal@ejemplo.invalid', '2001:db8:1:2:ffff::9' ) );
	$ok( 'R76: desde otro /64, sí', null === $intentar( 'login', 'v6-temporal@ejemplo.invalid', '2001:db8:1:3::1' ) );

	// --- La ventana se renueva ---
	for ( $i = 0; $i <= $max_cuenta_ip; $i++ ) {
		$intentar( 'login', 'ventana-temporal@ejemplo.invalid', '192.0.2.13' );
	}
	$_SERVER['REMOTE_ADDR'] = '192.0.2.13';
	$ventana                = array_keys( ZN_Limite::claves( 'login', 'ventana-temporal@ejemplo.invalid' ) );
	$wpdb->query( "UPDATE $tabla SET desde = '" . gmdate( 'Y-m-d H:i:s', time() - 901 ) . "' WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', $ventana ) ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$ok( 'pasados 15 minutos desde el primer intento, vuelve a dejar', null === $intentar( 'login', 'ventana-temporal@ejemplo.invalid', '192.0.2.13' ) );
	$ok( '…y cuenta desde 1', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT intentos FROM $tabla WHERE clave = %s", $ventana[0] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// --- R77: huellas con clave secreta y limpieza en cada llamada ---
	$ok( 'R77: la huella no es el SHA-256 sin clave (no se puede sacar probando)', ! in_array( hash( 'sha256', 'login|ip|192.0.2.12' ), array_keys( ZN_Limite::claves( 'login', null ) ), true ) );
	$todo = wp_json_encode( $wpdb->get_results( "SELECT * FROM $tabla" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$ok( 'en la tabla solo hay huellas: ni la IP ni el correo', false === strpos( $todo, '192.0.2.10' ) && false === strpos( $todo, 'victima-temporal' ) );
	$wpdb->insert( $tabla, array( 'clave' => str_repeat( 'f', 64 ), 'intentos' => 1, 'desde' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ) );
	$mias[] = str_repeat( 'f', 64 );
	$intentar( 'login', null, '192.0.2.40' );
	$ok( 'R77: una fila caducada desaparece en la siguiente llamada', 0 === $filas( array( str_repeat( 'f', 64 ) ) ) );

	// --- 429 como el Laravel, también al salir por la API (ZN_Errores no lo toca) ---
	$r = ZN_Limite::demasiados( 120 );
	$ok( '429 {"message":"Too Many Attempts."} con Retry-After', 429 === $r->get_status() && array( 'message' => 'Too Many Attempts.' ) === $r->get_data() && '120' === ( $r->get_headers()['Retry-After'] ?? null ) );
	add_action(
		'rest_api_init',
		static function () {
			register_rest_route( ZN_NAMESPACE, '/prueba-limite', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => static fn() => ZN_Limite::demasiados( 77 ) ) );
		}
	);
	$respuesta = rest_do_request( new WP_REST_Request( 'POST', '/' . ZN_NAMESPACE . '/prueba-limite' ) );
	$ok( 'por la API: 429, el mismo cuerpo y Retry-After 77', 429 === $respuesta->get_status() && array( 'message' => 'Too Many Attempts.' ) === $respuesta->get_data() && '77' === ( $respuesta->get_headers()['Retry-After'] ?? null ) );

	// --- De qué IP se fía ---
	$_SERVER['REMOTE_ADDR']          = '203.0.113.5';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
	$ok( 'sin ZN_PROXIES, X-Forwarded-For no se mira (cualquiera se lo inventa)', '203.0.113.5' === ZN_Limite::ip() );
	if ( ! defined( 'ZN_PROXIES' ) ) {
		define( 'ZN_PROXIES', '10.0.0.0/8, 2001:db8::/32' );
	}
	$ok( 'con ZN_PROXIES, si REMOTE_ADDR no es un proxy de confianza, X-Forwarded-For tampoco', '203.0.113.5' === ZN_Limite::ip() );
	$_SERVER['REMOTE_ADDR']          = '10.1.2.3';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.7, 10.9.9.9';
	$ok( 'detrás del proxy: la primera IP por la derecha que no es un proxy (no la que pone el cliente)', '198.51.100.7' === ZN_Limite::ip() );
	$_SERVER['REMOTE_ADDR'] = '::ffff:10.1.2.3';
	$ok( 'R83: un proxy de confianza que llega como ::ffff:10.1.2.3 se reconoce', '198.51.100.7' === ZN_Limite::ip() );
	$_SERVER['REMOTE_ADDR']          = '10.1.2.3';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = 'esto no es una ip';
	$ok( 'X-Forwarded-For roto: se queda la del proxy', '10.1.2.3' === ZN_Limite::ip() );
	$_SERVER['REMOTE_ADDR']          = '2001:db8::1';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db9::5';
	$ok( 'también con IPv6 (rango 2001:db8::/32)', '2001:db9::5' === ZN_Limite::ip() );
	$_SERVER['REMOTE_ADDR'] = 'basura';
	$ok( 'REMOTE_ADDR que no es una IP: sin IP (no se cuenta por IP)', '' === ZN_Limite::ip() );
} finally {
	$_SERVER = $servidor;
	foreach ( array_chunk( array_unique( $mias ), 100 ) as $trozo ) {
		$wpdb->query( "DELETE FROM $tabla WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', $trozo ) ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
$ok( sprintf( 'limpieza: borradas solo las %d filas de esta prueba', count( array_unique( $mias ) ) ), 0 === $filas( $mias ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

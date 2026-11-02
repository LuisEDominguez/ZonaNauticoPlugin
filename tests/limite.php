<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Límite de intentos común (R59). Uso: wp eval-file tests/limite.php
// Escribe filas temporales en la tabla de intentos con una acción y correos inventados, y las borra al terminar.

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$servidor = $_SERVER;
$tabla    = ZN_Limite::tabla();
$antes    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tabla" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$momento  = gmdate( 'Y-m-d H:i:s', time() - 5 );

try {
	$ok( 'existe la tabla de intentos', $tabla === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabla ) ) );
	$ok( 'la tabla se instala en plugins_loaded', false !== has_action( 'plugins_loaded', array( 'ZN_Limite', 'instalar' ) ) );

	// --- Por email: 5 intentos de login cada 15 minutos ---
	$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
	list( $maximo ) = ZN_Limite::REGLAS['login']['email'];
	$esperas = array();
	for ( $i = 0; $i < $maximo; $i++ ) {
		$esperas[] = ZN_Limite::intentar( 'login', 'limite-temporal@ejemplo.invalid' );
	}
	$ok( "login: los $maximo primeros intentos pasan", array( null ) === array_unique( $esperas ) );
	$espera = ZN_Limite::intentar( 'login', 'Limite-Temporal@Ejemplo.invalid ' );
	$ok( 'login: el siguiente no (mismo correo con mayúsculas y espacios) y dice cuánto esperar', is_int( $espera ) && $espera > 800 && $espera <= 900 );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.11';
	$ok( 'login: desde otra IP, ese correo sigue bloqueado', null !== ZN_Limite::intentar( 'login', 'limite-temporal@ejemplo.invalid' ) );
	$ok( 'login: otro correo desde esa IP, sí', null === ZN_Limite::intentar( 'login', 'otro-temporal@ejemplo.invalid' ) );
	ZN_Limite::olvidar( 'login', 'limite-temporal@ejemplo.invalid' );
	$ok( 'tras un acierto, ese correo vuelve a cero', null === ZN_Limite::intentar( 'login', 'limite-temporal@ejemplo.invalid' ) );

	// --- Por IP: aunque cambie el correo ---
	$_SERVER['REMOTE_ADDR'] = '192.0.2.12';
	list( $max_ip ) = ZN_Limite::REGLAS['login']['ip'];
	for ( $i = 0; $i < $max_ip; $i++ ) {
		ZN_Limite::intentar( 'login', "ip-$i-temporal@ejemplo.invalid" );
	}
	$ok( "login: tras $max_ip intentos desde una IP con correos distintos, esa IP se para", null !== ZN_Limite::intentar( 'login', 'nuevo-temporal@ejemplo.invalid' ) );
	$ok( 'las acciones no se mezclan: «recuperar» desde esa IP, sí', null === ZN_Limite::intentar( 'recuperar', 'nuevo-temporal@ejemplo.invalid' ) );

	// --- La ventana se renueva ---
	$_SERVER['REMOTE_ADDR'] = '192.0.2.13';
	for ( $i = 0; $i <= $maximo; $i++ ) {
		ZN_Limite::intentar( 'login', 'ventana-temporal@ejemplo.invalid' );
	}
	$huella = hash( 'sha256', 'login|email|ventana-temporal@ejemplo.invalid' );
	$wpdb->update( $tabla, array( 'desde' => gmdate( 'Y-m-d H:i:s', time() - 901 ) ), array( 'clave' => $huella ) );
	$ok( 'pasados 15 minutos desde el primer intento, vuelve a dejar', null === ZN_Limite::intentar( 'login', 'ventana-temporal@ejemplo.invalid' ) );
	$ok( '…y cuenta desde 1', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT intentos FROM $tabla WHERE clave = %s", $huella ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// --- Ni la IP ni el correo se guardan en claro ---
	$filas = wp_json_encode( $wpdb->get_results( "SELECT * FROM $tabla" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$ok( 'en la tabla solo hay huellas: ni la IP ni el correo', false === strpos( $filas, '192.0.2.10' ) && false === strpos( $filas, 'limite-temporal' ) );

	// --- 429 como el Laravel ---
	$r = ZN_Limite::demasiados( 120 );
	$ok( '429 {"message":"Too Many Attempts."} con Retry-After', 429 === $r->get_status() && array( 'message' => 'Too Many Attempts.' ) === $r->get_data() && '120' === ( $r->get_headers()['Retry-After'] ?? null ) );

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
	$_SERVER['HTTP_X_FORWARDED_FOR'] = 'esto no es una ip';
	$ok( 'X-Forwarded-For roto: se queda la del proxy', '10.1.2.3' === ZN_Limite::ip() );
	$_SERVER['REMOTE_ADDR']          = '2001:db8::1';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db9::5';
	$ok( 'también con IPv6 (rango 2001:db8::/32)', '2001:db9::5' === ZN_Limite::ip() );
	$_SERVER['REMOTE_ADDR'] = 'basura';
	$ok( 'REMOTE_ADDR que no es una IP: sin IP (no se cuenta por IP)', '' === ZN_Limite::ip() );
} finally {
	$_SERVER = $servidor;
	$wpdb->query( $wpdb->prepare( "DELETE FROM $tabla WHERE desde >= %s", $momento ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->delete( $tabla, array( 'clave' => hash( 'sha256', 'login|email|ventana-temporal@ejemplo.invalid' ) ) );
}
$ok( 'limpieza: la tabla queda como estaba', $antes === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tabla" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

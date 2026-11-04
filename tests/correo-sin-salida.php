<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Día de la subida (tanda C): sin ZN_CORREO_REAL no sale NINGÚN correo a una dirección de verdad, por ninguna de las
// rutas que mandan correos (alta, reenvío, código, contraseña cambiada, cuenta borrada) ni por wp_mail directo con
// copias. Las direcciones «de verdad» son de example.com (reservado, RFC 2606: no es de nadie). Los correos se recogen
// aquí y no salen (ni a Mailpit). Además, la regla del interruptor para producción, que en Local no se puede montar.
// Uso: wp eval-file tests/correo-sin-salida.php. Crea cuentas «…-temporal-sin-salida» y las borra al terminar.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$servidor = $_SERVER;
$cuentas  = array();
$claves   = array();
$ip       = '192.0.2.91';
// Lo que decide el plugin (prioridad 10) se apunta aquí (prioridad máxima) y nada sale: ni a Mailpit.
$correos = array();
add_filter(
	'pre_wp_mail',
	static function ( $resultado, $correo ) use ( &$correos ) {
		$correos[] = $correo + array( 'resultado' => $resultado );
		return null === $resultado ? true : $resultado;
	},
	PHP_INT_MAX,
	2
);
$llamar = static function ( string $metodo, string $ruta, array $datos = array(), int $quien = 0 ) use ( &$claves, $ip ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$email                  = is_string( $datos['email'] ?? null ) ? $datos['email'] : null;
	foreach ( array_keys( ZN_Limite::REGLAS ) as $accion ) {
		$claves = array_merge( $claves, array_keys( ZN_Limite::claves( $accion, $email ) ), array_keys( ZN_Limite::claves( $accion, null ) ) );
	}
	wp_set_current_user( $quien );
	$peticion = new WP_REST_Request( $metodo, '/' . ZN_NAMESPACE . $ruta );
	if ( 'GET' === $metodo ) {
		$peticion->set_query_params( $datos );
	} else {
		$peticion->set_header( 'Content-Type', 'application/json' );
		$peticion->set_body( wp_json_encode( $datos ) );
	}
	$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $peticion ) ), rest_get_server(), $peticion );
	wp_set_current_user( 0 );
	return array( $respuesta->get_status(), $respuesta->get_data() );
};
$real    = static fn( string $d ) => ! preg_match( '/@ejemplo\.invalid$/', $d );
$a_reales = static function () use ( &$correos, $real ) {
	return array_values( array_filter( $correos, static fn( $c ) => (bool) array_filter( ZN_Puertas::destinatarios( $c ), $real ) ) );
};
$sufijo = wp_rand( 1000, 9999 ) . '-temporal-sin-salida@example.com';

try {
	$ok( 'aquí ZN_CORREO_REAL no está definida', ! defined( 'ZN_CORREO_REAL' ) );

	// Alta, reenvío del enlace y código de recuperación de una dirección «de verdad».
	$email = "alta-$sufijo";
	list( $estado ) = $llamar( 'POST', '/register', array( 'name' => 'Sin salida', 'email' => $email, 'password' => wp_generate_password( 14 ) ) );
	$u = get_user_by( 'email', $email );
	if ( $u ) {
		$cuentas[] = $u->ID;
	}
	$ok( 'POST /register de example.com: 201 (la cuenta se crea igual)', 201 === $estado && $u );
	$llamar( 'GET', '/send-email', array( 'email' => $email ) );
	$llamar( 'POST', '/reset-password-code', array( 'email' => $email ) );

	// Contraseña cambiada y cuenta borrada, con sesión.
	$clave = wp_generate_password( 16 );
	$otra  = wp_insert_user( array( 'user_login' => 'sin-salida-' . wp_rand( 1000, 9999 ), 'user_email' => "datos-$sufijo", 'user_pass' => $clave, 'role' => 'zn_usuario', 'first_name' => 'Temporal' ) );
	$cuentas[] = $otra;
	update_user_meta( $otra, 'zn_verificado', '1' );
	$nueva = wp_generate_password( 16 );
	list( $estado ) = $llamar( 'PATCH', "/user/$otra", array( 'password' => $nueva, 'currentPassword' => $clave ), $otra );
	$ok( 'PATCH /user (contraseña nueva): 200', 200 === $estado );
	list( $estado ) = $llamar( 'DELETE', "/user/$otra", array( 'currentPassword' => $nueva ), $otra );
	$ok( 'DELETE /user: 200 (cuenta borrada)', 200 === $estado && false === get_userdata( $otra ) );

	// wp_mail directo: a una de verdad, mezclada con una inventada y en copia.
	wp_mail( "directo-$sufijo", 'Prueba', 'x' );
	wp_mail( array( 'x@ejemplo.invalid', "mezcla-$sufijo" ), 'Prueba', 'x' );
	wp_mail( 'x@ejemplo.invalid', 'Prueba', 'x', array( "Cc: Alguien <cc-$sufijo>" ) );
	wp_mail( 'x@ejemplo.invalid', 'Prueba', 'x', "From: a@ejemplo.invalid\r\nBcc: bcc-$sufijo" );
	wp_mail( 'x@ejemplo.invalid', 'Prueba inventada', 'x' );

	$reales = $a_reales();
	$ok( sprintf( 'las rutas y wp_mail intentaron mandar %d correos a direcciones de verdad (la prueba no está vacía)', count( $reales ) ), count( $reales ) >= 8 );
	$ok( 'NINGUNO salió: el plugin los paró todos (pre_wp_mail = false)', array() === array_filter( $reales, static fn( $c ) => false !== $c['resultado'] ) );
	$casos = array(
		'alta, reenvío y código (3)' => array( 'alta', '', 3 ),
		'contraseña cambiada'        => array( 'datos', 'contraseña ha cambiado', 1 ),
		'cuenta borrada (R111)'      => array( 'datos', 'cuenta se ha borrado', 1 ),
		'copia (Cc)'                 => array( 'cc', '', 1 ),
		'copia oculta (Bcc)'         => array( 'bcc', '', 1 ),
	);
	foreach ( $casos as $que => list( $prefijo, $asunto, $cuantos ) ) {
		$suyos = array_filter( $reales, static fn( $c ) => in_array( "$prefijo-$sufijo", ZN_Puertas::destinatarios( $c ), true ) && ( '' === $asunto || false !== strpos( (string) ( $c['subject'] ?? '' ), $asunto ) ) );
		$ok( "…entre ellos, $que", count( $suyos ) >= $cuantos );
	}
	$inventado = array_filter( $correos, static fn( $c ) => 'Prueba inventada' === ( $c['subject'] ?? '' ) );
	$ok( 'a una inventada (@ejemplo.invalid) sí saldría (el interruptor no lo para todo a ciegas)', $inventado && null === array_values( $inventado )[0]['resultado'] );

	// La regla del interruptor (R57-bis), también para producción: solo con la constante a true Y entorno production.
	$regla = array(
		'sin la constante, en producción: no'          => array( false, null, 'production', false ),
		'con la constante a false: no'                 => array( true, false, 'production', false ),
		'con la constante a «1» (texto): no'           => array( true, '1', 'production', false ),
		'con la constante a 1 (número): no'            => array( true, 1, 'production', false ),
		'con la constante a true en staging: no'       => array( true, true, 'staging', false ),
		'con la constante a true en local: no'         => array( true, true, 'local', false ),
		'con la constante a true en production: sí'    => array( true, true, 'production', true ),
	);
	foreach ( $regla as $nombre => list( $definida, $valor, $entorno, $esperado ) ) {
		$ok( "interruptor: $nombre", $esperado === ZN_Puertas::correo_real_activado( $definida, $valor, $entorno ) );
	}
} finally {
	$_SERVER = $servidor;
	wp_set_current_user( 0 );
	foreach ( array_keys( ZN_Limite::REGLAS ) as $accion ) {
		$_SERVER['REMOTE_ADDR'] = $ip;
		foreach ( array( "alta-$sufijo", "datos-$sufijo" ) as $direccion ) {
			$claves = array_merge( $claves, array_keys( ZN_Limite::claves( $accion, $direccion ) ) );
		}
		foreach ( $cuentas as $id ) {
			$u = get_userdata( $id );
			if ( $u ) {
				$claves = array_merge( $claves, array_keys( ZN_Limite::claves( $accion, $u->user_email ) ) );
			}
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
$sueltas = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email LIKE '%-temporal-sin-salida@example.com'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas temporales', count( $cuentas ) ), 0 === $sueltas );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

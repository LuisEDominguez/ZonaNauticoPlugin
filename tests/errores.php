<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Errores con el formato del Laravel por dentro (R12): los 401 y los 422 de campos obligatorios, con rutas
// de prueba que solo existen mientras dura esta comprobación. No escribe nada en la base de datos.
// Uso: wp eval-file tests/errores.php

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

// Rutas de prueba en nuestro espacio: una privada (como lo serán las escrituras) y una con un campo obligatorio.
add_action(
	'rest_api_init',
	static function () {
		register_rest_route( ZN_NAMESPACE, '/prueba-privada', array( 'methods' => 'POST', 'callback' => '__return_true', 'permission_callback' => '__return_false' ) );
		register_rest_route(
			ZN_NAMESPACE,
			'/prueba-obligatorio',
			array(
				'methods'             => 'POST',
				'callback'            => '__return_true',
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'    => array( 'type' => 'string', 'required' => true ),
					'password' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
		// Como un GET con ?context=edit sin permiso: WordPress da rest_forbidden_context, haya sesión o no.
		register_rest_route( ZN_NAMESPACE, '/prueba-contexto', array( 'methods' => 'GET', 'callback' => '__return_true', 'permission_callback' => static fn() => new WP_Error( 'rest_forbidden_context', 'Sorry, you are not allowed to edit this.', array( 'status' => rest_authorization_required_code() ) ) ) );
		register_rest_route( ZN_NAMESPACE, '/prueba-rota', array( 'methods' => 'GET', 'callback' => static fn() => new WP_Error( 'algo_interno', 'Detalle interno que no debe salir', array( 'status' => 500 ) ), 'permission_callback' => '__return_true' ) );
	}
);

$pedir = static function ( string $metodo, string $ruta, array $cuerpo = array() ) {
	$peticion = new WP_REST_Request( $metodo, '/' . ZN_NAMESPACE . $ruta );
	$peticion->set_body_params( $cuerpo );
	$servidor  = rest_get_server();
	$respuesta = rest_ensure_response( $servidor->dispatch( $peticion ) );
	// Lo mismo que hace WordPress al servir la petición de verdad.
	return apply_filters( 'rest_post_dispatch', $respuesta, $servidor, $peticion );
};

// --- 401 sin sesión: la app cierra la sesión al ver exactamente este mensaje ---
wp_set_current_user( 0 );
$r = $pedir( 'POST', '/prueba-privada' );
$ok( 'sin sesión: 401', 401 === $r->get_status() );
$ok( 'sin sesión: {"message":"Unauthenticated."} y nada más', array( 'message' => 'Unauthenticated.' ) === $r->get_data() );

// --- 401 con sesión pero sin permiso (el Laravel también da 401) ---
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0] );
$r = $pedir( 'POST', '/prueba-privada' );
$ok( 'sin permiso: 401', 401 === $r->get_status() );
$ok( 'sin permiso: mensaje distinto de Unauthenticated (la app no cierra la sesión)', array( 'message' => 'This action is unauthorized.' ) === $r->get_data() );
// R31: rest_forbidden_context con sesión válida no es «Unauthenticated.» (la app cerraría la sesión).
$r = $pedir( 'GET', '/prueba-contexto' );
$ok( 'rest_forbidden_context con sesión: «This action is unauthorized.»', 401 === $r->get_status() && array( 'message' => 'This action is unauthorized.' ) === $r->get_data() );
wp_set_current_user( 0 );
$r = $pedir( 'GET', '/prueba-contexto' );
$ok( 'rest_forbidden_context sin sesión: «Unauthenticated.»', 401 === $r->get_status() && array( 'message' => 'Unauthenticated.' ) === $r->get_data() );

// --- R34a: la ruta con otras mayúsculas también es nuestra ---
$peticion  = new WP_REST_Request( 'GET', '/ZonaNautico/V1/prueba-contexto' );
$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_get_server()->dispatch( $peticion ) ), rest_get_server(), $peticion );
$ok( '/ZonaNautico/V1/…: errores con el formato del Laravel', array( 'message' => 'Unauthenticated.' ) === $respuesta->get_data() );

// --- R33: contraseñas de aplicación apagadas ---
$ok( 'contraseñas de aplicación no disponibles', ! wp_is_application_passwords_available() );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
$ok( 'ni para el administrador', ! wp_is_application_passwords_available_for_user( $admin ) );

// --- 422 de campos obligatorios ---
$r = $pedir( 'POST', '/prueba-obligatorio' );
$d = $r->get_data();
$ok( 'faltan campos: 422', 422 === $r->get_status() );
$ok( 'faltan campos: errores por campo', array( 'email' => array( 'The email field is required.' ), 'password' => array( 'The password field is required.' ) ) === $d['errors'] );
$ok( 'faltan campos: mensaje del primero con «(and 1 more error)»', 'The email field is required. (and 1 more error)' === $d['message'] );

$r = $pedir( 'POST', '/prueba-obligatorio', array( 'email' => 'a@b.es', 'password' => 'x' ) );
$ok( 'con los campos: pasa', 200 === $r->get_status() );

// --- 500 sin detalles internos ---
$r = $pedir( 'GET', '/prueba-rota' );
$ok( '500: solo {"message":"Server Error"}', 500 === $r->get_status() && array( 'message' => 'Server Error' ) === $r->get_data() );

// --- Piezas sueltas ---
$r = ZN_Errores::no_valido( array( 'name' => 'Uno.', 'email' => array( 'Dos.', 'Tres.' ) ) );
$ok( 'no_valido: «(and 2 more errors)» con tres errores', 'Uno. (and 2 more errors)' === $r->get_data()['message'] );
$ok( 'no_valido: cada campo es una lista', array( 'name' => array( 'Uno.' ), 'email' => array( 'Dos.', 'Tres.' ) ) === $r->get_data()['errors'] );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

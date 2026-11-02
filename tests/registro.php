<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Registro, verificación del correo y recuperación de la contraseña (R30, R53, R62; H16, H17, H24).
// Uso: wp eval-file tests/registro.php
// Crea cuentas temporales (correos «…-temporal-registro@ejemplo.invalid»), llama a las rutas por dentro (con IPs de
// documentación) y un par de veces por HTTP de verdad. Los correos se recogen aquí y no se envían. Al terminar
// borra las cuentas, sus tokens y solo las filas de intentos que ha usado.

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
$usados   = array(); // [acción, email] usados, para borrar sus filas de intentos.
$n        = 0;
$correo_nuevo = static function () use ( &$n ) {
	return 'c' . ( ++$n ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-registro@ejemplo.invalid';
};
// Correos que se mandarían: se apuntan y no salen (ni a Mailpit).
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
$ultimo_a = static function ( string $email ) use ( &$correos ) {
	foreach ( array_reverse( $correos ) as $correo ) {
		if ( in_array( strtolower( $email ), ZN_Puertas::destinatarios( $correo ), true ) ) {
			return $correo;
		}
	}
	return null;
};
// Llamada por dentro desde una IP de documentación. GET: los datos van en la dirección; POST: en el cuerpo JSON.
$llamar = static function ( string $metodo, string $ruta, array $datos = array(), string $ip = '192.0.2.70' ) use ( &$claves ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$email                  = is_string( $datos['email'] ?? null ) ? $datos['email'] : null;
	foreach ( array_keys( ZN_Limite::REGLAS ) as $accion ) {
		$claves = array_merge( $claves, array_keys( ZN_Limite::claves( $accion, $email ) ) );
	}
	$peticion = new WP_REST_Request( $metodo, '/' . ZN_NAMESPACE . $ruta );
	if ( 'GET' === $metodo ) {
		$peticion->set_query_params( $datos );
	} else {
		$peticion->set_header( 'Content-Type', 'application/json' );
		$peticion->set_body( wp_json_encode( $datos ) );
	}
	// Como al servir por HTTP: también pasa por rest_post_dispatch (donde ZN_Errores traduce los errores).
	$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $peticion ) ), rest_get_server(), $peticion );
	return array( $respuesta->get_status(), $respuesta->get_data(), $respuesta->get_headers() );
};
$http = static function ( string $url, ?array $formulario = null ) {
	for ( $intento = 0; $intento < 3; $intento++ ) {
		usleep( 400000 );
		$respuesta = null === $formulario ? wp_remote_get( $url, array( 'timeout' => 60 ) ) : wp_remote_post( $url, array( 'timeout' => 60, 'body' => $formulario ) );
		if ( ! is_wp_error( $respuesta ) && 502 !== wp_remote_retrieve_response_code( $respuesta ) ) {
			break;
		}
		sleep( 15 );
	}
	return is_wp_error( $respuesta ) ? array( 0, '', '' ) : array( wp_remote_retrieve_response_code( $respuesta ), wp_remote_retrieve_body( $respuesta ), (string) wp_remote_retrieve_header( $respuesta, 'content-type' ) );
};
$registrar = static function ( array $datos, string $ip = '192.0.2.70' ) use ( $llamar, &$cuentas ) {
	$r = $llamar( 'POST', '/register', $datos, $ip );
	$u = is_string( $datos['email'] ?? null ) ? get_user_by( 'email', trim( $datos['email'] ) ) : false;
	if ( $u && ! in_array( $u->ID, $cuentas, true ) ) {
		$cuentas[] = $u->ID;
	}
	return $r;
};
// Enlace de verificación del último correo a esa dirección: [id, token].
$enlace = static function ( string $email ) use ( $ultimo_a ) {
	$correo = $ultimo_a( $email );
	if ( ! $correo || ! preg_match( '#(https?://\S+/verify\?\S+)#', (string) $correo['message'], $m ) ) {
		return array( 0, '', '' );
	}
	parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $q );
	return array( (int) ( $q['id'] ?? 0 ), (string) ( $q['token'] ?? '' ), html_entity_decode( $m[1] ) );
};
$codigo_de = static function ( string $email ) use ( $ultimo_a ) {
	$correo = $ultimo_a( $email );
	return $correo && preg_match( '/\b(\d{6})\b/', (string) $correo['message'], $m ) ? $m[1] : '';
};
$exito = array( 'status' => 'success' );

try {
	// --- Las rutas ---
	$rutas = rest_get_server()->get_routes( ZN_NAMESPACE );
	foreach ( array( 'register' => 'POST', 'send-email' => 'GET', 'verify' => 'POST', 'reset-password-code' => 'POST', 'reset-password' => 'POST' ) as $ruta => $metodo ) {
		$metodos = array_keys( $rutas[ '/' . ZN_NAMESPACE . "/$ruta" ][0]['methods'] ?? array() );
		$ok( "existe $metodo /$ruta", in_array( $metodo, $metodos, true ) );
	}

	// --- POST /register ---
	$email                   = $correo_nuevo();
	$clave                   = wp_generate_password( 16 );
	$correos                 = array();
	list( $estado, $cuerpo ) = $registrar( array( 'name' => '  Ana María López  ', 'email' => $email, 'password' => $clave, 'phone' => '+34 600 000 000', 'role' => 'administrator', 'roleId' => 1, 'latitude' => 42.2, 'longitude' => -8.7 ) );
	$ok( 'registro: 201 {status: «success»}', 201 === $estado && $exito === $cuerpo );
	$cuenta = get_user_by( 'email', $email );
	$ok( 'registro: la cuenta existe con el rol «user» y ninguno más (el rol que mande la app se ignora)', $cuenta && array( 'zn_usuario' ) === array_values( $cuenta->roles ) );
	$ok( 'R53: el nombre entero va en first_name y los apellidos vacíos', $cuenta && 'Ana María López' === $cuenta->first_name && '' === $cuenta->last_name );
	$ok( 'registro: guarda el teléfono', $cuenta && '+34 600 000 000' === get_user_meta( $cuenta->ID, 'phone', true ) );
	$ok( 'R91: no guarda la ubicación (está sin decidir, RGPD)', $cuenta && '' === get_user_meta( $cuenta->ID, 'latitude', true ) && '' === get_user_meta( $cuenta->ID, 'longitude', true ) );
	$ok( 'registro: la contraseña buena vale', $cuenta && wp_check_password( $clave, $cuenta->user_pass, $cuenta->ID ) );
	$ok( 'registro: el nombre de acceso no lleva ni el correo ni el nombre', $cuenta && false === stripos( $cuenta->user_login, 'temporal' ) && false === stripos( $cuenta->user_login, 'ana' ) );
	$ok( 'registro: sin verificar', $cuenta && '1' !== get_user_meta( $cuenta->ID, 'zn_verificado', true ) );
	$ok( 'registro: un solo correo, a esa dirección', 1 === count( $correos ) && array( $email ) === ZN_Puertas::destinatarios( $correos[0] ) );
	list( $id_enlace, $token, $url ) = $enlace( $email );
	$ok( 'R62: el correo lleva el enlace /verify?id=…&token=… de esa cuenta', $cuenta && $cuenta->ID === $id_enlace && 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) && 0 === strpos( $url, rest_url( ZN_NAMESPACE . '/verify' ) ) );
	$ok( 'R62/H16: el token no es sha1 del correo y no se guarda en claro (solo su huella)', $cuenta && base64_encode( sha1( $email ) ) !== $token && false === strpos( wp_json_encode( get_user_meta( $cuenta->ID ) ), $token ) );
	list( $estado ) = $llamar( 'POST', '/login', array( 'email' => $email, 'password' => $clave ) );
	$usados[]       = array( 'login', $email );
	$ok( 'sin verificar no se entra (401)', 401 === $estado );

	// Datos que no valen.
	list( $estado, $cuerpo ) = $registrar( array( 'name' => 'Otra', 'email' => strtoupper( $email ), 'password' => wp_generate_password( 12 ) ) );
	$ok( 'R30: email repetido (con mayúsculas): 422 «The email has already been taken.»', 422 === $estado && array( 'The email has already been taken.' ) === ( $cuerpo['errors']['email'] ?? null ) );
	list( $estado, $cuerpo ) = $registrar( array( 'email' => $correo_nuevo(), 'password' => 'corta' ) );
	$ok( '422 sin nombre y con la contraseña corta, campo por campo', 422 === $estado && isset( $cuerpo['errors']['name'], $cuerpo['errors']['password'] ) && array( 'The password field must be at least 8 characters.' ) === $cuerpo['errors']['password'] );
	list( $estado, $cuerpo ) = $registrar( array( 'name' => '    ', 'email' => $correo_nuevo(), 'password' => wp_generate_password( 12 ), 'phone' => '12' ) );
	$ok( 'R53: nombre en blanco y teléfono que no vale: 422', 422 === $estado && isset( $cuerpo['errors']['name'], $cuerpo['errors']['phone'] ) );
	list( $estado, $cuerpo ) = $registrar( array( 'name' => 'X', 'email' => 'no-es-un-correo', 'password' => wp_generate_password( 12 ) ) );
	$ok( '422 con el email mal escrito', 422 === $estado && isset( $cuerpo['errors']['email'] ) );

	// R30: red de seguridad: un error de wp_insert_user sin estado no puede salir como 500.
	rest_get_server()->register_route( ZN_NAMESPACE, '/' . ZN_NAMESPACE . '/prueba-registro', array( array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => static fn() => new WP_Error( 'existing_user_email', 'Sorry, that email address is already used!' ) ) ) );
	rest_get_server()->register_route( ZN_NAMESPACE, '/' . ZN_NAMESPACE . '/prueba-registro-2', array( array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => static fn() => new WP_Error( 'existing_user_login', 'Sorry, that username already exists!' ) ) ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/prueba-registro' );
	$ok( 'R30: existing_user_email de WordPress → 422 «has already been taken» (la app lo reconoce)', 422 === $estado && array( 'The email has already been taken.' ) === ( $cuerpo['errors']['email'] ?? null ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/prueba-registro-2' );
	$ok( 'R30: otros errores de wp_insert_user → 422, no 500', 422 === $estado && isset( $cuerpo['errors'] ) );

	// Límite de altas por IP (10 por hora).
	list( $max_registro ) = ZN_Limite::REGLAS['registro']['ip'];
	for ( $i = 0; $i < $max_registro; $i++ ) {
		$registrar( array(), '192.0.2.71' );
	}
	list( $estado, $cuerpo ) = $registrar( array( 'name' => 'Muchas', 'email' => $correo_nuevo(), 'password' => wp_generate_password( 12 ) ), '192.0.2.71' );
	$ok( "registro: pasadas $max_registro altas por hora desde una IP, 429", 429 === $estado && array( 'message' => 'Too Many Attempts.' ) === $cuerpo );

	// --- /verify (R62, R88): abrir el enlace (GET) no verifica; lo hace el botón (POST) ---
	list( $estado, $cuerpo ) = $llamar( 'GET', '/verify', array( 'id' => $id_enlace, 'token' => $token, 'format' => 'json' ) );
	$ok( 'R88: abrir el enlace (GET) con un token bueno: 200 {status: «pending»}', 200 === $estado && array( 'status' => 'pending' ) === $cuerpo );
	$ok( 'R88: …y la cuenta NO queda verificada (un escáner de enlaces no la verifica)', '1' !== get_user_meta( $cuenta->ID, 'zn_verificado', true ) );
	list( $estado ) = $llamar( 'GET', '/verify', array( 'id' => $id_enlace, 'token' => strrev( $token ), 'format' => 'json' ) );
	$ok( 'R88: abrir el enlace con un token que no es: 400', 400 === $estado );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/verify', array( 'id' => $id_enlace, 'token' => strrev( $token ), 'format' => 'json' ) );
	$ok( 'verify con un token que no es: 400', 400 === $estado && 'error' === ( $cuerpo['status'] ?? null ) );
	list( $otro_id ) = array( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0] );
	list( $estado ) = $llamar( 'POST', '/verify', array( 'id' => $otro_id, 'token' => $token, 'format' => 'json' ) );
	$ok( 'verify con el token de otra cuenta: 400', 400 === $estado );
	list( $estado ) = $llamar( 'POST', '/verify', array( 'format' => 'json' ) );
	$ok( 'verify sin datos: 400', 400 === $estado );
	$ok( '(sin verificar todavía)', '1' !== get_user_meta( $cuenta->ID, 'zn_verificado', true ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/verify', array( 'id' => $id_enlace, 'token' => $token, 'format' => 'json' ) );
	$ok( 'verify bueno (POST, format=json): 200 {status: «success»}', 200 === $estado && $exito === $cuerpo );
	$ok( 'verify: la cuenta queda verificada', '1' === get_user_meta( $cuenta->ID, 'zn_verificado', true ) );
	list( $estado ) = $llamar( 'POST', '/verify', array( 'id' => $id_enlace, 'token' => $token, 'format' => 'json' ) );
	$ok( 'verify: el enlace solo vale una vez', 400 === $estado );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/login', array( 'email' => $email, 'password' => $clave ) );
	$ok( 'ya verificada, el login entra', 200 === $estado && $cuenta->ID === ( $cuerpo['id'] ?? null ) );

	// La página (por HTTP de verdad, como desde el correo).
	$email_pagina = $correo_nuevo();
	$registrar( array( 'name' => 'Página', 'email' => $email_pagina, 'password' => wp_generate_password( 12 ) ) );
	list( , , $url_pagina ) = $enlace( $email_pagina );
	$_SERVER                = $servidor;
	$id_pagina = get_user_by( 'email', $email_pagina )->ID;
	list( $estado, $html, $tipo ) = $http( $url_pagina );
	$ok( 'HTTP: el enlace del correo abre una página (text/html) con el botón «Confirmar mi correo» (POST)', 200 === $estado && 0 === strpos( $tipo, 'text/html' ) && false !== strpos( $html, 'Confirmar mi correo' ) && 1 === preg_match( '/<form[^>]+method="post"/i', $html ) );
	$ok( 'HTTP: la página no es JSON ni deja ver datos de la cuenta', null === json_decode( $html ) && false === stripos( $html, $email_pagina ) );
	$ok( 'R88 HTTP: abrirlo otra vez también pinta el botón…', 200 === $http( $url_pagina )[0] );
	wp_cache_delete( $id_pagina, 'user_meta' ); // Lo cambia otra petición (el sitio), no este proceso.
	$ok( 'R88 HTTP: …y abrir el enlace no verifica', '1' !== get_user_meta( $id_pagina, 'zn_verificado', true ) );
	preg_match( '/<form[^>]+action="([^"]+)"/i', $html, $accion );
	preg_match_all( '/<input[^>]+name="(id|token)"[^>]+value="([^"]*)"/i', $html, $campos, PREG_SET_ORDER );
	$formulario = array();
	foreach ( $campos as $campo ) {
		$formulario[ $campo[1] ] = html_entity_decode( $campo[2] );
	}
	list( $estado, $html, $tipo ) = $http( html_entity_decode( $accion[1] ?? '' ), $formulario );
	$ok( 'HTTP: al pulsar el botón (POST del formulario), página «Cuenta verificada»', 200 === $estado && 0 === strpos( $tipo, 'text/html' ) && false !== stripos( $html, 'verificada' ) );
	wp_cache_delete( $id_pagina, 'user_meta' );
	$ok( 'HTTP: verificada', '1' === get_user_meta( $id_pagina, 'zn_verificado', true ) );
	list( $estado, $html, $tipo ) = $http( html_entity_decode( $accion[1] ?? '' ), $formulario );
	$ok( 'HTTP: pulsado otra vez, 400 y página que dice que el enlace no vale', 400 === $estado && 0 === strpos( $tipo, 'text/html' ) && false !== stripos( $html, 'no vale' ) );

	// Enlace caducado.
	$email_viejo = $correo_nuevo();
	$registrar( array( 'name' => 'Vieja', 'email' => $email_viejo, 'password' => wp_generate_password( 12 ) ) );
	list( $id_viejo, $token_viejo ) = $enlace( $email_viejo );
	update_user_meta( $id_viejo, 'zn_verificar_caduca', time() - 60 );
	list( $estado ) = $llamar( 'POST', '/verify', array( 'id' => $id_viejo, 'token' => $token_viejo, 'format' => 'json' ) );
	$ok( 'R62: un enlace caducado no vale (400)', 400 === $estado && '1' !== get_user_meta( $id_viejo, 'zn_verificado', true ) );

	// --- GET /send-email (H17) ---
	$correos                 = array();
	list( $estado, $cuerpo ) = $llamar( 'GET', '/send-email', array( 'email' => $email_viejo ) );
	$ok( 'reenviar a una cuenta sin verificar: 200 y un correo nuevo', 200 === $estado && $exito === $cuerpo && 1 === count( $correos ) );
	list( , $token_nuevo ) = $enlace( $email_viejo );
	$ok( 'reenviar: el enlace nuevo vale y el viejo ya no', '' !== $token_nuevo && $token_nuevo !== $token_viejo && ZN_API_Cuenta::token_de_verificacion_vale( $id_viejo, $token_nuevo ) && ! ZN_API_Cuenta::token_de_verificacion_vale( $id_viejo, $token_viejo ) );
	$wp_admin_email = get_userdata( $otro_id )->user_email;
	foreach ( array( 'que no existe' => 'nadie-temporal-registro@ejemplo.invalid', 'ya verificada' => $email, 'de wp-admin' => $wp_admin_email ) as $nombre => $direccion ) {
		$correos                 = array();
		list( $estado, $cuerpo ) = $llamar( 'GET', '/send-email', array( 'email' => $direccion ) );
		$usados[]                = array( 'reenviar', $direccion );
		$ok( "H17: reenviar a una cuenta $nombre: la misma respuesta (200) y ningún correo", 200 === $estado && $exito === $cuerpo && array() === $correos );
	}
	list( $estado ) = $llamar( 'GET', '/send-email', array( 'email' => 'no-es-un-correo' ) );
	$ok( 'reenviar con el email mal escrito: 422', 422 === $estado );
	$email_reenvios = $correo_nuevo();
	$registrar( array( 'name' => 'Reenvíos', 'email' => $email_reenvios, 'password' => wp_generate_password( 12 ) ), '192.0.2.72' );
	list( $max_reenviar ) = ZN_Limite::REGLAS['reenviar']['cuenta_ip'];
	for ( $i = 0; $i < $max_reenviar; $i++ ) {
		$llamar( 'GET', '/send-email', array( 'email' => $email_reenvios ), '192.0.2.72' );
	}
	$correos                 = array();
	list( $estado, $cuerpo ) = $llamar( 'GET', '/send-email', array( 'email' => $email_reenvios ), '192.0.2.72' );
	$ok( "H17: pasados $max_reenviar reenvíos por hora, 429 y ningún correo", 429 === $estado && array( 'message' => 'Too Many Attempts.' ) === $cuerpo && array() === $correos );

	// --- POST /reset-password-code (H24) ---
	$correos                 = array();
	list( $estado, $cuerpo ) = $llamar( 'POST', '/reset-password-code', array( 'email' => $email ) );
	$codigo                  = $codigo_de( $email );
	$ok( 'código: 200 {status: «success»}, sin el código en la respuesta y sin Bearer compartido', 200 === $estado && $exito === $cuerpo );
	$ok( 'código: llega por correo, de 6 cifras', 1 === count( $correos ) && 1 === preg_match( '/^\d{6}$/', $codigo ) );
	$ok( 'código: no se guarda en claro', false === strpos( wp_json_encode( get_user_meta( $cuenta->ID ) ), '"' . $codigo . '"' ) );
	foreach ( array( 'que no existe' => 'nadie-temporal-registro@ejemplo.invalid', 'de wp-admin' => $wp_admin_email ) as $nombre => $direccion ) {
		$correos                 = array();
		list( $estado, $cuerpo ) = $llamar( 'POST', '/reset-password-code', array( 'email' => $direccion ) );
		$usados[]                = array( 'recuperar', $direccion );
		$ok( "H24: código para una cuenta $nombre: la misma respuesta (200) y ningún correo", 200 === $estado && $exito === $cuerpo && array() === $correos );
	}
	list( $estado ) = $llamar( 'POST', '/reset-password-code', array( 'email' => 'no-es-un-correo' ) );
	$ok( 'código con el email mal escrito: 422', 422 === $estado );
	$email_codigos = $correo_nuevo();
	$registrar( array( 'name' => 'Códigos', 'email' => $email_codigos, 'password' => wp_generate_password( 12 ) ), '192.0.2.73' );
	list( $max_recuperar ) = ZN_Limite::REGLAS['recuperar']['cuenta_ip'];
	for ( $i = 0; $i < $max_recuperar; $i++ ) {
		$llamar( 'POST', '/reset-password-code', array( 'email' => $email_codigos ), '192.0.2.73' );
	}
	$correos         = array();
	list( $estado ) = $llamar( 'POST', '/reset-password-code', array( 'email' => $email_codigos ), '192.0.2.73' );
	$ok( "H24: pasados $max_recuperar códigos por hora, 429 y ningún correo", 429 === $estado && array() === $correos );

	// --- POST /reset-password ---
	$token_sesion = ZN_Tokens::emitir( $cuenta->ID );
	$nueva        = wp_generate_password( 14 );
	$mal          = '000000' === $codigo ? '111111' : '000000';
	list( $estado, $cuerpo ) = $llamar( 'POST', '/reset-password', array( 'email' => $email, 'code' => $mal, 'password' => $nueva ) );
	$ok( 'R30: código incorrecto: 400 (no 422) {status: «error», message: «Invalid code»}', 400 === $estado && array( 'status' => 'error', 'message' => 'Invalid code' ) === $cuerpo );
	list( $estado ) = $llamar( 'POST', '/reset-password', array( 'email' => $email, 'code' => '12ab', 'password' => $nueva ) );
	$ok( 'R30: código con otro formato: 400, no 422', 400 === $estado );
	list( $estado ) = $llamar( 'POST', '/reset-password', array( 'email' => $email, 'password' => $nueva ) );
	$ok( 'R30: sin código: 400', 400 === $estado );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/reset-password', array( 'email' => $email, 'code' => $codigo, 'password' => 'corta' ) );
	$ok( 'contraseña nueva corta: 422 en password (y el código no se gasta)', 422 === $estado && isset( $cuerpo['errors']['password'] ) );
	list( $estado ) = $llamar( 'POST', '/reset-password', array( 'email' => 'nadie-temporal-registro@ejemplo.invalid', 'code' => $codigo, 'password' => $nueva ) );
	$usados[]       = array( 'codigo', 'nadie-temporal-registro@ejemplo.invalid' );
	$ok( 'una cuenta que no existe: el mismo 400', 400 === $estado );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/reset-password', array( 'email' => ' ' . strtoupper( $email ), 'code' => $codigo, 'password' => $nueva ) );
	$ok( 'código bueno: 200 {status: «success»}', 200 === $estado && $exito === $cuerpo );
	clean_user_cache( $cuenta->ID );
	$ok( 'la contraseña ha cambiado', wp_check_password( $nueva, get_userdata( $cuenta->ID )->user_pass, $cuenta->ID ) && ! wp_check_password( $clave, get_userdata( $cuenta->ID )->user_pass, $cuenta->ID ) );
	$ok( 'R32: las sesiones abiertas de la cuenta se cierran', 0 === ZN_Tokens::validar( $token_sesion ) );
	list( $estado ) = $llamar( 'POST', '/reset-password', array( 'email' => $email, 'code' => $codigo, 'password' => wp_generate_password( 14 ) ) );
	$ok( 'el código solo vale una vez', 400 === $estado );
	list( $estado ) = $llamar( 'POST', '/login', array( 'email' => $email, 'password' => $nueva ) );
	$ok( 'se entra con la contraseña nueva', 200 === $estado );

	// 5 intentos por código y 15 minutos (H24).
	$email_fallos = $correo_nuevo();
	$registrar( array( 'name' => 'Fallos', 'email' => $email_fallos, 'password' => wp_generate_password( 12 ) ), '192.0.2.74' );
	$llamar( 'POST', '/reset-password-code', array( 'email' => $email_fallos ), '192.0.2.74' );
	$bueno = $codigo_de( $email_fallos );
	$malo  = '000000' === $bueno ? '111111' : '000000';
	for ( $i = 0; $i < 5; $i++ ) {
		$llamar( 'POST', '/reset-password', array( 'email' => $email_fallos, 'code' => $malo, 'password' => wp_generate_password( 14 ) ), '192.0.2.' . ( 80 + $i ) );
	}
	list( $estado ) = $llamar( 'POST', '/reset-password', array( 'email' => $email_fallos, 'code' => $bueno, 'password' => wp_generate_password( 14 ) ), '192.0.2.90' );
	$ok( 'H24: tras 5 códigos malos (aunque sea desde IPs distintas), el bueno ya no vale', 400 === $estado );
	$ok( 'H24: …y la verificación del correo de esa cuenta no cambia', '1' !== get_user_meta( get_user_by( 'email', $email_fallos )->ID, 'zn_verificado', true ) );
	$llamar( 'POST', '/reset-password-code', array( 'email' => $email_fallos ), '192.0.2.74' );
	$bueno = $codigo_de( $email_fallos );
	update_user_meta( get_user_by( 'email', $email_fallos )->ID, 'zn_codigo_caduca', time() - 60 );
	list( $estado ) = $llamar( 'POST', '/reset-password', array( 'email' => $email_fallos, 'code' => $bueno, 'password' => wp_generate_password( 14 ) ), '192.0.2.74' );
	$ok( 'H24: un código de hace más de 15 minutos no vale', 400 === $estado );
	$llamar( 'POST', '/reset-password-code', array( 'email' => $email_fallos ), '192.0.2.74' );
	$bueno                   = $codigo_de( $email_fallos );
	list( $estado )          = $llamar( 'POST', '/reset-password', array( 'email' => $email_fallos, 'code' => $bueno, 'password' => wp_generate_password( 14 ) ), '192.0.2.74' );
	$ok( 'con un código nuevo, sí', 200 === $estado );
	$ok( 'cambiar la contraseña con el código del correo deja verificado el correo', '1' === get_user_meta( get_user_by( 'email', $email_fallos )->ID, 'zn_verificado', true ) );

	// Límite de intentos con código: antes de mirarlo.
	$email_limite = $correo_nuevo();
	$registrar( array( 'name' => 'Límite', 'email' => $email_limite, 'password' => wp_generate_password( 12 ) ), '192.0.2.75' );
	$ok( 'R89: con código, como mucho 10 intentos por hora por cuenta', array( 10, HOUR_IN_SECONDS ) === ZN_Limite::REGLAS['codigo']['cuenta'] );
	list( $max_codigo ) = ZN_Limite::REGLAS['codigo']['cuenta_ip'];
	for ( $i = 0; $i < $max_codigo; $i++ ) {
		$llamar( 'POST', '/reset-password', array( 'email' => $email_limite, 'code' => '12345', 'password' => wp_generate_password( 14 ) ), '192.0.2.76' );
	}
	list( $estado, $cuerpo ) = $llamar( 'POST', '/reset-password', array( 'email' => $email_limite, 'code' => '12345', 'password' => wp_generate_password( 14 ) ), '192.0.2.76' );
	$ok( "pasados $max_codigo intentos con código desde una IP, 429", 429 === $estado && array( 'message' => 'Too Many Attempts.' ) === $cuerpo );

	// Ningún correo a una dirección de verdad (el interruptor de R57 sigue en medio).
	$ok( 'todos los correos de la prueba iban a @ejemplo.invalid', array() === array_filter( $correos, static fn( $c ) => (bool) array_filter( ZN_Puertas::destinatarios( $c ), static fn( $d ) => ! preg_match( '/@ejemplo\.invalid$/', $d ) ) ) );
} finally {
	$_SERVER = $servidor;
	// Filas de intentos de las cuentas (antes de borrarlas: la clave de una cuenta que existe va por su número).
	foreach ( array_keys( ZN_Limite::REGLAS ) as $accion ) {
		foreach ( array_merge( array( '127.0.0.1', '::1' ), array_map( static fn( $i ) => "192.0.2.$i", range( 70, 90 ) ) ) as $ip ) {
			$_SERVER['REMOTE_ADDR'] = $ip;
			foreach ( $cuentas as $id ) {
				$u = get_userdata( $id );
				if ( $u ) {
					$claves = array_merge( $claves, array_keys( ZN_Limite::claves( $accion, $u->user_email ) ) );
				}
			}
			foreach ( $usados as list( , $direccion ) ) {
				$claves = array_merge( $claves, array_keys( ZN_Limite::claves( $accion, $direccion ) ) );
			}
			$claves = array_merge( $claves, array_keys( ZN_Limite::claves( $accion, null ) ) );
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
$quedan   = array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) );
$sueltas  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email LIKE '%-temporal-registro@ejemplo.invalid'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$intentos = $claves ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', array_unique( $claves ) ) ) . "')" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas temporales, sus tokens y sus filas de intentos', count( $cuentas ) ), ! $quedan && 0 === $sueltas && 0 === $intentos );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

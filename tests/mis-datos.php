<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// «Cambiar mis datos» desde la app (R69): PATCH /user/{id} con el nombre, el teléfono, el correo o la contraseña;
// el correo y la contraseña piden la contraseña actual (currentPassword). El correo nuevo no vale hasta confirmarlo
// con el enlace que le llega (GET /verify-email pinta el botón; POST confirma).
// Uso: wp eval-file tests/mis-datos.php. Crea cuentas «…-temporal-misdatos@ejemplo.invalid», llama a las rutas
// por dentro (con IPs de documentación) y unas pocas veces por HTTP con tokens de verdad. Los correos se recogen
// aquí y no se envían. Al terminar borra las cuentas, sus tokens y solo las filas de intentos que ha usado.

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
$usados   = array(); // Correos nuevos pedidos, para borrar sus filas de intentos.
$clave    = 'Temporal-misdatos-1';
$cuenta   = static function ( string $rol_wp = 'zn_usuario', string $nombre = 'Temporal' ) use ( &$cuentas, $clave ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-misdatos';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => $clave, 'role' => $rol_wp, 'first_name' => $nombre ) );
	update_user_meta( $id, 'zn_verificado', '1' );
	$cuentas[] = $id;
	return $id;
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
$enlace = static function ( ?array $correo ): array {
	preg_match( '/[?&]id=(\d+)&(?:amp;)?token=([A-Za-z0-9_-]{43})/', (string) ( $correo['message'] ?? '' ), $m );
	return $m ? array( 'id' => (int) $m[1], 'token' => $m[2] ) : array();
};
// Llamada por dentro como la cuenta $quien (0 = sin sesión) desde una IP de documentación, pasando por
// rest_post_dispatch como al servir. GET: los datos en la dirección; los demás: en el cuerpo JSON.
$llamar = static function ( string $metodo, string $ruta, array $datos = array(), int $quien = 0, string $ip = '192.0.2.80' ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
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
	return array( $respuesta->get_status(), $respuesta->get_data(), $respuesta->get_headers() );
};
$patch = static fn( int $id, array $datos, int $quien = -1, string $ip = '192.0.2.80' ) => $llamar( 'PATCH', "/user/$id", $datos, -1 === $quien ? $id : $quien, $ip );
$base  = rest_url( ZN_NAMESPACE );
// El Local de Windows reinicia su PHP tras muchas peticiones seguidas (502 unos segundos): se espera y se repite.
$http = static function ( string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null, bool $formulario = false ) use ( $base ) {
	$cabeceras = array( 'Accept' => 'application/json' );
	if ( ! $formulario ) {
		$cabeceras['Content-Type'] = 'application/json';
	}
	if ( $token ) {
		$cabeceras['Authorization'] = "Bearer $token";
	}
	for ( $intento = 0; $intento < 3; $intento++ ) {
		usleep( 400000 );
		$respuesta = wp_remote_request( 0 === strpos( $ruta, 'http' ) ? $ruta : $base . $ruta, array( 'method' => $metodo, 'timeout' => 60, 'headers' => $cabeceras, 'body' => null === $cuerpo ? null : ( $formulario ? $cuerpo : wp_json_encode( $cuerpo ) ) ) );
		if ( ! is_wp_error( $respuesta ) && 502 !== wp_remote_retrieve_response_code( $respuesta ) ) {
			break;
		}
		sleep( 15 );
	}
	return is_wp_error( $respuesta )
		? array( 0, null, '', '' )
		: array( wp_remote_retrieve_response_code( $respuesta ), json_decode( wp_remote_retrieve_body( $respuesta ), true ), wp_remote_retrieve_body( $respuesta ), (string) wp_remote_retrieve_header( $respuesta, 'content-type' ) );
};
$no_autenticado = array( 'message' => 'Unauthenticated.' );
$sin_permiso    = array( 'message' => 'This action is unauthorized.' );
$incorrecta     = array( 'The password is incorrect.' );
$requerida      = array( 'The current password field is required.' );

try {
	// --- La ruta ---
	$metodos = array();
	foreach ( rest_get_server()->get_routes( ZN_NAMESPACE )[ '/' . ZN_NAMESPACE . '/user/(?P<id>\d+)' ] ?? array() as $manejador ) {
		$metodos = array_merge( $metodos, array_keys( array_filter( (array) $manejador['methods'] ) ) );
	}
	$ok( 'existen PATCH y PUT /user/{id} (como el Laravel), y sigue GET', in_array( 'PATCH', $metodos, true ) && in_array( 'PUT', $metodos, true ) && in_array( 'GET', $metodos, true ) );
	$verificar = rest_get_server()->get_routes( ZN_NAMESPACE )[ '/' . ZN_NAMESPACE . '/verify-email' ] ?? array();
	$ok( 'existe /verify-email (GET y POST)', (bool) $verificar );

	// --- Quién puede ---
	$ana   = $cuenta( 'zn_usuario', 'Ana' );
	$admin = $cuenta( 'zn_administrador', 'Admin' );
	list( $estado, $cuerpo ) = $patch( $ana, array( 'name' => 'Ana (prueba)' ), 0 );
	$ok( 'sin sesión: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	list( $estado, $cuerpo ) = $patch( $ana, array( 'name' => 'Cambiado por otro (prueba)' ), $admin );
	$ok( 'con la sesión de otra cuenta (aunque sea la administración): 401 «This action is unauthorized.»', 401 === $estado && $sin_permiso === $cuerpo );
	$ok( '…y el nombre no cambia', 'Ana' === get_user_meta( $ana, 'first_name', true ) );
	list( $estado ) = $patch( 999999999, array( 'name' => 'Nadie (prueba)' ), $ana );
	$ok( 'cuenta que no existe: 404', 404 === $estado );
	list( $estado, $cuerpo ) = $patch( $ana, array( 'roleId' => 1 ) );
	$ok( 'roleId (cambiar el rol): 401, no se cambia desde aquí', 401 === $estado && $sin_permiso === $cuerpo && 'user' === ZN_Roles::rol_app( get_userdata( $ana ) ) );

	// --- Nombre y teléfono: sin la contraseña actual ---
	list( $estado, $cuerpo, $cabeceras ) = $patch( $ana, array( 'name' => '  Ana María (prueba) ' ) );
	$ok( 'nombre sin contraseña actual: 200 con {data} como GET /user/{id}', 200 === $estado && 'Ana María (prueba)' === ( $cuerpo['data']['name'] ?? null ) && $ana === ( $cuerpo['data']['id'] ?? null ) );
	$ok( 'el nombre va en first_name y los apellidos vacíos (R53)', 'Ana María (prueba)' === get_user_meta( $ana, 'first_name', true ) && '' === get_user_meta( $ana, 'last_name', true ) );
	$ok( 'respuesta sin caché (Cache-Control: no-store)', false !== strpos( (string) ( $cabeceras['Cache-Control'] ?? '' ), 'no-store' ) );
	list( $estado, $cuerpo ) = $patch( $ana, array( 'phone' => '+34 600 000 000' ) );
	$ok( 'teléfono: 200 y guardado', 200 === $estado && '+34 600 000 000' === get_user_meta( $ana, 'phone', true ) );
	list( $estado ) = $patch( $ana, array() );
	$ok( 'sin nada: 200 y nada cambia (ausente = no tocar)', 200 === $estado && 'Ana María (prueba)' === get_user_meta( $ana, 'first_name', true ) && '+34 600 000 000' === get_user_meta( $ana, 'phone', true ) );
	list( $estado, $cuerpo ) = $patch( $ana, array( 'phone' => '' ) );
	$ok( 'teléfono "": 422 y no se borra', 422 === $estado && isset( $cuerpo['errors']['phone'] ) && '+34 600 000 000' === get_user_meta( $ana, 'phone', true ) );
	list( $estado ) = $patch( $ana, array( 'phone' => null ) );
	$ok( 'teléfono null: 200 y se borra (decisión a)', 200 === $estado && '' === get_user_meta( $ana, 'phone', true ) );
	list( $estado, $cuerpo ) = $patch( $ana, array( 'name' => '' ) );
	$ok( 'nombre "": 422 «The name field is required.»', 422 === $estado && array( 'The name field is required.' ) === ( $cuerpo['errors']['name'] ?? null ) );
	list( $estado, $cuerpo ) = $patch( $ana, array( 'name' => null ) );
	$ok( 'nombre null: 422 (el nombre no se puede borrar)', 422 === $estado && isset( $cuerpo['errors']['name'] ) );
	list( $estado, $cuerpo ) = $patch( $ana, array( 'name' => str_repeat( 'a', 256 ) ) );
	$ok( 'nombre de 256: 422 max', 422 === $estado && isset( $cuerpo['errors']['name'] ) && 'Ana María (prueba)' === get_user_meta( $ana, 'first_name', true ) );
	list( $estado, $cuerpo ) = $patch( $ana, array( 'name' => 'Ana <b>(prueba)</b>', 'phone' => '+34 611 111 111', 'latitude' => 42.2, 'longitude' => -8.7, 'code' => '123456', 'verified' => false ) );
	$ok( 'nombre sin etiquetas; ubicación, código y verified se ignoran', 200 === $estado && 'Ana (prueba)' === get_user_meta( $ana, 'first_name', true ) && '' === get_user_meta( $ana, 'latitude', true ) && '1' === get_user_meta( $ana, 'zn_verificado', true ) );

	// --- Contraseña ---
	$pedro = $cuenta( 'zn_usuario', 'Pedro' );
	$hash  = get_userdata( $pedro )->user_pass;
	list( $estado, $cuerpo ) = $patch( $pedro, array( 'password' => 'Nueva-misdatos-2' ) );
	$ok( 'contraseña sin la actual: 422 en currentPassword', 422 === $estado && $requerida === ( $cuerpo['errors']['currentPassword'] ?? null ) && get_userdata( $pedro )->user_pass === $hash );
	list( $estado, $cuerpo ) = $patch( $pedro, array( 'password' => 'Nueva-misdatos-2', 'currentPassword' => '' ) );
	$ok( 'contraseña actual "": 422 requerida', 422 === $estado && $requerida === ( $cuerpo['errors']['currentPassword'] ?? null ) );
	list( $estado, $cuerpo ) = $patch( $pedro, array( 'password' => 'corta', 'currentPassword' => $clave ) );
	$ok( 'contraseña nueva corta: 422 en password', 422 === $estado && isset( $cuerpo['errors']['password'] ) && get_userdata( $pedro )->user_pass === $hash );
	list( $estado, $cuerpo ) = $patch( $pedro, array( 'password' => '' ) );
	$ok( 'contraseña nueva "": 422 en password (y pide la actual)', 422 === $estado && isset( $cuerpo['errors']['password'] ) && isset( $cuerpo['errors']['currentPassword'] ) );
	list( $estado, $cuerpo ) = $patch( $pedro, array( 'password' => null, 'currentPassword' => $clave ) );
	$ok( 'contraseña nueva null: 422 (no se puede borrar)', 422 === $estado && isset( $cuerpo['errors']['password'] ) );
	list( $estado, $cuerpo ) = $patch( $pedro, array( 'password' => 'Nueva-misdatos-2', 'currentPassword' => 'mala-de-verdad' ) );
	$ok( 'contraseña actual mala: 422 «The password is incorrect.» y no cambia', 422 === $estado && $incorrecta === ( $cuerpo['errors']['currentPassword'] ?? null ) && get_userdata( $pedro )->user_pass === $hash );
	update_user_meta( $pedro, 'zn_codigo', 'huella-de-prueba' );
	$correos = array();
	list( $estado, $cuerpo ) = $patch( $pedro, array( 'password' => 'Nueva-misdatos-2', 'currentPassword' => $clave, 'name' => 'Pedro (prueba)' ) );
	$cambiada = get_userdata( $pedro );
	$ok( 'contraseña con la actual buena: 200 y cambia (y el nombre a la vez)', 200 === $estado && wp_check_password( 'Nueva-misdatos-2', $cambiada->user_pass, $pedro ) && 'Pedro (prueba)' === get_user_meta( $pedro, 'first_name', true ) );
	$ok( 'el código de recuperación pendiente deja de valer', '' === get_user_meta( $pedro, 'zn_codigo', true ) );
	$aviso = $ultimo_a( $cambiada->user_email );
	$ok( 'llega un aviso de «tu contraseña ha cambiado» a su correo, sin la contraseña', $aviso && false !== stripos( $aviso['subject'], 'contraseña' ) && false === strpos( $aviso['message'], 'Nueva-misdatos-2' ) );
	list( $estado ) = $patch( $pedro, array( 'password' => 'Otra-misdatos-3', 'currentPassword' => $clave ) );
	$ok( 'la contraseña vieja ya no vale como actual', 422 === $estado );

	// --- Correo ---
	$marta   = $cuenta( 'zn_usuario', 'Marta' );
	$viejo   = get_userdata( $marta )->user_email;
	$nuevo   = 'marta-' . wp_rand( 1000, 9999 ) . '-temporal-misdatos@ejemplo.invalid';
	$usados[] = $nuevo;
	$ocupado = get_userdata( $ana )->user_email;
	list( $estado, $cuerpo ) = $patch( $marta, array( 'email' => $nuevo ) );
	$ok( 'correo sin la actual: 422 en currentPassword', 422 === $estado && $requerida === ( $cuerpo['errors']['currentPassword'] ?? null ) );
	list( $estado, $cuerpo ) = $patch( $marta, array( 'email' => 'no-es-un-correo', 'currentPassword' => $clave ) );
	$ok( 'correo mal escrito: 422 en email', 422 === $estado && isset( $cuerpo['errors']['email'] ) );
	list( $estado, $cuerpo ) = $patch( $marta, array( 'email' => '', 'currentPassword' => $clave ) );
	$ok( 'correo "": 422', 422 === $estado && isset( $cuerpo['errors']['email'] ) );
	list( $estado, $cuerpo ) = $patch( $marta, array( 'email' => null, 'currentPassword' => $clave ) );
	$ok( 'correo null: 422 (no se puede borrar)', 422 === $estado && isset( $cuerpo['errors']['email'] ) );
	list( $estado, $cuerpo ) = $patch( $marta, array( 'email' => $ocupado, 'currentPassword' => 'mala-de-verdad' ) );
	$ok( 'correo de otra cuenta con la actual mala: solo «The password is incorrect.» (no delata que existe)', 422 === $estado && array( 'currentPassword' => $incorrecta ) === ( $cuerpo['errors'] ?? null ) );
	list( $estado, $cuerpo ) = $patch( $marta, array( 'email' => strtoupper( $ocupado ), 'currentPassword' => $clave ) );
	$ok( 'correo de otra cuenta con la actual buena: 422 «The email has already been taken.»', 422 === $estado && array( 'The email has already been taken.' ) === ( $cuerpo['errors']['email'] ?? null ) );
	list( $estado ) = $patch( $marta, array( 'email' => strtoupper( $viejo ) ) );
	$ok( 'su mismo correo (en mayúsculas): 200 sin pedir la actual ni mandar nada', 200 === $estado && '' === get_user_meta( $marta, 'zn_email_nuevo', true ) );

	$correos = array();
	list( $estado, $cuerpo ) = $patch( $marta, array( 'email' => " $nuevo ", 'currentPassword' => $clave ) );
	$ok( 'correo nuevo con la actual buena: 200, pero el correo sigue siendo el viejo', 200 === $estado && $viejo === get_userdata( $marta )->user_email && $viejo === ( $cuerpo['data']['email'] ?? null ) );
	$ok( 'queda pendiente el nuevo', $nuevo === get_user_meta( $marta, 'zn_email_nuevo', true ) );
	$al_nuevo = $ultimo_a( $nuevo );
	$datos    = $enlace( $al_nuevo );
	$ok( 'al correo nuevo le llega el enlace /verify-email con id y token', $datos && $marta === $datos['id'] && false !== strpos( $al_nuevo['message'], '/verify-email' ) );
	$al_viejo = $ultimo_a( $viejo );
	$ok( 'al correo viejo le llega un aviso, sin el enlace', $al_viejo && ! $enlace( $al_viejo ) && false === strpos( $al_viejo['message'], 'token=' ) );
	$ok( 'solo se guarda la huella del token', ! in_array( $datos['token'] ?? '', get_user_meta( $marta ), true ) && false === strpos( wp_json_encode( get_user_meta( $marta ) ), $datos['token'] ?? 'x' ) );
	list( $estado ) = $llamar( 'POST', '/login', array( 'email' => $nuevo, 'password' => $clave ), 0, '192.0.2.81' );
	$ok( 'mientras no se confirma, no se entra con el correo nuevo', 401 === $estado );
	list( $estado, $cuerpo ) = $llamar( 'GET', '/verify-email', $datos + array( 'format' => 'json' ) );
	$ok( 'abrir el enlace (GET) no cambia nada: 200 pending', 200 === $estado && array( 'status' => 'pending' ) === $cuerpo && $viejo === get_userdata( $marta )->user_email );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/verify-email', array( 'id' => $marta, 'token' => str_repeat( 'A', 43 ) ) );
	$ok( 'token inventado: 400 error', 400 === $estado && 'error' === ( $cuerpo['status'] ?? null ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/verify-email', array( 'id' => $ana, 'token' => $datos['token'] ) );
	$ok( 'el token con el id de otra cuenta: 400', 400 === $estado );
	$tokens_antes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZN_Tokens::tabla() . ' WHERE user_id = %d AND anulado IS NULL', $marta ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	ZN_Tokens::emitir( $marta );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/verify-email', $datos );
	$ok( 'POST con el token bueno: 200 success y el correo cambia', 200 === $estado && array( 'status' => 'success' ) === $cuerpo && $nuevo === get_userdata( $marta )->user_email );
	$ok( 'el pendiente se borra y la cuenta sigue verificada', '' === get_user_meta( $marta, 'zn_email_nuevo', true ) && '1' === get_user_meta( $marta, 'zn_verificado', true ) );
	$ok( 'al cambiar el correo se anulan todos sus tokens', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZN_Tokens::tabla() . ' WHERE user_id = %d AND anulado IS NULL', $marta ) ) && 0 === $tokens_antes ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	list( $estado ) = $llamar( 'POST', '/verify-email', $datos );
	$ok( 'el enlace vale una sola vez', 400 === $estado );
	list( $estado ) = $llamar( 'POST', '/login', array( 'email' => $nuevo, 'password' => $clave ), 0, '192.0.2.81' );
	$ok( 'ya se entra con el correo nuevo', 200 === $estado );

	// Caducado, ocupado entre medias, y anulado al cambiar la contraseña.
	$luis  = $cuenta( 'zn_usuario', 'Luis' );
	$otro  = 'luis-' . wp_rand( 1000, 9999 ) . '-temporal-misdatos@ejemplo.invalid';
	$usados[] = $otro;
	$patch( $luis, array( 'email' => $otro, 'currentPassword' => $clave ) );
	$datos = $enlace( $ultimo_a( $otro ) );
	update_user_meta( $luis, 'zn_email_nuevo_caduca', time() - 1 );
	list( $estado ) = $llamar( 'POST', '/verify-email', $datos );
	$ok( 'enlace caducado: 400 y el correo no cambia', 400 === $estado && $otro !== get_userdata( $luis )->user_email );
	$patch( $luis, array( 'email' => $otro, 'currentPassword' => $clave ) );
	$datos  = $enlace( $ultimo_a( $otro ) );
	$colado = $cuenta( 'zn_usuario', 'Colado' );
	wp_update_user( array( 'ID' => $colado, 'user_email' => $otro ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/verify-email', $datos );
	$ok( 'si otra cuenta se queda el correo entre medias: 400 y no cambia', 400 === $estado && 'error' === ( $cuerpo['status'] ?? null ) && $otro !== get_userdata( $luis )->user_email );
	wp_update_user( array( 'ID' => $colado, 'user_email' => 'colado-' . wp_rand( 1000, 9999 ) . '-temporal-misdatos@ejemplo.invalid' ) );
	$patch( $luis, array( 'email' => $otro, 'currentPassword' => $clave ) );
	$datos = $enlace( $ultimo_a( $otro ) );
	$patch( $luis, array( 'password' => 'Nueva-misdatos-4', 'currentPassword' => $clave ) );
	list( $estado ) = $llamar( 'POST', '/verify-email', $datos );
	$ok( 'cambiar la contraseña anula el cambio de correo pendiente', 400 === $estado && '' === get_user_meta( $luis, 'zn_email_nuevo', true ) );
	$patch( $luis, array( 'email' => $otro, 'currentPassword' => 'Nueva-misdatos-4' ) );
	$datos = $enlace( $ultimo_a( $otro ) );
	update_user_meta( $luis, 'zn_desactivada', '1' );
	list( $estado ) = $llamar( 'POST', '/verify-email', $datos );
	$ok( 'una cuenta dada de baja no confirma el correo', 400 === $estado && $otro !== get_userdata( $luis )->user_email );
	delete_user_meta( $luis, 'zn_desactivada' );

	// Contraseña y correo a la vez: la contraseña al momento, el correo pendiente.
	$sara  = $cuenta( 'zn_usuario', 'Sara' );
	$mas   = 'sara-' . wp_rand( 1000, 9999 ) . '-temporal-misdatos@ejemplo.invalid';
	$usados[] = $mas;
	list( $estado ) = $patch( $sara, array( 'email' => $mas, 'password' => 'Nueva-misdatos-5', 'currentPassword' => $clave ) );
	$ok( 'contraseña y correo a la vez: la contraseña cambia y el correo queda pendiente', 200 === $estado && wp_check_password( 'Nueva-misdatos-5', get_userdata( $sara )->user_pass, $sara ) && $mas === get_user_meta( $sara, 'zn_email_nuevo', true ) );

	// --- Límite de intentos de la contraseña actual ---
	$eva = $cuenta( 'zn_usuario', 'Eva' );
	for ( $i = 0; $i < 5; $i++ ) {
		$patch( $eva, array( 'password' => 'Nueva-misdatos-6', 'currentPassword' => "mala-$i" ), -1, '192.0.2.82' );
	}
	list( $estado, $cuerpo, $cabeceras ) = $patch( $eva, array( 'password' => 'Nueva-misdatos-6', 'currentPassword' => $clave ), -1, '192.0.2.82' );
	$ok( 'tras 5 contraseñas actuales malas desde una IP: 429 aunque la 6.ª sea buena, y no cambia', 429 === $estado && 'Too Many Attempts.' === ( $cuerpo['message'] ?? null ) && isset( $cabeceras['Retry-After'] ) && wp_check_password( $clave, get_userdata( $eva )->user_pass, $eva ) );
	list( $estado ) = $patch( $eva, array( 'name' => 'Eva (prueba)' ), -1, '192.0.2.82' );
	$ok( 'el nombre (sin contraseña) se sigue pudiendo cambiar', 200 === $estado );
	list( $estado ) = $patch( $eva, array( 'password' => 'Nueva-misdatos-6', 'currentPassword' => $clave ), -1, '192.0.2.83' );
	$ok( 'desde otra IP, la dueña sí puede', 200 === $estado );
	list( $estado ) = $patch( $eva, array( 'password' => 'corta', 'currentPassword' => 'mala' ), -1, '192.0.2.84' );
	$ok( 'un 422 de formato no gasta intentos de la contraseña actual (se mira antes)', 422 === $estado );

	// Límite de escrituras de la cuenta (R94): también cuenta aquí.
	$pepa = $cuenta( 'zn_usuario', 'Pepa' );
	for ( $i = 0; $i < ZN_Limite::REGLAS['escritura']['cuenta'][0]; $i++ ) {
		$patch( $pepa, array( 'name' => "Pepa $i (prueba)" ), -1, '192.0.2.85' );
	}
	list( $estado ) = $patch( $pepa, array( 'name' => 'Pepa de más (prueba)' ), -1, '192.0.2.85' );
	$ok( 'pasado el límite de escrituras de la cuenta: 429', 429 === $estado );

	// --- Por HTTP de verdad, con tokens ---
	$rosa = $cuenta( 'zn_usuario', 'Rosa' );
	// Lo cambia otro proceso (el de la petición HTTP): sin la caché de esta prueba.
	$fresco = static function () use ( $rosa ) {
		clean_user_cache( $rosa );
		wp_cache_delete( $rosa, 'user_meta' );
		return $rosa;
	};
	$t1   = ZN_Tokens::emitir( $rosa );
	$t2   = ZN_Tokens::emitir( $rosa );
	list( $estado, $cuerpo ) = $http( 'PATCH', "/user/$rosa", array( 'name' => 'Rosa (prueba)' ), $t1 );
	$ok( 'HTTP: PATCH del nombre con el token: 200', 200 === $estado && 'Rosa (prueba)' === ( $cuerpo['data']['name'] ?? null ) );
	list( $estado ) = $http( 'POST', "/user/$rosa?_method=PATCH", array( 'name' => 'Rosa Bis (prueba)' ), $t1 );
	$ok( 'HTTP: POST con ?_method=PATCH (como Adalo) también vale', 200 === $estado && 'Rosa Bis (prueba)' === get_user_meta( $fresco(), 'first_name', true ) );
	list( $estado ) = $http( 'PATCH', "/user/$rosa", array( 'password' => 'Nueva-misdatos-7', 'currentPassword' => $clave ), $t1 );
	$ok( 'HTTP: cambiar la contraseña: 200', 200 === $estado );
	$ok( 'HTTP: el token de quien la cambia sigue valiendo (R56)', 200 === $http( 'GET', "/user/$rosa", null, $t1 )[0] );
	$ok( 'HTTP: los demás tokens de la cuenta dejan de valer', 401 === $http( 'GET', "/user/$rosa", null, $t2 )[0] );
	$nueva_rosa = 'rosa-' . wp_rand( 1000, 9999 ) . '-temporal-misdatos@ejemplo.invalid';
	$usados[]   = $nueva_rosa;
	list( $estado ) = $http( 'PATCH', "/user/$rosa", array( 'email' => $nueva_rosa, 'currentPassword' => 'Nueva-misdatos-7' ), $t1 );
	// Los correos de una petición HTTP no pasan por esta prueba: el enlace se fabrica aquí, con un token nuevo.
	$ok( 'HTTP: pedir el cambio de correo: 200 y queda pendiente', 200 === $estado && $nueva_rosa === get_user_meta( $fresco(), 'zn_email_nuevo', true ) );
	$token_correo = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	update_user_meta( $rosa, 'zn_email_nuevo_token', hash( 'sha256', $token_correo ) );
	$url = add_query_arg( array( 'id' => $rosa, 'token' => $token_correo ), rest_url( ZN_NAMESPACE . '/verify-email' ) );
	list( $estado, , $html, $tipo ) = $http( 'GET', $url );
	$ok( 'HTTP: el enlace del correo pinta una página con el botón, sin cambiar nada', 200 === $estado && false !== strpos( $tipo, 'text/html' ) && false !== strpos( $html, '<form method="post"' ) && $nueva_rosa !== get_userdata( $fresco() )->user_email );
	$ok( 'HTTP: la página no enseña el correo nuevo', false === strpos( $html, $nueva_rosa ) );
	list( $estado, , $html ) = $http( 'POST', rest_url( ZN_NAMESPACE . '/verify-email' ), array( 'id' => $rosa, 'token' => $token_correo ), null, true );
	$ok( 'HTTP: el botón confirma el correo', 200 === $estado && false !== strpos( $html, 'Correo cambiado' ) && $nueva_rosa === get_userdata( $fresco() )->user_email );
	$ok( 'HTTP: tras confirmar, tampoco vale el token de la app (hay que entrar con el correo nuevo)', 401 === $http( 'GET', "/user/$rosa", null, $t1 )[0] );

	// Ningún correo a una dirección de verdad (el interruptor de R57 sigue en medio).
	$ok( 'todos los correos de la prueba iban a @ejemplo.invalid', array() === array_filter( $correos, static fn( $c ) => (bool) array_filter( ZN_Puertas::destinatarios( $c ), static fn( $d ) => ! preg_match( '/@ejemplo\.invalid$/', $d ) ) ) );
} finally {
	// Filas de intentos de las cuentas (antes de borrarlas: la clave de una cuenta que existe va por su número).
	foreach ( array_keys( ZN_Limite::REGLAS ) as $accion ) {
		foreach ( array_merge( array( '127.0.0.1', '::1' ), array_map( static fn( $i ) => "192.0.2.$i", range( 80, 85 ) ) ) as $ip ) {
			$_SERVER['REMOTE_ADDR'] = $ip;
			foreach ( $cuentas as $id ) {
				$u = get_userdata( $id );
				if ( $u ) {
					$claves = array_merge( $claves, array_keys( ZN_Limite::claves( $accion, $u->user_email ) ) );
				}
			}
			foreach ( $usados as $direccion ) {
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
$sueltas  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email LIKE '%-temporal-misdatos@ejemplo.invalid'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$intentos = $claves ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', array_unique( $claves ) ) ) . "')" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas temporales, sus tokens y sus filas de intentos', count( $cuentas ) ), ! $quedan && 0 === $sueltas && 0 === $intentos );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

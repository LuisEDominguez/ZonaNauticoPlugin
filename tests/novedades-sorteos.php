<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Novedades y sorteos desde la app: POST /event, PATCH /event/{id}, DELETE /event/{id}, POST /draw, PATCH /draw/{id}
// y DELETE /draw/{id}, con el comportamiento del Laravel (y H15, H22 arreglados).
// Uso: wp eval-file tests/novedades-sorteos.php. Crea cuentas, negocios, novedades y sorteos «temporal-novedades» y
// los borra al terminar. Casi todo por dentro; un par de llamadas por HTTP con un token de verdad.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

$cuentas  = array();
$entradas = array();
$cuenta   = static function ( string $rol_wp, int $padre = 0 ) use ( &$cuentas ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-novedades';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => 'Temporal' ) );
	update_user_meta( $id, 'zn_verificado', '1' );
	if ( $padre ) {
		update_user_meta( $id, 'zn_parent_id', $padre );
	}
	$cuentas[] = $id;
	return $id;
};
$negocio = static function ( int $dueno ) use ( &$entradas ) {
	$id         = ZN_Esquema::guardar( 'negocio', null, array( 'post_title' => 'Negocio temporal-novedades', 'user_id' => $dueno ), array( 'post_status' => 'publish' ) );
	$entradas[] = $id;
	return $id;
};
$llamar = static function ( string $metodo, string $ruta, array $datos = array(), int $quien = 0 ) use ( &$entradas ) {
	wp_set_current_user( $quien );
	$peticion = new WP_REST_Request( $metodo, '/' . ZN_NAMESPACE . $ruta );
	if ( $datos ) {
		$peticion->set_header( 'Content-Type', 'application/json' );
		$peticion->set_body( wp_json_encode( $datos ) );
	}
	$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $peticion ) ), rest_get_server(), $peticion );
	wp_set_current_user( 0 );
	$cuerpo = $respuesta->get_data();
	if ( 201 === $respuesta->get_status() && isset( $cuerpo['data']['_id'] ) ) {
		$entradas[] = (int) $cuerpo['data']['_id'];
	}
	return array( $respuesta->get_status(), $cuerpo );
};
$base = rest_url( ZN_NAMESPACE );
$http = static function ( string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null ) use ( $base ) {
	$cabeceras = array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' );
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
	return is_wp_error( $respuesta ) ? array( 0, null ) : array( wp_remote_retrieve_response_code( $respuesta ), json_decode( wp_remote_retrieve_body( $respuesta ), true ) );
};
$no_autenticado = array( 'message' => 'Unauthenticated.' );
$sin_permiso    = array( 'message' => 'This action is unauthorized.' );
$exito          = array( 'status' => 'success' );

try {
	$admin_app  = $cuenta( 'zn_administrador' );
	$federacion = $cuenta( 'zn_federacion' );
	$asociacion = $cuenta( 'zn_asociacion', $federacion );
	$comercio   = $cuenta( 'zn_comercio', $asociacion );
	$otro_com   = $cuenta( 'zn_comercio' );
	$usuaria    = $cuenta( 'zn_usuario' );
	$tienda     = $negocio( $comercio );
	$ajena      = $negocio( $otro_com );

	// --- Las rutas ---
	$rutas = rest_get_server()->get_routes( ZN_NAMESPACE );
	$hay   = static function ( string $ruta, string $metodo ) use ( $rutas ) {
		$metodos = array();
		foreach ( $rutas[ '/' . ZN_NAMESPACE . $ruta ] ?? array() as $manejador ) {
			$metodos = array_merge( $metodos, array_keys( array_filter( (array) $manejador['methods'] ) ) );
		}
		return in_array( $metodo, $metodos, true );
	};
	foreach ( array( 'event', 'draw' ) as $r ) {
		$ok( "existen POST /$r y PATCH, PUT y DELETE /$r/{id}", $hay( "/$r", 'POST' ) && $hay( "/$r/(?P<id>\d+)", 'PATCH' ) && $hay( "/$r/(?P<id>\d+)", 'PUT' ) && $hay( "/$r/(?P<id>\d+)", 'DELETE' ) );
	}

	// ======================= NOVEDADES =======================
	$novedad = array(
		'name'        => 'Concierto (prueba)',
		'description' => "Primera línea\nSegunda <3",
		'userId'      => $comercio,
		'businessId'  => $tienda,
		'category'    => 1,
		'startDate'   => '2026-12-01 19:00:00',
		'endDate'     => '2026-12-01 22:00:00',
		'latitude'    => 42.24,
		'longitude'   => -8.72,
		'photos'      => array( 'https://ejemplo.invalid/foto.jpg' ),
	);
	list( $estado, $cuerpo ) = $llamar( 'POST', '/event', $novedad );
	$ok( 'POST /event sin sesión: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/event', $novedad, $comercio );
	$id_nov                  = (int) ( $cuerpo['data']['_id'] ?? 0 );
	$ok( 'POST /event: el comercio crea una novedad suya, 201 con el EventResource ({data})', 201 === $estado && $id_nov && 'Concierto (prueba)' === $cuerpo['data']['name'] && '2026-12-01 19:00:00' === $cuerpo['data']['startDate'] && '01-12-2026' === $cuerpo['data']['endDateShortFormat'] && 'https://ejemplo.invalid/foto.jpg' === $cuerpo['data']['photo_0'] );
	$ok( 'POST /event: publicada, con dueño, negocio y tipo guardados', $id_nov && 'publish' === get_post_status( $id_nov ) && ZN_Eventos::TIPO === get_post_type( $id_nov ) && (string) $comercio === get_post_meta( $id_nov, 'user_id', true ) && (string) $tienda === get_post_meta( $id_nov, 'business_id', true ) && '1' === get_post_meta( $id_nov, 'event_category_id', true ) );
	$ok( 'POST /event: sin autor de WordPress (post_author 0), así borrar la cuenta no la borra por detrás', $id_nov && '0' === (string) get_post( $id_nov )->post_author );
	$ok( 'POST /event: sale en GET /event/{id}', 200 === $llamar( 'GET', "/event/$id_nov" )[0] );

	list( $estado, $cuerpo ) = $llamar( 'POST', '/event', array( 'name' => 'Sin dueño (prueba)' ), $comercio );
	$ok( 'POST /event sin userId → 422 (obligatorio, como el Laravel)', 422 === $estado && isset( $cuerpo['errors']['userId'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/event', array( 'userId' => $comercio ), $comercio );
	$ok( 'POST /event sin nombre → 422', 422 === $estado && isset( $cuerpo['errors']['name'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/event', array( 'name' => 'X (prueba)', 'userId' => $otro_com ), $comercio );
	$ok( 'H15: a nombre de otro comercio → 401', 401 === $estado && $sin_permiso === $cuerpo );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/event', array( 'name' => 'X (prueba)', 'userId' => $usuaria ), $usuaria );
	$ok( 'una cuenta «user» no crea novedades (sin event:save_own) → 401', 401 === $estado && $sin_permiso === $cuerpo );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/event', array( 'name' => 'X (prueba)', 'userId' => $comercio, 'businessId' => $ajena ), $comercio );
	$ok( 'R70: colgada del negocio de otro → 422 en businessId', 422 === $estado && isset( $cuerpo['errors']['businessId'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/event', array( 'name' => 'X (prueba)', 'userId' => $comercio, 'startDate' => '2026-12-02 10:00:00', 'endDate' => '2026-12-01 10:00:00' ), $comercio );
	$ok( 'fin antes del inicio → 422 en endDate', 422 === $estado && isset( $cuerpo['errors']['endDate'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/event', array( 'name' => 'X (prueba)', 'userId' => $comercio, 'startDate' => '01/12/2026' ), $comercio );
	$ok( 'fecha con otro formato → 422 (Y-m-d H:i:s)', 422 === $estado && isset( $cuerpo['errors']['startDate'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/event', array( 'name' => 'De la asociación (prueba)', 'userId' => $comercio, 'businessId' => $tienda ), $asociacion );
	$ok( 'la asociación crea una novedad para su comercio (201)', 201 === $estado );

	// PATCH
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/event/$id_nov", array( 'name' => 'Cambio' ) );
	$ok( 'PATCH /event sin sesión: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	foreach ( array( 'otro comercio' => $otro_com, 'una usuaria' => $usuaria ) as $quien => $id_cuenta ) {
		list( $estado, $cuerpo ) = $llamar( 'PATCH', "/event/$id_nov", array( 'name' => 'Cambio' ), $id_cuenta );
		$ok( "PATCH /event: $quien no puede (401)", 401 === $estado && $sin_permiso === $cuerpo && 'Concierto (prueba)' === get_the_title( $id_nov ) );
	}
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/event/$id_nov", array( 'name' => 'Concierto cambiado (prueba)', 'userId' => $otro_com, 'category' => 2, 'categoryId' => 2 ), $comercio );
	$ok( 'PATCH /event: el dueño cambia nombre y tipo (200) sin mandar userId de más', 200 === $estado && 'Concierto cambiado (prueba)' === ( $cuerpo['data']['name'] ?? null ) && '2' === get_post_meta( $id_nov, 'event_category_id', true ) );
	$ok( 'H15: el userId que manda un comercio no cambia el dueño', (string) $comercio === get_post_meta( $id_nov, 'user_id', true ) );
	$ok( 'PATCH: los campos que no llegan no se tocan', '2026-12-01 19:00:00' === get_post_meta( $id_nov, 'start_date', true ) && 'https://ejemplo.invalid/foto.jpg' === get_post_meta( $id_nov, 'photo_0', true ) );
	list( $estado, $cuerpo ) = $llamar( 'PUT', "/event/$id_nov", array( 'description' => null ), $asociacion );
	$ok( 'PUT también vale; la asociación del comercio edita (hereda) y null borra el texto', 200 === $estado && null === $cuerpo['data']['description'] );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/event/$id_nov", array( 'startDate' => '2026-12-02 10:00:00' ), $comercio );
	$ok( 'PATCH solo del inicio, después del fin guardado → 422', 422 === $estado && ( isset( $cuerpo['errors']['startDate'] ) || isset( $cuerpo['errors']['endDate'] ) ) && '2026-12-01 19:00:00' === get_post_meta( $id_nov, 'start_date', true ) );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/event/$id_nov", array( 'name' => null ), $comercio );
	$ok( 'PATCH: el nombre no se puede borrar (null → 422)', 422 === $estado && isset( $cuerpo['errors']['name'] ) );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/event/$id_nov", array( 'name' => '' ), $comercio );
	$ok( 'PATCH: "" → 422', 422 === $estado );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/event/$id_nov", array( 'userId' => $otro_com ), $admin_app );
	$ok( 'la administración de la app sí cambia el dueño (si el negocio lo permite, R70: aquí no) → 422', 422 === $estado && isset( $cuerpo['errors']['userId'] ) );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/event/$id_nov", array( 'userId' => $asociacion ), $admin_app );
	$ok( 'la administración de la app cambia el dueño a la asociación del comercio (200)', 200 === $estado && (string) $asociacion === get_post_meta( $id_nov, 'user_id', true ) );
	list( $estado ) = $llamar( 'PATCH', '/event/999999999', array( 'name' => 'X' ), $comercio );
	$ok( 'R109: PATCH /event de una que no existe → 401, como una ajena', 401 === $estado );
	list( $estado ) = $llamar( 'PATCH', "/event/$tienda", array( 'name' => 'X' ), $admin_app );
	$ok( 'PATCH /event con el id de un negocio → 404', 404 === $estado );

	// DELETE
	list( $estado, $cuerpo ) = $llamar( 'DELETE', "/event/$id_nov" );
	$ok( 'DELETE /event sin sesión: 401', 401 === $estado && $no_autenticado === $cuerpo );
	list( $estado, $cuerpo ) = $llamar( 'DELETE', "/event/$id_nov", array(), $otro_com );
	$ok( 'DELETE /event: otro comercio no puede (401)', 401 === $estado && $sin_permiso === $cuerpo && get_post( $id_nov ) );
	list( $estado, $cuerpo ) = $llamar( 'DELETE', "/event/$id_nov", array(), $asociacion );
	$ok( 'DELETE /event: su dueña (ahora la asociación) la borra del todo, 200 {status: «success»}', 200 === $estado && $exito === $cuerpo && null === get_post( $id_nov ) );
	list( $estado ) = $llamar( 'DELETE', "/event/$id_nov", array(), $asociacion );
	$ok( 'R109: borrarla otra vez → 401, como una ajena', 401 === $estado );

	// Esta prueba escribe mucho con las mismas cuentas: sin esto llegarían al límite de 20 por hora (R94, se prueba abajo).
	global $wpdb;
	foreach ( array( $comercio, $asociacion, $admin_app ) as $id_cuenta ) {
		foreach ( array_keys( ZN_Limite::claves( 'escritura', get_userdata( $id_cuenta )->user_email ) ) as $clave ) {
			$wpdb->delete( ZN_Limite::tabla(), array( 'clave' => $clave ) );
		}
	}

	// ======================= SORTEOS =======================
	$sorteo = array(
		'title'       => 'Sorteo (prueba)',
		'description' => 'Bases (prueba)',
		'startDate'   => '2026-12-01 00:00:00',
		'endDate'     => '2026-12-31 23:59:00',
		'winners'     => 2,
		'userId'      => $comercio,
		'legalUrl'    => 'https://ejemplo.invalid/bases',
		'photos'      => array( 'https://ejemplo.invalid/s.jpg' ),
	);
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw', $sorteo );
	$ok( 'POST /draw sin sesión: 401', 401 === $estado && $no_autenticado === $cuerpo );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw', $sorteo, $comercio );
	$id_sor                  = (int) ( $cuerpo['data']['_id'] ?? 0 );
	$ok( 'POST /draw: el comercio crea un sorteo suyo, 201 con el DrawResource', 201 === $estado && $id_sor && 'Sorteo (prueba)' === $cuerpo['data']['title'] && 2 === $cuerpo['data']['winners'] && 'https://ejemplo.invalid/bases' === $cuerpo['data']['legalUrl'] && array_key_exists( 'controlParticipar', $cuerpo['data'] ) );
	$ok( 'POST /draw: publicado y con dueño', $id_sor && 'publish' === get_post_status( $id_sor ) && ZN_Sorteos::TIPO === get_post_type( $id_sor ) && (string) $comercio === get_post_meta( $id_sor, 'user_id', true ) );
	foreach ( array( 'title', 'startDate', 'endDate', 'winners', 'userId' ) as $falta ) {
		list( $estado, $cuerpo ) = $llamar( 'POST', '/draw', array_diff_key( $sorteo, array( $falta => 1 ) ), $comercio );
		$ok( "POST /draw sin $falta → 422", 422 === $estado && isset( $cuerpo['errors'][ $falta ] ) );
	}
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw', array( 'winners' => 0 ) + $sorteo, $comercio );
	$ok( 'POST /draw con 0 ganadores → 422', 422 === $estado && isset( $cuerpo['errors']['winners'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw', array( 'userId' => $usuaria ) + $sorteo, $usuaria );
	$ok( 'una cuenta «user» no crea sorteos → 401', 401 === $estado && $sin_permiso === $cuerpo );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw', array( 'userId' => $otro_com ) + $sorteo, $comercio );
	$ok( 'H15: a nombre de otro → 401', 401 === $estado && $sin_permiso === $cuerpo );

	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/draw/$id_sor", array( 'title' => 'Cambio' ), $asociacion );
	$ok( 'PATCH /draw: la asociación no edita los sorteos de su comercio (sin herencia, como el Laravel) → 401', 401 === $estado && $sin_permiso === $cuerpo );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/draw/$id_sor", array( 'endDate' => '2027-01-15 23:59:00' ), $comercio );
	$ok( 'PATCH /draw: el dueño cambia solo la fecha de fin (200)', 200 === $estado && '2027-01-15 23:59:00' === ( $cuerpo['data']['endDate'] ?? null ) );
	$ok( 'H22: un PATCH sin legalUrl no borra las bases', 'https://ejemplo.invalid/bases' === get_post_meta( $id_sor, 'legal_url', true ) );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/draw/$id_sor", array( 'endDate' => '2026-11-01 00:00:00' ), $comercio );
	$ok( 'PATCH /draw: fin antes del inicio → 422', 422 === $estado && isset( $cuerpo['errors']['endDate'] ) );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/draw/$id_sor", array( 'winners' => null ), $comercio );
	$ok( 'PATCH /draw: los ganadores no se pueden borrar → 422', 422 === $estado && isset( $cuerpo['errors']['winners'] ) );
	list( $estado ) = $llamar( 'PATCH', "/draw/$tienda", array( 'title' => 'X' ), $admin_app );
	$ok( 'PATCH /draw con el id de un negocio → 404', 404 === $estado );
	list( $estado, $cuerpo ) = $llamar( 'DELETE', "/draw/$id_sor", array(), $asociacion );
	$ok( 'DELETE /draw: la asociación no puede → 401', 401 === $estado && $sin_permiso === $cuerpo );
	list( $estado, $cuerpo ) = $llamar( 'DELETE', "/draw/$id_sor", array(), $comercio );
	$ok( 'DELETE /draw: su dueño lo borra del todo (200)', 200 === $estado && $exito === $cuerpo && null === get_post( $id_sor ) );

	// R94: también cuentan para el límite de escrituras por cuenta.
	$prisas = $cuenta( 'zn_comercio' );
	for ( $i = 0; $i < 20; $i++ ) {
		ZN_Limite::intentar( 'escritura', get_userdata( $prisas )->user_email );
	}
	list( $estado, $cuerpo ) = $llamar( 'POST', '/event', array( 'name' => 'X (prueba)', 'userId' => $prisas ), $prisas );
	$ok( 'R94: pasada la 20.ª escritura de la hora, POST /event → 429', 429 === $estado && array( 'message' => 'Too Many Attempts.' ) === $cuerpo );
	list( $estado ) = $llamar( 'POST', '/draw', array( 'userId' => $prisas ) + $sorteo, $prisas );
	$ok( 'R94: y POST /draw → 429', 429 === $estado );

	// --- Por HTTP, con un token de verdad ---
	$token                   = ZN_Tokens::emitir( $otro_com );
	list( $estado, $cuerpo ) = $http( 'POST', '/event', array( 'name' => 'Por HTTP (prueba)', 'userId' => $otro_com, 'businessId' => $ajena ), $token );
	$id_http                 = (int) ( $cuerpo['data']['_id'] ?? 0 );
	if ( $id_http ) {
		$entradas[] = $id_http;
	}
	$ok( 'HTTP: POST /event con el token de la app → 201', 201 === $estado && $id_http );
	list( $estado, $cuerpo ) = $http( 'DELETE', "/event/$id_http", null, $token );
	clean_post_cache( $id_http );
	$ok( 'HTTP: DELETE /event/{id} de la suya → 200', 200 === $estado && $exito === $cuerpo && null === get_post( $id_http ) );
	list( $estado, $cuerpo ) = $http( 'POST', '/draw', $sorteo );
	$ok( 'HTTP: POST /draw sin token → 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	ZN_Tokens::anular( $token );
} finally {
	wp_set_current_user( 0 );
	global $wpdb;
	foreach ( $cuentas as $id ) {
		$u = get_userdata( $id );
		foreach ( $u ? array_keys( ZN_Limite::claves( 'escritura', $u->user_email ) ) : array() as $clave ) {
			$wpdb->delete( ZN_Limite::tabla(), array( 'clave' => $clave ) );
		}
	}
	foreach ( array_unique( $entradas ) as $entrada ) {
		if ( is_int( $entrada ) && get_post( $entrada ) ) {
			wp_delete_post( $entrada, true );
		}
	}
	foreach ( $cuentas as $id ) {
		if ( get_userdata( $id ) ) {
			wp_delete_user( $id );
		}
	}
}
$quedan = array_filter( $entradas, static fn( $id ) => is_int( $id ) && get_post( $id ) );
$ok( sprintf( 'limpieza: borradas %d entradas y %d cuentas', count( array_unique( $entradas ) ), count( $cuentas ) ), ! $quedan && ! array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

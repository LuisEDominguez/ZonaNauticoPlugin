<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// R66: en lo público, de una persona (rol user) solo sale el nombre de pila; las cuentas de comercio, asociación y
// federación son el nombre del negocio o entidad y salen enteras. El nombre entero solo en /user/{id} (con token) y
// en el panel. GET /review?user=<id> («Mis reseñas» en la app) solo con el token de esa misma cuenta.
// Uso: wp eval-file tests/nombre-de-pila.php. Crea cuentas «…-temporal-pila@ejemplo.invalid», un negocio y reseñas,
// llama a las rutas por dentro y unas pocas veces por HTTP con tokens de verdad. Al terminar lo borra todo.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$servidor = $_SERVER;
$cuentas  = array();
$entradas = array();
$cuenta   = static function ( string $rol_wp, string $nombre, string $apellidos = '' ) use ( &$cuentas ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-pila';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => $nombre, 'last_name' => $apellidos ) );
	update_user_meta( $id, 'zn_verificado', '1' );
	$cuentas[] = $id;
	return $id;
};
// Llamada por dentro como la cuenta $quien (0 = sin sesión), pasando por rest_post_dispatch como al servir.
$api = static function ( string $ruta, array $datos = array(), int $quien = 0 ) {
	$_SERVER['REMOTE_ADDR'] = '192.0.2.95';
	wp_set_current_user( $quien );
	$peticion = new WP_REST_Request( 'GET', '/' . ZN_NAMESPACE . $ruta );
	$peticion->set_query_params( $datos );
	$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $peticion ) ), rest_get_server(), $peticion );
	wp_set_current_user( 0 );
	return array( $respuesta->get_status(), $respuesta->get_data(), $respuesta->get_headers() );
};
$base = rest_url( ZN_NAMESPACE );
$http = static function ( string $ruta, ?string $token = null ) use ( $base ) {
	$cabeceras = array( 'Accept' => 'application/json' );
	if ( $token ) {
		$cabeceras['Authorization'] = "Bearer $token";
	}
	for ( $intento = 0; $intento < 3; $intento++ ) {
		usleep( 400000 );
		$respuesta = wp_remote_get( $base . $ruta, array( 'timeout' => 60, 'headers' => $cabeceras ) );
		if ( ! is_wp_error( $respuesta ) && 502 !== wp_remote_retrieve_response_code( $respuesta ) ) {
			break;
		}
		sleep( 15 );
	}
	return is_wp_error( $respuesta ) ? array( 0, null ) : array( wp_remote_retrieve_response_code( $respuesta ), json_decode( wp_remote_retrieve_body( $respuesta ), true ) );
};
$no_autenticado = array( 'message' => 'Unauthenticated.' );
$sin_permiso    = array( 'message' => 'This action is unauthorized.' );

try {
	// --- La pieza: nombre_de_pila() y usuario_publico() ---
	$ana      = $cuenta( 'zn_usuario', 'Ana María', 'López Temporal' );
	$solo     = $cuenta( 'zn_usuario', ' Xoán ' );
	$luis     = $cuenta( 'zn_usuario', 'Luis Pérez Temporal' );
	$comercio = $cuenta( 'zn_comercio', 'Bar do Porto', 'Temporal' );
	$asoc     = $cuenta( 'zn_asociacion', 'Asociación Temporal de Bouzas' );
	$ok( 'R66: de una persona, solo el nombre de pila (la primera palabra del nombre)', 'Ana' === ( ZN_API::usuario_publico( $ana )['name'] ?? null ) );
	$ok( 'R66: con una sola palabra y espacios alrededor, esa palabra', 'Xoán' === ( ZN_API::usuario_publico( $solo )['name'] ?? null ) );
	$ok( 'R66: el nombre entero en first_name (R53) también se corta', 'Luis' === ( ZN_API::usuario_publico( $luis )['name'] ?? null ) );
	$ok( 'R66: un comercio sale con su nombre entero (es el del negocio)', 'Bar do Porto Temporal' === ( ZN_API::usuario_publico( $comercio )['name'] ?? null ) );
	$ok( 'R66: una asociación, también entera', 'Asociación Temporal de Bouzas' === ( ZN_API::usuario_publico( $asoc )['name'] ?? null ) );
	$ok( 'R66: sin cuenta (datos de prueba), el nombre guardado en el comentario, también solo el de pila', 'Pedro' === ( ZN_API::usuario_publico( 0, 'Pedro Gómez Temporal' )['name'] ?? null ) );
	$ok( 'nombre_publico() sigue dando nombre y apellidos (para /user/{id} y el panel)', 'Ana María López Temporal' === ZN_API::nombre_publico( get_userdata( $ana ) ) );

	// --- En las respuestas públicas ---
	$tienda     = ZN_Esquema::guardar( 'negocio', null, array( 'post_title' => 'Negocio temporal-pila', 'user_id' => $comercio ), array( 'post_status' => 'publish', 'post_author' => 0 ) );
	$entradas[] = $tienda;
	$r_ana      = ZN_Esquema::guardar( ZN_Comentarios::RESENA, null, array( 'comment_post_ID' => $tienda, 'comment_content' => 'Bien (prueba)', 'score' => '4' ), array( 'user_id' => $ana, 'comment_approved' => 1 ) );
	ZN_Esquema::guardar( ZN_Comentarios::RESENA, null, array( 'comment_post_ID' => $tienda, 'comment_content' => 'Mal (prueba)', 'score' => '2' ), array( 'user_id' => $luis, 'comment_approved' => 1 ) );
	list( $estado, $cuerpo ) = $api( '/review', array( 'business' => $tienda, 'includesUser' => 1 ) );
	$todo = wp_json_encode( $cuerpo, JSON_UNESCAPED_UNICODE );
	$ok( 'GET /review?business=&includesUser=1: autoras con el nombre de pila', 200 === $estado && array( 'Ana', 'Luis' ) === array_map( static fn( $r ) => $r['user']['name'] ?? null, $cuerpo['data'] ?? array() ) );
	$ok( 'R66: ni apellidos ni el resto del nombre en la respuesta pública', false === strpos( $todo, 'López' ) && false === strpos( $todo, 'María' ) && false === strpos( $todo, 'Pérez' ) );
	list( , $cuerpo ) = $api( "/review/$r_ana", array( 'includesUser' => 1 ) );
	$ok( 'GET /review/{id}: también solo el nombre de pila', 'Ana' === ( $cuerpo['data']['user']['name'] ?? null ) );
	list( , $cuerpo ) = $api( "/business/$tienda", array( 'includesUser' => 1 ) );
	$ok( 'GET /business/{id}?includesUser=1: el dueño (comercio) con su nombre entero', 'Bar do Porto Temporal' === ( $cuerpo['data']['user']['name'] ?? null ) );
	list( , $cuerpo ) = $api( "/user/$ana", array(), $ana );
	$ok( 'GET /user/{id} de la propia cuenta: el nombre entero', 'Ana María López Temporal' === ( $cuerpo['data']['name'] ?? null ) );

	// --- GET /review?user=<id>: solo la propia cuenta, con token ---
	list( $estado, $cuerpo ) = $api( '/review', array( 'user' => $ana ) );
	$ok( 'R66: /review?user= sin sesión: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	list( $estado, $cuerpo ) = $api( '/review', array( 'user' => $ana ), $luis );
	$ok( 'R66: /review?user= de otra cuenta: 401 «This action is unauthorized.»', 401 === $estado && $sin_permiso === $cuerpo );
	list( $estado, $cuerpo ) = $api( '/review', array( 'user' => 999999999 ), $luis );
	$ok( 'R66: /review?user= de una cuenta que no existe: también 401 (no se distingue)', 401 === $estado && $sin_permiso === $cuerpo );
	list( $estado, $cuerpo, $cabeceras ) = $api( '/review', array( 'user' => $ana, 'includesBusiness' => 1 ), $ana );
	$ok( 'R66: /review?user= de la propia cuenta: 200 con sus reseñas y su negocio (Mis reseñas)', 200 === $estado && array( (string) $r_ana ) === array_column( $cuerpo['data'] ?? array(), '_id' ) && isset( $cuerpo['data'][0]['business']['_id'] ) );
	$ok( 'R66: …y sin caché (Cache-Control: no-store, private)', false !== strpos( (string) ( $cabeceras['Cache-Control'] ?? '' ), 'no-store' ) );
	list( $estado, $cuerpo ) = $api( '/review', array( 'business' => $tienda ) );
	$ok( 'sin ?user= sigue siendo público', 200 === $estado && 2 === count( $cuerpo['data'] ?? array() ) );

	// --- Por HTTP de verdad ---
	$t_ana = ZN_Tokens::emitir( $ana );
	$t_luis = ZN_Tokens::emitir( $luis );
	list( $estado ) = $http( "/review?user=$ana" );
	$ok( 'HTTP: /review?user= sin token: 401', 401 === $estado );
	list( $estado ) = $http( "/review?user=$ana", $t_luis );
	$ok( 'HTTP: /review?user= con el token de otra cuenta: 401', 401 === $estado );
	list( $estado, $cuerpo ) = $http( "/review?user=$ana&includesBusiness=1", $t_ana );
	$ok( 'HTTP: /review?user= con su token: 200 con su reseña', 200 === $estado && array( (string) $r_ana ) === array_column( $cuerpo['data'] ?? array(), '_id' ) );
} finally {
	$_SERVER = $servidor;
	foreach ( $entradas as $entrada ) {
		if ( is_int( $entrada ) && $entrada ) {
			wp_delete_post( $entrada, true );
		}
	}
	foreach ( $cuentas as $id ) {
		if ( get_userdata( $id ) ) {
			wp_delete_user( $id );
		}
	}
	if ( $cuentas ) {
		$wpdb->query( 'DELETE FROM ' . ZN_Tokens::tabla() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $cuentas ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
$quedan  = array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) );
$sueltas = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email LIKE '%-temporal-pila@ejemplo.invalid'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas, %d entradas (con sus reseñas) y sus tokens', count( $cuentas ), count( $entradas ) ), ! $quedan && 0 === $sueltas && ! array_filter( $entradas, static fn( $id ) => is_int( $id ) && get_post( $id ) ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

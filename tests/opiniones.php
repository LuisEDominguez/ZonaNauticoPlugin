<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Reseñas y preguntas desde la app: POST /review, DELETE /review/{id}, PATCH /review/reply/{id}, POST /faq y
// PATCH /faq/{id}, con el comportamiento del Laravel (y sus fallos H13, H15, H20, H21 arreglados).
// Uso: wp eval-file tests/opiniones.php. Crea cuentas, negocios y comentarios «temporal-opiniones» y los borra al
// terminar. Casi todo por dentro (con la cuenta puesta con wp_set_current_user); un par de llamadas por HTTP con un
// token de verdad.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

$cuentas  = array();
$entradas = array();
$cuenta   = static function ( string $rol_wp, int $padre = 0, string $nombre = 'Temporal' ) use ( &$cuentas ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-opiniones';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => $nombre ) );
	update_user_meta( $id, 'zn_verificado', '1' );
	if ( $padre ) {
		update_user_meta( $id, 'zn_parent_id', $padre );
	}
	$cuentas[] = $id;
	return $id;
};
$negocio = static function ( int $dueno, string $estado = 'publish' ) use ( &$entradas ) {
	$id         = ZN_Esquema::guardar( 'negocio', null, array( 'post_title' => 'Negocio temporal-opiniones', 'user_id' => $dueno ), array( 'post_status' => $estado ) );
	$entradas[] = $id;
	return $id;
};
// Llamada por dentro como la cuenta $quien (0 = sin sesión), pasando por rest_post_dispatch como al servir.
$llamar = static function ( string $metodo, string $ruta, array $datos = array(), int $quien = 0 ) {
	wp_set_current_user( $quien );
	$peticion = new WP_REST_Request( $metodo, '/' . ZN_NAMESPACE . $ruta );
	if ( 'GET' === $metodo ) {
		$peticion->set_query_params( $datos );
	} elseif ( $datos ) {
		$peticion->set_header( 'Content-Type', 'application/json' );
		$peticion->set_body( wp_json_encode( $datos ) );
	}
	$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $peticion ) ), rest_get_server(), $peticion );
	wp_set_current_user( 0 );
	return array( $respuesta->get_status(), $respuesta->get_data() );
};
$base = rest_url( ZN_NAMESPACE );
// El Local de Windows reinicia su PHP tras muchas peticiones seguidas (502 unos segundos): se espera y se repite.
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
	$comercio   = $cuenta( 'zn_comercio', $asociacion, 'Comercio' );
	$otro_com   = $cuenta( 'zn_comercio', 0, 'Otro' );
	$usuaria    = $cuenta( 'zn_usuario', 0, 'Ana María' );
	$usuario2   = $cuenta( 'zn_usuario', 0, 'Luis' );
	$tienda     = $negocio( $comercio );
	$tienda2    = $negocio( $comercio );
	$ajena      = $negocio( $otro_com );
	$borrador   = $negocio( $comercio, 'draft' );

	// --- Las rutas ---
	$rutas = rest_get_server()->get_routes( ZN_NAMESPACE );
	foreach ( array( '/review' => 'POST', '/review/(?P<id>\d+)' => 'DELETE', '/review/reply/(?P<id>\d+)' => 'PATCH', '/faq' => 'POST', '/faq/(?P<id>\d+)' => 'PATCH' ) as $ruta => $metodo ) {
		$metodos = array();
		foreach ( $rutas[ '/' . ZN_NAMESPACE . $ruta ] ?? array() as $manejador ) {
			$metodos = array_merge( $metodos, array_keys( array_filter( (array) $manejador['methods'] ) ) );
		}
		$ok( "existe $metodo $ruta", in_array( $metodo, $metodos, true ) );
	}

	// --- POST /review ---
	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda, 'userId' => $usuaria, 'score' => 4 ) );
	$ok( 'POST /review sin sesión: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	$antes                   = count( get_comments( array( 'type' => ZN_Comentarios::RESENA, 'post_id' => $tienda, 'status' => 'all' ) ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'businessId' => (string) $tienda, 'userId' => $otro_com, 'score' => '4', 'description' => "me encantó <3\nvolveré", 'datetime' => '2001-01-01 00:00:00', 'response' => 'respuesta colada' ), $usuaria );
	$id_resena               = (int) ( $cuerpo['data']['_id'] ?? 0 );
	$resena                  = $id_resena ? get_comment( $id_resena ) : null;
	$ok( 'POST /review: 201 con la reseña ({data}) como el ReviewResource', 201 === $estado && $resena && array( '_id', 'title', 'description', 'response', 'score', 'datetime' ) === array_keys( $cuerpo['data'] ) );
	$ok( 'POST /review: el texto tal cual (R20) y la puntuación', $resena && "me encantó <3\nvolveré" === $cuerpo['data']['description'] && 4 == $cuerpo['data']['score'] );
	$ok( 'H21: el autor es quien tiene la sesión, aunque la app mande otro userId', $resena && $usuaria === (int) $resena->user_id );
	$ok( 'POST /review: la fecha la pone el servidor (datetime de la app ignorado)', $resena && 0 !== strpos( (string) $cuerpo['data']['datetime'], '2001' ) && abs( time() - strtotime( $resena->comment_date_gmt . ' UTC' ) ) < 120 );
	$ok( 'POST /review: la respuesta no se puede colar al crear', $resena && null === $cuerpo['data']['response'] && '' === get_comment_meta( $id_resena, 'response', true ) );
	$ok( 'decisión c: publicada al momento y sale en GET /review/{id}', $resena && '1' === $resena->comment_approved && 200 === $llamar( 'GET', "/review/$id_resena" )[0] );
	$ok( 'una reseña más en el negocio', $antes + 1 === count( get_comments( array( 'type' => ZN_Comentarios::RESENA, 'post_id' => $tienda, 'status' => 'all' ) ) ) );
	$ok( 'la puntuación del negocio se recalcula (4)', 4.0 === (float) get_post_meta( $tienda, 'score', true ) );
	$ok( 'R65: no guarda la IP, el navegador ni el correo de quien escribe', $resena && '' === $resena->comment_author_IP && '' === $resena->comment_agent && '' === $resena->comment_author_email );

	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda2, 'score' => 2, 'title' => 'Regular' ), $usuario2 );
	$id_resena2              = (int) ( $cuerpo['data']['_id'] ?? 0 );
	$ok( 'POST /review sin userId (la app siempre lo manda, pero no hace falta) y con título', 201 === $estado && 'Regular' === ( $cuerpo['data']['title'] ?? null ) && $usuario2 === (int) get_comment( $id_resena2 )->user_id );

	// H13 (decisión de Luis, 02-11: A): una reseña por persona y negocio.
	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda, 'score' => 5 ), $usuaria );
	$ok( 'H13: una segunda reseña de la misma persona al mismo negocio → 422 en businessId', 422 === $estado && array( 'The business id has already been taken.' ) === ( $cuerpo['errors']['businessId'] ?? null ) );
	wp_set_comment_status( $id_resena, 'hold' );
	list( $estado ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda, 'score' => 5 ), $usuaria );
	$ok( 'H13: también si la suya está oculta desde wp-admin', 422 === $estado );
	wp_set_comment_status( $id_resena, 'approve' );
	list( $estado ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda2, 'score' => 5 ), $usuaria );
	$ok( 'H13: en otro negocio sí puede (201)', 201 === $estado );

	// Datos que no valen.
	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda ), $usuario2 );
	$ok( '422 sin puntuación', 422 === $estado && isset( $cuerpo['errors']['score'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda, 'score' => 6 ), $usuario2 );
	$ok( 'H13: puntuación fuera de 1 a 5 → 422', 422 === $estado && isset( $cuerpo['errors']['score'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'score' => 3 ), $usuario2 );
	$ok( '422 sin negocio', 422 === $estado && isset( $cuerpo['errors']['businessId'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'businessId' => $borrador, 'score' => 3 ), $usuario2 );
	$ok( 'negocio sin publicar → 422 (no existe para la app)', 422 === $estado && isset( $cuerpo['errors']['businessId'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda, 'score' => 3, 'description' => str_repeat( 'a', 2001 ) ), $usuario2 );
	$ok( 'texto de más de 2000 caracteres → 422', 422 === $estado && isset( $cuerpo['errors']['description'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda, 'score' => 3, 'description' => '   ' ), $usuario2 );
	$ok( 'texto en blanco → 422 (regla del PATCH: "" no vale)', 422 === $estado && isset( $cuerpo['errors']['description'] ) );
	$ok( 'ninguno de los fallos ha guardado nada', $antes + 1 === count( get_comments( array( 'type' => ZN_Comentarios::RESENA, 'post_id' => $tienda, 'status' => 'all' ) ) ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda, 'score' => 3 ), 1 );
	$ok( 'una cuenta de wp-admin no escribe reseñas por la API (401)', 401 === $estado && $sin_permiso === $cuerpo );

	// R93: dos POST /review a la vez. Se simula que la otra petición guarda la suya justo después de que esta haya
	// comprobado «no tiene ninguna» (la consulta de esa comprobación devuelve 0 y, en ese momento, entra la otra).
	$carrera   = $cuenta( 'zn_usuario' );
	$la_otra   = 0;
	$simular   = static function ( $resultado, $consulta ) use ( $carrera, $tienda, &$la_otra ) {
		if ( $la_otra || ! $consulta->query_vars['count'] || (int) $consulta->query_vars['user_id'] !== $carrera ) {
			return $resultado;
		}
		$la_otra = ZN_Esquema::guardar( ZN_Comentarios::RESENA, null, array( 'comment_post_ID' => $tienda, 'score' => '2' ), array( 'user_id' => $carrera, 'comment_approved' => 1 ) );
		return 0;
	};
	add_filter( 'comments_pre_query', $simular, 10, 2 );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda, 'score' => 5 ), $carrera );
	remove_filter( 'comments_pre_query', $simular, 10 );
	$suyas = get_comments( array( 'type' => ZN_Comentarios::RESENA, 'post_id' => $tienda, 'user_id' => $carrera, 'status' => 'any', 'fields' => 'ids' ) );
	$ok( 'R93: la otra petición guardó la suya en medio (la simulación funciona)', is_int( $la_otra ) && $la_otra > 0 );
	$ok( 'R93: esta responde 422 en businessId y solo queda una reseña (la primera)', 422 === $estado && isset( $cuerpo['errors']['businessId'] ) && array( $la_otra ) === array_map( 'intval', $suyas ) );
	wp_delete_comment( $la_otra, true );

	// R94: como mucho 20 escrituras por hora y cuenta (también cuentan las que no valen).
	$prisas = $cuenta( 'zn_usuario' );
	$vistos = array();
	for ( $i = 1; $i <= 20; $i++ ) {
		$vistos[] = $llamar( 'POST', '/review', array( 'businessId' => $tienda, 'score' => 9 ), $prisas )[0];
	}
	list( $estado, $cuerpo ) = $llamar( 'POST', '/faq', array( 'userId' => $comercio, 'question' => '¿Hola? (prueba)' ), $prisas );
	$ok( 'R94: 20 escrituras seguidas pasan (aquí, 422 por la nota)', array_fill( 0, 20, 422 ) === $vistos );
	$ok( 'R94: la 21.ª (también de otro tipo) → 429 «Too Many Attempts.» y no guarda nada', 429 === $estado && array( 'message' => 'Too Many Attempts.' ) === $cuerpo && 0 === (int) get_comments( array( 'user_id' => $prisas, 'status' => 'any', 'count' => true ) ) );
	list( $estado ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda, 'score' => 9 ), $usuario2 );
	$ok( 'R94: a otra cuenta no le afecta', 422 === $estado );

	// --- PATCH /review/reply/{id} ---
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/review/reply/$id_resena", array( 'response' => 'Gracias <3' ) );
	$ok( 'responder sin sesión: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	foreach ( array( 'la autora' => $usuaria, 'otro comercio' => $otro_com, 'la asociación del comercio' => $asociacion ) as $quien => $id_cuenta ) {
		list( $estado, $cuerpo ) = $llamar( 'PATCH', "/review/reply/$id_resena", array( 'response' => 'No' ), $id_cuenta );
		$ok( "responder: $quien no puede (401 «This action is unauthorized.»)", 401 === $estado && $sin_permiso === $cuerpo );
	}
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/review/reply/$id_resena", array( 'response' => 'Gracias <3', 'score' => 1, 'description' => 'cambiada' ), $comercio );
	$ok( 'responder: el dueño del negocio sí (200 con la reseña)', 200 === $estado && 'Gracias <3' === ( $cuerpo['data']['response'] ?? null ) );
	$ok( 'responder: solo cambia la respuesta (ni la nota ni el texto)', 4.0 === (float) get_comment_meta( $id_resena, 'score', true ) && "me encantó <3\nvolveré" === get_comment( $id_resena )->comment_content );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/review/reply/$id_resena", array( 'response' => '' ), $comercio );
	$ok( 'responder con "" → 422 (regla del PATCH)', 422 === $estado && isset( $cuerpo['errors']['response'] ) );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/review/reply/$id_resena", array( 'response' => str_repeat( 'a', 2001 ) ), $comercio );
	$ok( 'respuesta de más de 2000 → 422', 422 === $estado && isset( $cuerpo['errors']['response'] ) );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/review/reply/$id_resena", array( 'response' => null ), $admin_app );
	$ok( 'la administración de la app responde en cualquiera; con null se borra la respuesta', 200 === $estado && null === $cuerpo['data']['response'] && '' === get_comment_meta( $id_resena, 'response', true ) );
	list( $estado ) = $llamar( 'PATCH', '/review/reply/999999999', array( 'response' => 'x' ), $comercio );
	$ok( 'R109: responder una reseña que no existe → 401, como una ajena', 401 === $estado );

	// --- DELETE /review/{id} ---
	list( $estado, $cuerpo ) = $llamar( 'DELETE', "/review/$id_resena" );
	$ok( 'borrar sin sesión: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	foreach ( array( 'otra persona' => $usuario2, 'el dueño del negocio' => $comercio, 'la asociación' => $asociacion ) as $quien => $id_cuenta ) {
		list( $estado, $cuerpo ) = $llamar( 'DELETE', "/review/$id_resena", array(), $id_cuenta );
		$ok( "borrar: $quien no puede (401)", 401 === $estado && $sin_permiso === $cuerpo && get_comment( $id_resena ) );
	}
	list( $estado, $cuerpo ) = $llamar( 'DELETE', "/review/$id_resena", array(), $usuaria );
	$ok( 'borrar: la autora sí, 200 {status: «success»} y desaparece del todo (sin papelera)', 200 === $estado && $exito === $cuerpo && null === get_comment( $id_resena ) );
	$ok( 'al borrarla, la puntuación del negocio vuelve a 0', 0.0 === (float) get_post_meta( $tienda, 'score', true ) );
	list( $estado ) = $llamar( 'DELETE', "/review/$id_resena", array(), $usuaria );
	$ok( 'R109: borrarla otra vez → 401, como una ajena', 401 === $estado );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/review', array( 'businessId' => $tienda, 'score' => 3 ), $usuaria );
	$ok( 'H13: tras borrar la suya puede escribir otra (201)', 201 === $estado && $usuaria === (int) get_comment( (int) ( $cuerpo['data']['_id'] ?? 0 ) )->user_id );
	list( $estado, $cuerpo ) = $llamar( 'DELETE', "/review/$id_resena2", array(), $admin_app );
	$ok( 'la administración de la app borra cualquiera', 200 === $estado && null === get_comment( $id_resena2 ) );

	// --- POST /faq ---
	list( $estado, $cuerpo ) = $llamar( 'POST', '/faq', array( 'userId' => $comercio, 'question' => '¿Abrís?' ) );
	$ok( 'POST /faq sin sesión: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/faq', array( 'userId' => $comercio, 'question' => '¿Menores <12 gratis?', 'answer' => 'colada', 'published' => true ), $usuaria );
	$id_pregunta             = (int) ( $cuerpo['data']['_id'] ?? 0 );
	$pregunta                = $id_pregunta ? get_comment( $id_pregunta ) : null;
	$ok( 'POST /faq: 201 con la pregunta ({data}) como el FaqResource', 201 === $estado && $pregunta && array( '_id', 'question', 'answer', 'published' ) === array_keys( $cuerpo['data'] ) );
	$ok( 'POST /faq: sin publicar y sin respuesta, aunque la app los mande (como el Laravel)', $pregunta && '0' === $pregunta->comment_approved && 0 === $cuerpo['data']['published'] && null === $cuerpo['data']['answer'] );
	$ok( 'POST /faq: quien pregunta es la sesión y el destinatario, el comercio', $pregunta && $usuaria === (int) $pregunta->user_id && (string) $comercio === get_comment_meta( $id_pregunta, 'user_id', true ) );
	$ok( 'POST /faq: cuelga del primer negocio publicado del comercio', $pregunta && $tienda === (int) $pregunta->comment_post_ID );
	$ok( 'R65: tampoco guarda IP, navegador ni correo', $pregunta && '' === $pregunta->comment_author_IP && '' === $pregunta->comment_agent && '' === $pregunta->comment_author_email );
	$ok( 'sin publicar no sale en GET /faq (H20)', 404 === $llamar( 'GET', "/faq/$id_pregunta" )[0] );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/faq', array( 'userId' => $usuario2, 'question' => '¿Hola?' ), $usuaria );
	$ok( 'POST /faq a una cuenta que no es un comercio → 422 en userId', 422 === $estado && isset( $cuerpo['errors']['userId'] ) );
	$sin_negocio             = $cuenta( 'zn_comercio' );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/faq', array( 'userId' => $sin_negocio, 'question' => '¿Hola?' ), $usuaria );
	$ok( 'POST /faq a un comercio sin negocios publicados → 422 en userId', 422 === $estado && isset( $cuerpo['errors']['userId'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/faq', array( 'userId' => $comercio ), $usuaria );
	$ok( 'POST /faq sin pregunta → 422', 422 === $estado && isset( $cuerpo['errors']['question'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/faq', array( 'question' => '¿Hola?' ), $usuaria );
	$ok( 'POST /faq sin comercio → 422', 422 === $estado && isset( $cuerpo['errors']['userId'] ) );

	// --- PATCH /faq/{id} ---
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/faq/$id_pregunta", array( 'answer' => 'Sí' ) );
	$ok( 'PATCH /faq sin sesión: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	foreach ( array( 'otra persona' => $usuario2, 'otro comercio' => $otro_com ) as $quien => $id_cuenta ) {
		list( $estado, $cuerpo ) = $llamar( 'PATCH', "/faq/$id_pregunta", array( 'answer' => 'No' ), $id_cuenta );
		$ok( "PATCH /faq: $quien no puede (401)", 401 === $estado && $sin_permiso === $cuerpo );
	}
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/faq/$id_pregunta", array( 'answer' => 'Sí, hasta los 12 <3', 'published' => true, 'userId' => $otro_com ), $comercio );
	$ok( 'el comercio responde y publica (200)', 200 === $estado && 'Sí, hasta los 12 <3' === ( $cuerpo['data']['answer'] ?? null ) && 1 === $cuerpo['data']['published'] );
	$ok( 'H15: el destinatario no cambia aunque se mande userId', (string) $comercio === get_comment_meta( $id_pregunta, 'user_id', true ) );
	$ok( 'publicada sale en GET /faq/{id}', 200 === $llamar( 'GET', "/faq/$id_pregunta" )[0] );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/faq/$id_pregunta", array( 'published' => 'true' ), $comercio );
	$ok( 'published "true" (texto) → 422, como el Laravel', 422 === $estado && isset( $cuerpo['errors']['published'] ) );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/faq/$id_pregunta", array( 'question' => '¿Y los menores de 3?', 'answer' => 'colada', 'published' => true ), $usuaria );
	$ok( 'R41: quien preguntó solo cambia el texto y la pregunta vuelve a quedar sin publicar', 200 === $estado && '¿Y los menores de 3?' === get_comment( $id_pregunta )->comment_content && 'Sí, hasta los 12 <3' === get_comment_meta( $id_pregunta, 'answer', true ) && '0' === get_comment( $id_pregunta )->comment_approved );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/faq/$id_pregunta", array( 'published' => 1 ), $asociacion );
	$ok( 'la asociación del comercio también la publica (hereda en preguntas, como el Laravel)', 200 === $estado && '1' === get_comment( $id_pregunta )->comment_approved );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/faq/$id_pregunta", array( 'answer' => null ), $comercio );
	$ok( 'con null se borra la respuesta', 200 === $estado && null === $cuerpo['data']['answer'] );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/faq/$id_pregunta", array( 'question' => '' ), $comercio );
	$ok( 'pregunta vacía → 422', 422 === $estado && isset( $cuerpo['errors']['question'] ) );
	list( $estado ) = $llamar( 'PATCH', '/faq/999999999', array( 'answer' => 'x' ), $comercio );
	$ok( 'R109: PATCH /faq de una que no existe → 401, como una ajena', 401 === $estado );

	// --- Por HTTP, con un token de verdad ---
	$token                   = ZN_Tokens::emitir( $usuario2 );
	list( $estado, $cuerpo ) = $http( 'POST', '/review', array( 'businessId' => $ajena, 'userId' => $usuario2, 'score' => 5, 'description' => 'Muy bien (prueba)' ), $token );
	$id_http                 = (int) ( $cuerpo['data']['_id'] ?? 0 );
	$ok( 'HTTP: POST /review con el token de la app → 201', 201 === $estado && $id_http && $usuario2 === (int) get_comment( $id_http )->user_id );
	list( $estado, $cuerpo ) = $http( 'POST', '/review', array( 'businessId' => $ajena, 'score' => 5 ) );
	$ok( 'HTTP: sin token → 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	list( $estado, $cuerpo ) = $http( 'DELETE', "/review/$id_http", null, $token );
	clean_comment_cache( $id_http ); // Lo borra otra petición (el sitio), no este proceso.
	$ok( 'HTTP: DELETE /review/{id} de la suya → 200', 200 === $estado && $exito === $cuerpo && null === get_comment( $id_http ) );
	ZN_Tokens::anular( $token );
} finally {
	wp_set_current_user( 0 );
	// Filas de intentos de escritura de las cuentas temporales (R94).
	global $wpdb;
	foreach ( $cuentas as $id ) {
		$u = get_userdata( $id );
		foreach ( $u ? array_keys( ZN_Limite::claves( 'escritura', $u->user_email ) ) : array() as $clave ) {
			$wpdb->delete( ZN_Limite::tabla(), array( 'clave' => $clave ) );
		}
	}
	foreach ( $entradas as $entrada ) {
		if ( is_int( $entrada ) ) {
			wp_delete_post( $entrada, true );
		}
	}
	foreach ( $cuentas as $id ) {
		if ( get_userdata( $id ) ) {
			wp_delete_user( $id );
		}
	}
}
$quedan     = array_filter( $entradas, static fn( $id ) => is_int( $id ) && get_post( $id ) );
$comentarios = get_comments( array( 'post__in' => array_filter( $entradas, 'is_int' ) ?: array( 0 ), 'status' => 'all', 'type__in' => array( ZN_Comentarios::RESENA, ZN_Comentarios::PREGUNTA ), 'count' => true ) );
$ok( sprintf( 'limpieza: borradas %d entradas (y sus comentarios) y %d cuentas', count( $entradas ), count( $cuentas ) ), ! $quedan && 0 === (int) $comentarios && ! array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Esquema y validación comunes (R58). No escribe nada en la base de datos: solo lee los datos de prueba
// (un negocio publicado y una cuenta de la app, si la hay) y registra una ruta de prueba en memoria.
// Uso: wp eval-file tests/esquema.php
foreach ( array( 'campos', 'negocios', 'eventos', 'sorteos' ) as $f ) {
	require_once WP_PLUGIN_DIR . "/zona-nautico/includes/class-zn-admin-$f.php";
}

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};
$v = static fn( string $recurso, array $datos, ?array $actual = null ) => ZN_Esquema::validar( $recurso, $datos, $actual );

// --- Una sola definición: los campos personalizados son los de siempre ---
$antes = array(
	'negocio' => array( 'business_category_id', 'latitude', 'longitude', 'address', 'postcode', 'state', 'city', 'country', 'phone', 'email', 'web', 'schedule', 'photo_0', 'photo_1', 'photo_2', 'video_0', 'video_1', 'video_2', 'score', 'user_id' ),
	'evento'  => array( 'user_id', 'event_category_id', 'business_id', 'latitude', 'longitude', 'start_date', 'end_date', 'photo_0', 'photo_1', 'photo_2', 'video_0', 'video_1', 'video_2' ),
	'sorteo'  => array( 'user_id', 'start_date', 'end_date', 'winners', 'legal_url', 'photo_0', 'photo_1', 'photo_2', 'video_0', 'video_1', 'video_2' ),
);
foreach ( $antes as $recurso => $metas ) {
	$ahora = ZN_Esquema::metas( $recurso );
	$ok( "$recurso: los campos personalizados no cambian (los mismos " . count( $metas ) . ')', array() === array_diff( $metas, array_keys( $ahora ) ) && array() === array_diff( array_keys( $ahora ), $metas ) );
}
$ok( 'tipos: coordenadas number, ids integer, textos string', 'number' === ZN_Negocios::campos()['latitude'] && 'integer' === ZN_Negocios::campos()['user_id'] && 'string' === ZN_Negocios::campos()['phone'] && 'integer' === ZN_Sorteos::campos()['winners'] );
$ok( 'WordPress tiene registrados los campos del esquema', registered_meta_key_exists( 'post', 'country', ZN_Negocios::TIPO ) && registered_meta_key_exists( 'post', 'video_2', ZN_Sorteos::TIPO ) );
$ok( 'máximos de la sección 4.3: name 50, country 2, phone 20, email 50, title del sorteo 50', 50 === ZN_Esquema::campos( 'negocio' )['name']['max'] && 2 === ZN_Esquema::campos( 'negocio' )['country']['max'] && 20 === ZN_Esquema::campos( 'negocio' )['phone']['max'] && 50 === ZN_Esquema::campos( 'negocio' )['email']['max'] && 50 === ZN_Esquema::campos( 'sorteo' )['title']['max'] );

// --- Formateadores: lo que sale del esquema es lo de antes ---
$negocio = get_posts( array( 'post_type' => ZN_Negocios::TIPO, 'numberposts' => 1, 'title' => 'Taberna O Peirao (prueba)' ) )[0] ?? get_posts( array( 'post_type' => ZN_Negocios::TIPO, 'numberposts' => 1 ) )[0];
$f       = ZN_API_Negocios::formatear( $negocio );
$ok( 'negocio: mismas claves y en el mismo orden que el BusinessResource', array( '_id', 'name', 'description', 'latitude', 'longitude', 'address', 'postcode', 'state', 'city', 'country', 'phone', 'email', 'web', 'schedule', 'photo_0', 'photo_1', 'photo_2', 'video_0', 'video_1', 'video_2', 'score' ) === array_keys( $f ) );
$ok( 'negocio: coordenadas como número y textos tal cual', ( null === $f['latitude'] || is_float( $f['latitude'] ) || is_int( $f['latitude'] ) ) && $negocio->post_title === $f['name'] );
$sorteo = get_posts( array( 'post_type' => ZN_Sorteos::TIPO, 'numberposts' => 1 ) )[0];
$ok( 'sorteo: mismas claves y orden', array( '_id', 'title', 'description', 'startDate', 'endDate', 'winners', 'photo_0', 'photo_1', 'photo_2', 'video_0', 'video_1', 'video_2', 'legalUrl', 'controlParticipar', 'controlVisibilidad' ) === array_keys( ZN_API_Sorteos::formatear( $sorteo ) ) );
$ok( 'sorteo: winners entero', is_int( ZN_API_Sorteos::formatear( $sorteo )['winners'] ) );
$evento = get_posts( array( 'post_type' => ZN_Eventos::TIPO, 'numberposts' => 1 ) )[0];
$ok( 'novedad: mismas claves y orden', array( '_id', 'name', 'description', 'latitude', 'longitude', 'photo_0', 'photo_1', 'photo_2', 'video_0', 'video_1', 'video_2', 'startDate', 'startDateLongFormat', 'startDateShortFormat', 'endDate', 'endDateLongFormat', 'endDateShortFormat' ) === array_keys( ZN_API_Eventos::formatear( $evento ) ) );

// --- Validador: crear (POST) ---
$cuenta_app = get_users( array( 'role__in' => array_keys( ZN_Roles::ROLES_APP ), 'number' => 20 ) );
$cuenta_app = array_values( array_filter( $cuenta_app, static fn( $u ) => null !== ZN_Roles::rol_app( $u ) ) )[0] ?? null;
$ok( 'hay una cuenta de la app en los datos de prueba (wp zona-nautico datos-prueba)', null !== $cuenta_app );
$app_id = $cuenta_app ? $cuenta_app->ID : 0;

$r = $v( 'negocio', array() );
$ok( 'POST sin nada: name y userId obligatorios', array( 'The name field is required.' ) === ( $r['errores']['name'] ?? null ) && array( 'The user id field is required.' ) === ( $r['errores']['userId'] ?? null ) );

$r = $v(
	'negocio',
	array(
		'name' => '  Taberna nueva  ', 'userId' => $app_id, 'categoryId' => 3, 'country' => 'es', 'latitude' => '42.2388906', 'longitude' => -8.7259422,
		'phone' => '600 000 000', 'email' => 'hola@ejemplo.invalid', 'web' => 'https://ejemplo.invalid/a?b=1&c=2', 'schedule' => "lunes\t10:00",
		'photos' => array( 'https://ejemplo.invalid/1.jpg' ), 'campoInventado' => 'x', 'score' => 5,
	)
);
$ok( 'POST bueno: sin errores', array() === $r['errores'] );
$ok( 'POST bueno: nombres guardados (camelCase → columna del Laravel)', 'Taberna nueva' === $r['valores']['post_title'] && 3 === $r['valores']['business_category_id'] && $app_id === $r['valores']['user_id'] );
$ok( 'POST bueno: país en mayúsculas, coordenadas como texto numérico, horario con «: »', 'ES' === $r['valores']['country'] && '42.2388906' === $r['valores']['latitude'] && '-8.7259422' === $r['valores']['longitude'] && 'lunes: 10:00' === $r['valores']['schedule'] );
$ok( 'POST bueno: photos[0] → photo_0; los huecos que no llegan no se tocan', 'https://ejemplo.invalid/1.jpg' === $r['valores']['photo_0'] && ! array_key_exists( 'photo_1', $r['valores'] ) );
$ok( 'campos que no están en el esquema se ignoran (y la puntuación no se escribe, H14)', ! array_key_exists( 'campoInventado', $r['valores'] ) && ! array_key_exists( 'score', $r['valores'] ) );

$r = $v(
	'negocio',
	array(
		'name' => str_repeat( 'a', 51 ), 'userId' => 999999999, 'categoryId' => 99, 'country' => 'ESP', 'latitude' => 91, 'phone' => 600000000,
		'email' => 'no-es-email', 'web' => 'javascript:alert(1)', 'photos' => array( 'ftp://x.es/a.jpg' ), 'videos' => array( 'a', 'b', 'c', 'd' ),
	)
);
$e = $r['errores'];
$ok( 'máximo: «The name field must not be greater than 50 characters.»', array( 'The name field must not be greater than 50 characters.' ) === ( $e['name'] ?? null ) );
$ok( 'cuenta inexistente: «The selected user id is invalid.»', array( 'The selected user id is invalid.' ) === ( $e['userId'] ?? null ) );
$ok( 'categoría inexistente: «The selected category id is invalid.»', array( 'The selected category id is invalid.' ) === ( $e['categoryId'] ?? null ) );
$ok( 'país de 3 letras: «The country field must be 2 characters.»', array( 'The country field must be 2 characters.' ) === ( $e['country'] ?? null ) );
$ok( 'latitud 91: «The latitude field must be between -90 and 90.»', array( 'The latitude field must be between -90 and 90.' ) === ( $e['latitude'] ?? null ) );
$ok( 'teléfono como número: «The phone field must be a string.»', array( 'The phone field must be a string.' ) === ( $e['phone'] ?? null ) );
$ok( 'email malo: «The email field must be a valid email address.»', array( 'The email field must be a valid email address.' ) === ( $e['email'] ?? null ) );
$ok( 'web javascript: «The web field must be a valid URL.»', array( 'The web field must be a valid URL.' ) === ( $e['web'] ?? null ) );
$ok( 'foto ftp: error en «photos.0»', array( 'The photos.0 field must be a valid URL.' ) === ( $e['photos.0'] ?? null ) );
$ok( 'cuatro vídeos: «The videos field must not have more than 3 items.»', array( 'The videos field must not have more than 3 items.' ) === ( $e['videos'] ?? null ) );
$ok( 'con errores no se devuelve ningún valor de esos campos', ! array_key_exists( 'post_title', $r['valores'] ) && ! array_key_exists( 'country', $r['valores'] ) );

$respuesta = ZN_Esquema::respuesta( $e );
$cuerpo    = $respuesta->get_data();
$ok( '422 con el formato del Laravel: message = primero + «(and N more errors)»', 422 === $respuesta->get_status() && 'The name field must not be greater than 50 characters. (and 9 more errors)' === $cuerpo['message'] && is_array( $cuerpo['errors']['name'] ) );

// --- Validador: modificar (PATCH), decisión a ---
$r = $v( 'negocio', array( 'phone' => '600' ), array() );
$ok( 'PATCH: campo ausente no se toca (solo vuelve phone)', array( 'phone' => '600' ) === $r['valores'] && array() === $r['errores'] );
$r = $v( 'negocio', array( 'web' => null, 'photos' => null ), array() );
$ok( 'PATCH: null borra (web y las tres fotos)', array( 'web' => null, 'photo_0' => null, 'photo_1' => null, 'photo_2' => null ) === $r['valores'] );
$r = $v( 'negocio', array( 'photos' => array( 1 => null ) ), array() );
$ok( 'PATCH: photos[1] = null borra solo ese hueco', array( 'photo_1' => null ) === $r['valores'] );
$r = $v( 'negocio', array( 'phone' => '', 'city' => '   ' ), array() );
$ok( 'PATCH: "" o solo espacios → 422 «The phone field must have a value.»', array( 'The phone field must have a value.' ) === ( $r['errores']['phone'] ?? null ) && isset( $r['errores']['city'] ) && array() === $r['valores'] );
$r = $v( 'negocio', array( 'name' => null ), array() );
$ok( 'PATCH: null en un obligatorio → 422 «is required»', array( 'The name field is required.' ) === ( $r['errores']['name'] ?? null ) );
$r = $v( 'negocio', array( 'name' => '<b></b>' ), array() );
$ok( 'texto que se queda vacío al quitar etiquetas → 422', isset( $r['errores']['name'] ) );

// --- Novedades y sorteos ---
$r = $v( 'evento', array( 'name' => 'Regata', 'userId' => $app_id, 'categoryId' => 2, 'startDate' => '2026-11-02 10:30:00', 'endDate' => '2026-11-02 12:00:00' ) );
$ok( 'novedad: `categoryId` vale como alias de `category` (H37)', array() === $r['errores'] && 2 === $r['valores']['event_category_id'] );
$r = $v( 'evento', array( 'startDate' => '2026-11-02 10:30:00', 'endDate' => '2026-11-01 10:30:00' ), array() );
$ok( 'novedad: fin antes que inicio → «The end date field must be a date after or equal to start date.»', array( 'The end date field must be a date after or equal to start date.' ) === ( $r['errores']['endDate'] ?? null ) );
$r = $v( 'evento', array( 'endDate' => '2026-11-01 10:30:00' ), array( 'start_date' => '2026-11-02 10:30:00' ) );
$ok( 'novedad PATCH: el fin se compara con el inicio guardado', isset( $r['errores']['endDate'] ) );
$r = $v( 'evento', array( 'startDate' => '02-11-2026', 'businessId' => 999999999 ), array() );
$ok( 'novedad: fecha en otro formato → «must match the format Y-m-d H:i:s»', array( 'The start date field must match the format Y-m-d H:i:s.' ) === ( $r['errores']['startDate'] ?? null ) );
$ok( 'novedad: negocio inexistente → «The selected business id is invalid.»', array( 'The selected business id is invalid.' ) === ( $r['errores']['businessId'] ?? null ) );
$r = $v( 'sorteo', array( 'title' => 'Sorteo', 'userId' => $app_id, 'startDate' => '2026-11-01 00:00:00', 'endDate' => '2026-11-30 23:59:00', 'winners' => '0' ) );
$ok( 'sorteo: winners 0 → «The winners field must be at least 1.»', array( 'The winners field must be at least 1.' ) === ( $r['errores']['winners'] ?? null ) );
$r = $v( 'sorteo', array( 'winners' => 2.5 ), array() );
$ok( 'sorteo: winners 2.5 → «must be an integer»', array( 'The winners field must be an integer.' ) === ( $r['errores']['winners'] ?? null ) );
$r = $v( 'sorteo', array() );
$ok( 'sorteo POST: obligatorios title, startDate, endDate, winners y userId', array( 'title', 'startDate', 'endDate', 'winners', 'userId' ) === array_keys( $r['errores'] ) );

// --- Reseñas y preguntas ---
$r = $v( ZN_Comentarios::RESENA, array( 'businessId' => $negocio->ID, 'score' => 6, 'description' => 'me encantó <3' ) );
$ok( 'reseña: puntuación 6 → «between 1 and 5» (H13)', array( 'The score field must be between 1 and 5.' ) === ( $r['errores']['score'] ?? null ) );
$ok( 'reseña: el texto de una persona se guarda tal cual («<3» no se corta, R20)', 'me encantó <3' === ( $r['valores']['comment_content'] ?? null ) );
$r = $v( ZN_Comentarios::PREGUNTA, array( 'published' => 'true' ), array() );
$ok( 'pregunta: published "true" (texto) → «must be true or false» como el Laravel', array( 'The published field must be true or false.' ) === ( $r['errores']['published'] ?? null ) );
$r = $v( ZN_Comentarios::PREGUNTA, array( 'published' => true ), array() );
$ok( 'pregunta: published true → 1', 1 === ( $r['valores']['comment_approved'] ?? null ) );

// --- La ficha de wp-admin aplica las mismas reglas ---
$e = array();
$l = ZN_Admin_Negocios::limpiar( array( 'phone' => str_repeat( '6', 21 ), 'city' => str_repeat( 'c', 31 ), 'country' => 'ESP', 'email' => str_repeat( 'a', 46 ) . '@b.es' ), $e );
$ok( 'ficha: teléfono de 21, ciudad de 31, país de 3 letras y email de 51 no se guardan', ! array_key_exists( 'phone', $l ) && ! array_key_exists( 'city', $l ) && ! array_key_exists( 'country', $l ) && ! array_key_exists( 'email', $l ) && 4 === count( $e ) );
$ok( 'ficha: avisos en español con el motivo', in_array( 'El teléfono pasa de 20 caracteres; no se ha cambiado.', $e, true ) && in_array( 'El país tiene que tener 2 letras; no se ha cambiado.', $e, true ) );
$e = array();
$l = ZN_Admin_Sorteos::limpiar( array( 'winners' => '' ), $e );
$ok( 'ficha: ganadores vacío (obligatorio) no se borra y avisa', ! array_key_exists( 'winners', $l ) && in_array( 'El número de ganadores es obligatorio; no se ha cambiado.', $e, true ) );
ob_start();
ZN_Admin_Negocios::caja_datos( $negocio );
$html = ob_get_clean();
$ok( 'ficha: las casillas llevan el máximo del esquema (maxlength)', false !== strpos( $html, 'name="zn[phone]" maxlength="20"' ) && false !== strpos( $html, 'name="zn[country]" maxlength="2"' ) );

// --- Por HTTP interno: una ruta de prueba (solo en memoria) que valida con el esquema ---
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			ZN_NAMESPACE,
			'/prueba-esquema',
			array(
				'methods'             => 'PATCH',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $peticion ) {
					$r = ZN_Esquema::validar( 'negocio', (array) $peticion->get_json_params(), array() );
					return $r['errores'] ? ZN_Esquema::respuesta( $r['errores'] ) : new WP_REST_Response( array( 'valores' => $r['valores'] ) );
				},
			)
		);
	}
);
$pedir = static function ( string $json ) {
	$peticion = new WP_REST_Request( 'PATCH', '/' . ZN_NAMESPACE . '/prueba-esquema' );
	$peticion->set_header( 'Content-Type', 'application/json' );
	$peticion->set_body( $json );
	$respuesta = rest_do_request( $peticion );
	return array( $respuesta->get_status(), rest_get_server()->response_to_data( $respuesta, false ) );
};
list( $estado, $cuerpo ) = $pedir( '{"phone":"","web":null,"city":"Vigo"}' );
$ok( 'JSON: "" → 422 con errors.phone y nada guardado', 422 === $estado && array( 'The phone field must have a value.' ) === ( $cuerpo['errors']['phone'] ?? null ) && ! isset( $cuerpo['valores'] ) );
list( $estado, $cuerpo ) = $pedir( '{"web":null,"city":"Vigo"}' );
$ok( 'JSON: null borra y lo demás se guarda (200)', 200 === $estado && array( 'city' => 'Vigo', 'web' => null ) === $cuerpo['valores'] );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

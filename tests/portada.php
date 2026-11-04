<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Portada real (bloque 2, parte B): GET /news y /news/{id} (Novedades ZN), GET /home-slides, GET /business-category
// (categorías fijas con sort_order) e includesCategory en /business. Nombres del traspaso: news (title, description,
// image, url, date), home_slides y category {_id, name}. Slides y Novedades ZN solo las edita la administración.
// Uso: wp eval-file tests/portada.php. Crea novedades y slides «temporal-portada» y las borra; prueba la carga
// inicial con datos inventados (no la de verdad).

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

$entradas = array();
$cuentas  = array();
$api      = static function ( string $ruta, array $datos = array() ) {
	$peticion = new WP_REST_Request( 'GET', '/' . ZN_NAMESPACE . $ruta );
	$peticion->set_query_params( $datos );
	$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $peticion ) ), rest_get_server(), $peticion );
	return array( $respuesta->get_status(), $respuesta->get_data() );
};
$noticia = static function ( array $datos, string $estado = 'publish' ) use ( &$entradas ) {
	$id = wp_insert_post(
		array(
			'post_type'     => ZN_Portada::NOTICIA,
			'post_status'   => $estado,
			'post_title'    => $datos['title'] ?? 'Novedad temporal-portada',
			'post_content'  => $datos['description'] ?? '',
			// La fecha se da en hora de Madrid, sea cual sea la zona del sitio.
			'post_date_gmt' => ZN_Portada::utc_desde_madrid( $datos['date'] ?? wp_date( 'Y-m-d H:i:s', null, new DateTimeZone( 'Europe/Madrid' ) ) ),
			'post_author'   => 0,
		)
	);
	foreach ( array( 'image', 'url' ) as $campo ) {
		if ( isset( $datos[ $campo ] ) ) {
			update_post_meta( $id, $campo, $datos[ $campo ] );
		}
	}
	$entradas[] = $id;
	return $id;
};
$slide = static function ( string $nombre, int $orden, string $estado = 'publish', ?string $imagen = 'https://ejemplo.invalid/slide.jpg' ) use ( &$entradas ) {
	$id = wp_insert_post( array( 'post_type' => ZN_Portada::SLIDE, 'post_status' => $estado, 'post_title' => $nombre, 'menu_order' => $orden, 'post_author' => 0 ) );
	if ( null !== $imagen ) {
		update_post_meta( $id, 'image', $imagen );
	}
	$entradas[] = $id;
	return $id;
};

try {
	$rutas = rest_get_server()->get_routes( ZN_NAMESPACE );
	foreach ( array( '/news', '/news/(?P<id>\d+)', '/home-slides', '/business-category' ) as $ruta ) {
		$ok( "existe GET $ruta", isset( $rutas[ '/' . ZN_NAMESPACE . $ruta ] ) );
	}

	// --- Categorías: fijas, con sort_order ---
	list( $estado, $cuerpo ) = $api( '/business-category' );
	$categorias = $cuerpo['data'] ?? array();
	$ok( 'GET /business-category: 200 con las 7 categorías', 200 === $estado && 7 === count( $categorias ) );
	$ok( 'cada una con _id (texto), name y sort_order, en ese orden', $categorias && array( '_id', 'name', 'sort_order' ) === array_keys( $categorias[0] ) && is_string( $categorias[0]['_id'] ) );
	$ok( 'ordenadas por sort_order 1…7, como el «Orden» de Adalo', array( 1, 2, 3, 4, 5, 6, 7 ) === array_column( $categorias, 'sort_order' ) && array( '2', '4', '6', '1', '3', '5', '7' ) === array_column( $categorias, '_id' ) );
	$ok( 'nombres de la app (Comercio primero, Ocio nocturno al final)', 'Comercio' === ( $categorias[0]['name'] ?? null ) && 'Ocio nocturno' === ( $categorias[6]['name'] ?? null ) );
	$tienda     = ZN_Esquema::guardar( 'negocio', null, array( 'post_title' => 'Negocio temporal-portada', 'business_category_id' => 3 ), array( 'post_status' => 'publish', 'post_author' => 0 ) );
	$sin_cat    = ZN_Esquema::guardar( 'negocio', null, array( 'post_title' => 'Negocio sin categoría temporal-portada' ), array( 'post_status' => 'publish', 'post_author' => 0 ) );
	$entradas[] = $tienda;
	$entradas[] = $sin_cat;
	list( , $cuerpo ) = $api( "/business/$tienda", array( 'includesCategory' => 1 ) );
	$ok( 'GET /business/{id}?includesCategory=1: category {_id, name}', array( '_id' => '3', 'name' => 'Restauración' ) === ( $cuerpo['data']['category'] ?? null ) );
	list( , $cuerpo ) = $api( "/business/$sin_cat", array( 'includesCategory' => 1 ) );
	$ok( 'sin categoría: category null', array_key_exists( 'category', $cuerpo['data'] ?? array() ) && null === $cuerpo['data']['category'] );
	list( , $cuerpo ) = $api( "/business/$tienda" );
	$ok( 'sin includesCategory, la ficha no cambia (sin category)', ! array_key_exists( 'category', $cuerpo['data'] ?? array( 'category' => 1 ) ) );
	list( , $cuerpo ) = $api( '/business', array( 'includesCategory' => 1, 'perPage' => 50 ) );
	$ok( 'el listado /business también admite includesCategory', $cuerpo['data'] && array_key_exists( 'category', $cuerpo['data'][0] ) );

	// --- Novedades ZN: GET /news ---
	$vieja  = $noticia( array( 'title' => 'Vieja temporal-portada', 'date' => '2026-01-10 10:00:00', 'description' => 'Uno<br>dos <script>alert(1)</script><img src="https://ejemplo.invalid/a.jpg" onerror="x()">', 'image' => 'https://ejemplo.invalid/vieja.jpg', 'url' => 'https://www.facebook.com/ejemplo/posts/1' ) );
	$nueva  = $noticia( array( 'title' => 'Nueva temporal-portada', 'date' => '2026-03-01 18:30:00' ) );
	$oculta = $noticia( array( 'title' => 'Borrador temporal-portada' ), 'draft' );
	list( $estado, $cuerpo ) = $api( '/news', array( 'perPage' => 50 ) );
	$ids = array_column( $cuerpo['data'] ?? array(), '_id' );
	$ok( 'GET /news: 200 con {data, links, meta}', 200 === $estado && isset( $cuerpo['links'], $cuerpo['meta']['total'] ) );
	$ok( 'solo las publicadas, la más reciente primero', in_array( (string) $vieja, $ids, true ) && in_array( (string) $nueva, $ids, true ) && ! in_array( (string) $oculta, $ids, true ) && array_search( (string) $nueva, $ids, true ) < array_search( (string) $vieja, $ids, true ) );
	list( $estado, $cuerpo ) = $api( "/news/$vieja" );
	$dato = $cuerpo['data'] ?? array();
	$ok( 'GET /news/{id}: campos del traspaso en orden (_id, title, description, image, url, date)', 200 === $estado && array( '_id', 'title', 'description', 'image', 'url', 'date' ) === array_keys( $dato ) );
	$ok( 'fecha "Y-m-d H:i:s" (hora de Madrid, como el resto)', '2026-01-10 10:00:00' === ( $dato['date'] ?? null ) );
	$ok( 'imagen y enlace a la publicación original', 'https://ejemplo.invalid/vieja.jpg' === ( $dato['image'] ?? null ) && 'https://www.facebook.com/ejemplo/posts/1' === ( $dato['url'] ?? null ) );
	$ok( 'descripción: HTML sencillo (br e img), sin scripts ni atributos peligrosos', false !== strpos( (string) $dato['description'], '<br' ) && false !== strpos( (string) $dato['description'], 'src="https://ejemplo.invalid/a.jpg"' ) && false === stripos( (string) $dato['description'], 'script' ) && false === stripos( (string) $dato['description'], 'onerror' ) );
	list( , $cuerpo ) = $api( "/news/$nueva" );
	$ok( 'sin imagen ni enlace ni texto: null', null === $cuerpo['data']['image'] && null === $cuerpo['data']['url'] && null === $cuerpo['data']['description'] );
	$ok( 'un borrador no sale: 404', 404 === $api( "/news/$oculta" )[0] );
	$ok( 'un id de otro tipo (un negocio): 404', 404 === $api( "/news/$tienda" )[0] );
	$ok( 'GET /news?perPage=1&page=2: paginación como el resto', 1 === count( $api( '/news', array( 'perPage' => 1, 'page' => 2 ) )[1]['data'] ?? array() ) );

	// --- Slides: GET /home-slides ---
	$s2 = $slide( 'Segunda temporal-portada', 902 );
	$s1 = $slide( 'Primera temporal-portada', 901 );
	$s3 = $slide( 'Borrador temporal-portada', 900, 'draft' );
	$s4 = $slide( 'Sin foto temporal-portada', 903, 'publish', null );
	list( $estado, $cuerpo ) = $api( '/home-slides' );
	$datos = $cuerpo['data'] ?? array();
	$mias  = array_values( array_filter( $datos, static fn( $s ) => in_array( (int) $s['_id'], array( $s1, $s2, $s3, $s4 ), true ) ) );
	$ok( 'GET /home-slides: 200 con {data}', 200 === $estado && is_array( $datos ) );
	$ok( 'campos _id, name, image, link y sort_order', $mias && array( '_id', 'name', 'image', 'link', 'sort_order' ) === array_keys( $mias[0] ) );
	$ok( 'solo publicadas y con foto, por sort_order', array( (string) $s1, (string) $s2 ) === array_column( $mias, '_id' ) && array( 901, 902 ) === array_column( $mias, 'sort_order' ) );
	$ok( 'link null si no se pone', null === $mias[0]['link'] );
	$ok( 'están ordenadas en toda la lista', array_column( $datos, 'sort_order' ) === ( static function ( $o ) { sort( $o ); return $o; } )( array_column( $datos, 'sort_order' ) ) );

	// --- Quién las edita: solo la administración (de WordPress) ---
	$admin = get_role( 'administrator' );
	$ok( 'el administrador de WordPress puede editar novedades ZN y slides', $admin && $admin->has_cap( 'edit_zn_noticias' ) && $admin->has_cap( 'edit_zn_slides' ) && $admin->has_cap( 'publish_zn_slides' ) );
	$login  = 'comercio-' . wp_rand( 1000, 9999 ) . '-temporal-portada';
	$cuenta = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => 'zn_asociacion' ) );
	$cuentas[] = $cuenta;
	$ok( 'una asociación (con panel) no puede editarlas', ! user_can( $cuenta, 'edit_zn_noticias' ) && ! user_can( $cuenta, 'edit_zn_slides' ) && ! user_can( $cuenta, 'edit_post', $s1 ) );
	$ok( 'no tienen página pública ni ruta /wp/v2', ! is_post_type_viewable( ZN_Portada::NOTICIA ) && ! is_post_type_viewable( ZN_Portada::SLIDE ) && ! isset( $rutas['/wp/v2/' . ZN_Portada::NOTICIA] ) );
	$ok( 'las capacidades nuevas las reconoce la desinstalación (R68)', ZN_Instalacion::es_del_plugin( 'edit_zn_noticias' ) && ZN_Instalacion::es_del_plugin( 'publish_zn_slides' ) );

	// --- Carga inicial (con datos inventados) ---
	$de_origen = static fn() => count( get_posts( array( 'post_type' => ZN_Portada::NOTICIA, 'post_status' => 'any', 'meta_key' => 'zn_origen', 'meta_value' => array( 'temporal-1', 'temporal-2' ), 'meta_compare' => 'IN', 'fields' => 'ids', 'numberposts' => -1 ) ) );
	$crudas = array(
		array( 'id' => 'temporal-1', 'titulo' => 'Carga temporal-portada 1', 'html' => 'Hola<br>', 'fecha' => '2026-05-11T17:05:06.000Z', 'url' => 'https://www.facebook.com/x/posts/1', 'imagen' => 'https://ejemplo.invalid/1.jpg' ),
		array( 'id' => 'temporal-2', 'titulo' => '', 'html' => 'Sin título', 'fecha' => null, 'url' => null, 'imagen' => null ),
	);
	$hechas = ZN_Portada::importar_noticias( $crudas );
	$entradas = array_merge( $entradas, $hechas );
	$ok( 'importar: crea una novedad por cada una, publicada', 2 === count( $hechas ) && 2 === $de_origen() && ! array_filter( $hechas, static fn( $id ) => 'publish' !== get_post_status( $id ) ) );
	list( , $cuerpo ) = $api( '/news/' . $hechas[0] );
	$ok( 'importar: la fecha UTC de Adalo pasa a hora de Madrid', '2026-05-11 19:05:06' === ( $cuerpo['data']['date'] ?? null ) && 'https://ejemplo.invalid/1.jpg' === $cuerpo['data']['image'] );
	$ok( 'importar: sin título, «Novedad» (como la app)', 'Novedad' === get_the_title( $hechas[1] ) );
	$otra = ZN_Portada::importar_noticias( $crudas );
	$ok( 'importar otra vez no duplica (se reconoce por el id de origen)', array() === $otra && 2 === $de_origen() );
} finally {
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
}
$quedan = array_filter( $entradas, static fn( $id ) => is_int( $id ) && get_post( $id ) );
$ok( sprintf( 'limpieza: borradas %d entradas y %d cuentas', count( $entradas ), count( $cuentas ) ), ! $quedan && ! array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

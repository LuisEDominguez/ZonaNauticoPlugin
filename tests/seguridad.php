<?php
// Lo que la API nunca debe enseñar y los arreglos de la revisión 1 (R1, R2, R3, R5, R6, R8, R10, R11, R15, R19).
// Uso: wp eval-file tests/seguridad.php
// Crea sus propios datos (negocios, novedades, comentarios y cuentas con «(temporal)» en el nombre) y los borra al terminar.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

$api = static function ( string $ruta, array $parametros = array() ) {
	$peticion = new WP_REST_Request( 'GET', '/zonanautico/v1' . $ruta );
	$peticion->set_query_params( $parametros );
	return rest_do_request( $peticion );
};
$ids_en = static fn( $respuesta ) => array_column( $respuesta->get_data()['data'], '_id' );

$creados = array( 'entradas' => array(), 'comentarios' => array(), 'usuarios' => array() );
$negocio = static function ( array $datos ) use ( &$creados ) {
	$id                    = wp_insert_post( $datos + array( 'post_type' => ZN_Negocios::TIPO, 'post_status' => 'publish', 'post_title' => 'Negocio (temporal)' ) );
	$creados['entradas'][] = $id;
	return $id;
};
$cuenta = static function ( string $login, array $datos = array() ) use ( &$creados ) {
	$id                    = wp_insert_user( $datos + array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 32 ), 'role' => 'zn_comercio' ) );
	$creados['usuarios'][] = $id;
	return $id;
};
$comentario = static function ( int $negocio, string $tipo, array $datos = array() ) use ( &$creados ) {
	$id                       = wp_insert_comment( $datos + array( 'comment_post_ID' => $negocio, 'comment_type' => $tipo, 'comment_author' => 'Autor (temporal)', 'comment_approved' => 1 ) );
	$creados['comentarios'][] = $id;
	return $id;
};

try {
	// --- R11 y estados: solo salen los negocios publicados y sin contraseña, con todo lo suyo ---
	$visible = $negocio( array() );
	$ocultos = array(
		'con contraseña' => $negocio( array( 'post_password' => 'secreta' ) ),
		'borrador'       => $negocio( array( 'post_status' => 'draft' ) ),
		'privado'        => $negocio( array( 'post_status' => 'private' ) ),
		'programado'     => $negocio( array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS ) ) ),
		'en la papelera' => $negocio( array( 'post_status' => 'trash' ) ),
	);
	$lista = $ids_en( $api( '/business', array( 'perPage' => 50 ) ) );
	$ok( 'un negocio publicado sale', in_array( (string) $visible, $lista, true ) );
	foreach ( $ocultos as $estado => $id ) {
		$ok( "negocio $estado: no sale en el listado", ! in_array( (string) $id, $lista, true ) );
		$ok( "negocio $estado: su ficha da 404", 404 === $api( "/business/$id" )->get_status() );
	}

	$resena   = $comentario( $ocultos['con contraseña'], ZN_Comentarios::RESENA, array( 'comment_meta' => array( 'score' => 5 ) ) );
	$pregunta = $comentario( $ocultos['con contraseña'], ZN_Comentarios::PREGUNTA );
	$ok( 'reseña de un negocio con contraseña: no sale en el listado', ! in_array( (string) $resena, $ids_en( $api( '/review', array( 'perPage' => 50 ) ) ), true ) );
	$ok( 'reseña de un negocio con contraseña: su ficha da 404', 404 === $api( "/review/$resena" )->get_status() );
	$ok( 'pregunta de un negocio con contraseña: no sale', ! in_array( (string) $pregunta, $ids_en( $api( '/faq', array( 'perPage' => 50 ) ) ), true ) );
	$ok( 'pregunta de un negocio con contraseña: su ficha da 404', 404 === $api( "/faq/$pregunta" )->get_status() );

	foreach ( array( ZN_Eventos::TIPO => '/event', ZN_Sorteos::TIPO => '/draw' ) as $tipo => $ruta ) {
		$protegida             = wp_insert_post( array( 'post_type' => $tipo, 'post_status' => 'publish', 'post_title' => 'Protegida (temporal)', 'post_password' => 'secreta' ) );
		$creados['entradas'][] = $protegida;
		$ok( "$ruta con contraseña: no sale en el listado", ! in_array( (string) $protegida, $ids_en( $api( $ruta, array( 'perPage' => 50 ) ) ), true ) );
		$ok( "$ruta con contraseña: su ficha da 404", 404 === $api( "$ruta/$protegida" )->get_status() );
	}

	// --- R15: novedades de un negocio que no se ve ---
	$novedad               = wp_insert_post( array( 'post_type' => ZN_Eventos::TIPO, 'post_status' => 'publish', 'post_title' => 'Novedad (temporal)', 'meta_input' => array( 'business_id' => $ocultos['en la papelera'] ) ) );
	$creados['entradas'][] = $novedad;
	$ok( 'novedad de un negocio en la papelera: no sale en el listado', ! in_array( (string) $novedad, $ids_en( $api( '/event', array( 'perPage' => 50 ) ) ), true ) );
	$ok( 'novedad de un negocio en la papelera: su ficha da 404', 404 === $api( "/event/$novedad" )->get_status() );
	update_post_meta( $novedad, 'business_id', $visible );
	$ok( 'la misma novedad con un negocio visible sí sale', in_array( (string) $novedad, $ids_en( $api( '/event', array( 'perPage' => 50 ) ) ), true ) );

	// --- R1 y R10: el dueño es una cuenta Comercio, con su nombre y nunca su acceso ni su correo ---
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
	$del_admin = $negocio( array( 'post_author' => $admin->ID ) );
	$ok( 'negocio creado por el admin y sin dueño: user null', null === $api( "/business/$del_admin", array( 'includesUser' => 1 ) )->get_data()['data']['user'] );
	update_post_meta( $del_admin, 'user_id', $admin->ID );
	$ok( 'aunque se fuerce el admin como dueño, no sale', null === $api( "/business/$del_admin", array( 'includesUser' => 1 ) )->get_data()['data']['user'] );

	$comercio = $cuenta( 'acceso-secreto-temporal', array( 'first_name' => 'Ana', 'last_name' => 'Temporal' ) );
	update_post_meta( $del_admin, 'user_id', $comercio );
	$respuesta = $api( "/business/$del_admin", array( 'includesUser' => 1 ) )->get_data()['data'];
	$json      = wp_json_encode( $respuesta );
	$ok( 'dueño Comercio: sale con rol commerce y su nombre', array( '_id' => (string) $comercio, 'name' => 'Ana Temporal', 'role' => 'commerce', 'avatar' => null ) === $respuesta['user'] );
	$ok( 'nunca el nombre de acceso ni el correo', false === strpos( $json, 'acceso-secreto-temporal' ) );

	$sin_nombre = $cuenta( 'otro-acceso-temporal' );
	update_post_meta( $del_admin, 'user_id', $sin_nombre );
	$respuesta = $api( "/business/$del_admin", array( 'includesUser' => 1 ) )->get_data()['data'];
	$ok( 'cuenta sin nombre ni apellidos: name null, no el acceso (R10)', null === $respuesta['user']['name'] && false === strpos( wp_json_encode( $respuesta ), 'otro-acceso-temporal' ) );

	$ok( 'la cuenta Comercio no tiene ningún permiso de wp-admin', ! user_can( $comercio, 'read' ) && ! user_can( $comercio, 'edit_posts' ) );

	// --- R8: la pregunta va al comercio guardado en ella, no al dueño actual del negocio ---
	update_post_meta( $visible, 'user_id', $comercio );
	$dirigida = $comentario( $visible, ZN_Comentarios::PREGUNTA, array( 'comment_meta' => array( 'user_id' => $comercio ) ) );
	update_post_meta( $visible, 'user_id', $sin_nombre );
	$ok( 'cambiar el dueño del negocio no se lleva sus preguntas', in_array( (string) $dirigida, $ids_en( $api( '/faq', array( 'user' => $comercio ) ) ), true ) );
	$ok( 'el dueño nuevo no recibe preguntas ajenas', ! in_array( (string) $dirigida, $ids_en( $api( '/faq', array( 'user' => $sin_nombre ) ) ), true ) );

	// --- R5: hora de Madrid, sea cual sea la del sitio ---
	$verano   = $comentario( $visible, ZN_Comentarios::RESENA, array( 'comment_date_gmt' => '2026-07-01 10:00:00', 'comment_meta' => array( 'score' => 4 ) ) );
	$invierno = $comentario( $visible, ZN_Comentarios::RESENA, array( 'comment_date_gmt' => '2026-01-15 10:00:00', 'comment_meta' => array( 'score' => 4 ) ) );
	$ok( 'reseña en verano: UTC+2', '2026-07-01 12:00:00' === $api( "/review/$verano" )->get_data()['data']['datetime'] );
	$ok( 'reseña en invierno: UTC+1', '2026-01-15 11:00:00' === $api( "/review/$invierno" )->get_data()['data']['datetime'] );

	// --- R6: texto plano ---
	$html = $negocio( array( 'post_content' => "<strong>Pulpo</strong> &amp; vino&nbsp;blanco<br>\n<a href=\"https://x.es\">Reserva</a><p>&nbsp;</p>" ) );
	$ok( 'descripción sin etiquetas ni entidades, con sus saltos', "Pulpo & vino blanco\nReserva" === $api( "/business/$html" )->get_data()['data']['description'] );
	$ok( 'un <script> (de un administrador) desaparece con su contenido', "Hola\nAdiós" === ZN_API::texto_plano( "Hola<script>alert(1)</script>\n<em>Adiós</em>" ) );
	$ok( 'texto vacío del editor → null', null === ZN_API::texto_plano( '<p>&nbsp;</p>' ) );

	// --- R19: la puntuación se recalcula aunque llegue después de crear la reseña ---
	$solo    = $negocio( array() );
	$tardia  = $comentario( $solo, ZN_Comentarios::RESENA );
	add_comment_meta( $tardia, 'score', 5 );
	$ok( 'puntuación añadida después: la media pasa a 5', 5.0 === (float) get_post_meta( $solo, 'score', true ) );
	update_comment_meta( $tardia, 'score', 3 );
	$ok( 'puntuación cambiada después: la media pasa a 3', 3.0 === (float) get_post_meta( $solo, 'score', true ) );

	// --- R3: permisos propios ---
	foreach ( array( ZN_Negocios::TIPO, ZN_Eventos::TIPO, ZN_Sorteos::TIPO ) as $tipo ) {
		$cap = get_post_type_object( $tipo )->cap;
		$ok( "$tipo: permisos propios, no los de las entradas", 'edit_posts' !== $cap->edit_posts && 'publish_posts' !== $cap->publish_posts );
		foreach ( array( 'contributor', 'author', 'editor' ) as $rol ) {
			$ok( "$tipo: un $rol de WordPress no puede crear ni publicar", ! get_role( $rol )->has_cap( $cap->edit_posts ) && ! get_role( $rol )->has_cap( $cap->publish_posts ) );
		}
		$ok( "$tipo: el administrador sí", user_can( $admin, $cap->edit_posts ) && user_can( $admin, $cap->publish_posts ) && user_can( $admin, $cap->edit_others_posts ) );
	}

	// --- R2: listas de usuarios y medios cerradas a quien no ha entrado ---
	wp_set_current_user( 0 );
	$rutas = rest_get_server()->get_routes();
	$ok( '/wp/v2/users cerrada', ! array_key_exists( '/wp/v2/users', $rutas ) );
	$ok( '/wp/v2/media cerrada', ! array_key_exists( '/wp/v2/media', $rutas ) );
	$ok( 'XML-RPC apagado', ! apply_filters( 'xmlrpc_enabled', true ) && array() === apply_filters( 'xmlrpc_methods', array( 'x' => 'y' ) ) );
} finally {
	foreach ( $creados['comentarios'] as $id ) {
		wp_delete_comment( $id, true );
	}
	foreach ( $creados['entradas'] as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $creados['usuarios'] as $id ) {
		wp_delete_user( $id );
	}
}
$ok( 'limpieza: no queda nada temporal', ! get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 's' => 'temporal', 'fields' => 'ids' ) ) && ! get_user_by( 'login', 'acceso-secreto-temporal' ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

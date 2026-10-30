<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Lo que la API nunca debe enseñar y los arreglos de la revisión 1 (R1, R2, R3, R5, R6, R8, R10, R11, R15, R19).
// Uso: wp eval-file tests/seguridad.php
// Crea sus propios datos (negocios, novedades, comentarios y cuentas con «(temporal)» en el nombre) y los borra al terminar.

require_once ABSPATH . 'wp-admin/includes/user.php';
require_once WP_PLUGIN_DIR . '/zona-nautico/includes/class-zn-admin-campos.php';
require_once WP_PLUGIN_DIR . '/zona-nautico/includes/class-zn-admin-eventos.php';

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

	// --- R20: lo que escribe una persona en reseñas y preguntas sale entero ---
	$corazon = $comentario( $visible, ZN_Comentarios::RESENA, array( 'comment_content' => 'me encantó <3 volveré', 'comment_meta' => array( 'score' => 5, 'response' => 'Gracias &amp; hasta pronto <3' ) ) );
	$edades  = $comentario( $visible, ZN_Comentarios::PREGUNTA, array( 'comment_content' => '¿Precio para menores <12 y mayores >65?', 'comment_meta' => array( 'user_id' => $comercio, 'answer' => 'menores <12 gratis y >65 a mitad' ) ) );
	$r       = $api( "/review/$corazon" )->get_data()['data'];
	$p       = $api( "/faq/$edades" )->get_data()['data'];
	$ok( 'reseña con «<3»: no se corta', 'me encantó <3 volveré' === $r['description'] );
	$ok( 'respuesta de la reseña: entidades decodificadas y sin cortar', 'Gracias & hasta pronto <3' === $r['response'] );
	$ok( 'pregunta con «<12» y «>65»: no se corta', '¿Precio para menores <12 y mayores >65?' === $p['question'] );
	$ok( 'respuesta con «<12» y «>65»: no se corta', 'menores <12 gratis y >65 a mitad' === $p['answer'] );

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

	// --- R57: correos ---
	$ok( 'R57: fuera de producción no sale un correo a una dirección real', false === apply_filters( 'pre_wp_mail', null, array( 'to' => 'alguien@gmail.com' ) ) );
	$ok( 'R57: ni si va mezclada con una inventada', false === apply_filters( 'pre_wp_mail', null, array( 'to' => array( 'x@ejemplo.invalid', 'alguien@gmail.com' ) ) ) );
	$ok( 'R57: a direcciones inventadas (@ejemplo.invalid, Mailpit) sí', null === apply_filters( 'pre_wp_mail', null, array( 'to' => 'Prueba <x@ejemplo.invalid>' ) ) );
	$datos_comercio = (array) get_userdata( $comercio )->data;
	$ok( 'R57: sin aviso de WordPress de «contraseña cambiada» a una cuenta de la app', false === apply_filters( 'send_password_change_email', true, $datos_comercio, array() ) );
	$ok( 'R57: ni de «correo cambiado»', false === apply_filters( 'send_email_change_email', true, $datos_comercio, array() ) );
	$ok( 'R57: a una cuenta de wp-admin, sí (como siempre)', true === apply_filters( 'send_password_change_email', true, (array) $admin->data, array() ) );

	$ok( 'R57-bis: mira también las copias (Cc y Bcc)', false === apply_filters( 'pre_wp_mail', null, array( 'to' => 'x@ejemplo.invalid', 'headers' => array( 'Cc: Alguien <alguien@gmail.com>' ) ) ) && false === apply_filters( 'pre_wp_mail', null, array( 'to' => 'x@ejemplo.invalid', 'headers' => "From: a@ejemplo.invalid
Bcc: oculto@empresa.es" ) ) );
	$ok( 'R57-bis: con copias inventadas, sí', null === apply_filters( 'pre_wp_mail', null, array( 'to' => 'x@ejemplo.invalid', 'headers' => array( 'Cc: y@ejemplo.invalid', 'Bcc: z@ejemplo.invalid' ) ) ) );
	$ok( 'R57-bis: sin ZN_CORREO_REAL no hay correo real (aquí no está definida)', ! defined( 'ZN_CORREO_REAL' ) && false === apply_filters( 'pre_wp_mail', null, array( 'to' => 'alguien@gmail.com' ) ) );
	$ok( 'R57-bis: sin aviso de «nuevo usuario» al administrador', false === apply_filters( 'wp_send_new_user_notification_to_admin', true, $admin ) );
	$ok( 'R57-bis: sin avisos de comentarios nuevos ni pendientes', '0' === (string) get_option( 'comments_notify' ) && '0' === (string) get_option( 'moderation_notify' ) );

	// --- R63: novedades y sorteos con dueño ---
	$organizada            = wp_insert_post( array( 'post_type' => ZN_Eventos::TIPO, 'post_status' => 'publish', 'post_title' => 'Novedad organizada (temporal)', 'meta_input' => array( 'user_id' => $comercio ) ) );
	$sorteo_org            = wp_insert_post( array( 'post_type' => ZN_Sorteos::TIPO, 'post_status' => 'publish', 'post_title' => 'Sorteo organizado (temporal)', 'meta_input' => array( 'user_id' => $comercio ) ) );
	$creados['entradas'][] = $organizada;
	$creados['entradas'][] = $sorteo_org;
	$ok( 'R63: la novedad sale con su organizador (includesUser)', (string) $comercio === ( $api( "/event/$organizada", array( 'includesUser' => 1 ) )->get_data()['data']['user']['_id'] ?? null ) );
	$ok( 'R63: el sorteo, también', (string) $comercio === ( $api( "/draw/$sorteo_org", array( 'includesUser' => 1 ) )->get_data()['data']['user']['_id'] ?? null ) );
	$ok( 'R63: el organizador puede ver los participantes de su sorteo', ZN_Roles::puede( 'draw_participation:read', get_post( $sorteo_org ), $comercio ) );
	$limpio = array();
	$avisos = array();
	ZN_Admin_Campos::limpiar_dueno( array( 'user_id' => (string) $admin->ID ), $limpio, $avisos, ZN_Roles::ORGANIZADORES );
	$ok( 'R63: en wp-admin, una cuenta de wp-admin no puede ser dueña', ! array_key_exists( 'user_id', $limpio ) && $avisos );
	$limpio = array();
	$avisos = array();
	ZN_Admin_Campos::limpiar_dueno( array( 'user_id' => (string) $comercio ), $limpio, $avisos, ZN_Roles::ORGANIZADORES );
	$ok( 'R63: un comercio sí', $comercio === ( $limpio['user_id'] ?? null ) && ! $avisos );

	// --- R70: dueño de una novedad de un negocio ---
	$admin_app = $cuenta( 'admin-app-temporal', array( 'role' => 'zn_administrador' ) );
	$asoc_t    = $cuenta( 'asociacion-temporal', array( 'role' => 'zn_asociacion' ) );
	$com_hijo  = $cuenta( 'comercio-hijo-temporal' );
	update_user_meta( $com_hijo, 'zn_parent_id', $asoc_t );
	$negocio_h = $negocio( array( 'meta_input' => array( 'user_id' => $com_hijo ) ) );
	$ok( 'R70: el dueño del negocio puede ser dueño de su novedad', ZN_Admin_Eventos::dueno_valido_para_negocio( $com_hijo, $negocio_h ) );
	$ok( 'R70: su asociación, también', ZN_Admin_Eventos::dueno_valido_para_negocio( $asoc_t, $negocio_h ) );
	$ok( 'R70: la administración de la app, también', ZN_Admin_Eventos::dueno_valido_para_negocio( $admin_app, $negocio_h ) );
	$ok( 'R70: otro comercio, no', ! ZN_Admin_Eventos::dueno_valido_para_negocio( $comercio, $negocio_h ) );
	$limpio = array();
	$avisos = array();
	wp_set_current_user( $admin->ID );
	$limpio = ZN_Admin_Eventos::limpiar( array( 'business_id' => (string) $negocio_h, 'user_id' => (string) $comercio ), $avisos );
	wp_set_current_user( 0 );
	$ok( 'R70: en la ficha, una novedad de ese negocio no se guarda con otro comercio de dueño', ! array_key_exists( 'user_id', $limpio ) && $avisos );

	// --- R71: un negocio de la administración de la app no la delata ---
	$de_admin = $negocio( array( 'meta_input' => array( 'user_id' => $admin_app ) ) );
	$ok( 'R71: includesUser de un negocio de la administración: user null', null === $api( "/business/$de_admin", array( 'includesUser' => 1 ) )->get_data()['data']['user'] );

	// --- R2: listas de usuarios y medios cerradas a quien no ha entrado ---
	wp_set_current_user( 0 );
	$rutas = rest_get_server()->get_routes();
	$ok( '/wp/v2/users cerrada', ! array_key_exists( '/wp/v2/users', $rutas ) );
	$ok( '/wp/v2/media cerrada', ! array_key_exists( '/wp/v2/media', $rutas ) );
	$ok( 'XML-RPC apagado', ! apply_filters( 'xmlrpc_enabled', true ) && array() === apply_filters( 'xmlrpc_methods', array( 'x' => 'y' ) ) );
	$ok( 'mapa del sitio apagado', ! wp_sitemaps_get_server()->sitemaps_enabled() );
	$ok( 'ruta oEmbed cerrada', ! array_key_exists( '/oembed/1.0/embed', $rutas ) );
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

// Limpieza, elemento a elemento: los tipos de la app no son públicos y no salen en búsquedas genéricas (R13).
clean_comment_cache( $creados['comentarios'] );
$quedan = array_merge(
	array_filter( $creados['entradas'], static fn( $id ) => null !== get_post( $id ) ),
	array_filter( $creados['comentarios'], static fn( $id ) => null !== get_comment( $id ) ),
	array_filter( $creados['usuarios'], static fn( $id ) => false !== get_userdata( $id ) )
);
$ok( sprintf( 'limpieza: borrados %d entradas, %d comentarios y %d cuentas temporales', count( $creados['entradas'] ), count( $creados['comentarios'] ), count( $creados['usuarios'] ) ), ! $quedan && count( $creados['usuarios'] ) >= 2 );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

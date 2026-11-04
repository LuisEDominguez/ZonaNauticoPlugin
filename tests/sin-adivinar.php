<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// R50 y R109: con sesión pero sin permiso, un id que no existe responde lo mismo (401 «This action is unauthorized.»)
// que uno que existe y no es suyo (otra cuenta, un borrador ajeno, una reseña oculta, una pregunta sin publicar). Si
// no, el 404 deja adivinar qué ids existen. La administración de la app, que lo ve todo, sigue recibiendo 404.
// Uso: wp eval-file tests/sin-adivinar.php. Crea cuentas, un negocio, una novedad, un sorteo, una reseña y una pregunta
// «temporal-adivinar» y los borra al terminar.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

$cuentas  = array();
$entradas = array();
$cuenta   = static function ( string $rol_wp ) use ( &$cuentas ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-adivinar';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => 'Temporal' ) );
	update_user_meta( $id, 'zn_verificado', '1' );
	$cuentas[] = $id;
	return $id;
};
$llamar = static function ( string $metodo, string $ruta, array $datos = array(), int $quien = 0 ) {
	wp_set_current_user( $quien );
	$peticion = new WP_REST_Request( $metodo, '/' . ZN_NAMESPACE . $ruta );
	if ( $datos ) {
		$peticion->set_header( 'Content-Type', 'application/json' );
		$peticion->set_body( wp_json_encode( $datos ) );
	}
	$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $peticion ) ), rest_get_server(), $peticion );
	wp_set_current_user( 0 );
	return array( $respuesta->get_status(), $respuesta->get_data() );
};
$sin_permiso = array( 'message' => 'This action is unauthorized.' );
$no_existe   = 999999999;

try {
	$admin_app = $cuenta( 'zn_administrador' );
	$comercio  = $cuenta( 'zn_comercio' );
	$otro_com  = $cuenta( 'zn_comercio' );
	$ana       = $cuenta( 'zn_usuario' );
	$luis      = $cuenta( 'zn_usuario' );
	$wp_admin  = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );

	// Lo que existe y no es suyo: un negocio y, de él, una novedad y un sorteo en borrador, una reseña oculta y una pregunta
	// sin publicar.
	$tienda     = ZN_Esquema::guardar( 'negocio', null, array( 'post_title' => 'Negocio temporal-adivinar', 'user_id' => $otro_com ), array( 'post_status' => 'publish' ) );
	$entradas[] = $tienda;
	$borrador   = array();
	foreach ( array( 'event' => ZN_Eventos::TIPO, 'draw' => ZN_Sorteos::TIPO ) as $ruta => $tipo ) {
		$id                = wp_insert_post( array( 'post_type' => $tipo, 'post_status' => 'draft', 'post_title' => "Borrador temporal-adivinar ($ruta)" ) );
		$entradas[]        = $id;
		$borrador[ $ruta ] = $id;
		update_post_meta( $id, 'user_id', (string) $otro_com );
		update_post_meta( $id, 'business_id', (string) $tienda );
	}
	$oculta   = wp_insert_comment( array( 'comment_type' => ZN_Comentarios::RESENA, 'comment_post_ID' => $tienda, 'user_id' => $luis, 'comment_approved' => 0, 'comment_content' => 'Reseña temporal-adivinar' ) );
	$pregunta = wp_insert_comment( array( 'comment_type' => ZN_Comentarios::PREGUNTA, 'comment_post_ID' => $tienda, 'user_id' => $luis, 'comment_approved' => 0, 'comment_content' => '¿Pregunta temporal-adivinar?' ) );
	$ok( 'datos de prueba creados', $tienda && $borrador['event'] && $borrador['draw'] && $oculta && $pregunta );

	// Cada caso: [método, ruta de algo que existe y no es suyo, ruta de algo que no existe, quién, cuerpo].
	$casos = array(
		'GET /user/{id}'          => array( 'GET', "/user/$luis", "/user/$no_existe", $ana, array() ),
		'PATCH /user/{id}'        => array( 'PATCH', "/user/$luis", "/user/$no_existe", $ana, array( 'name' => 'X' ) ),
		'DELETE /user/{id}'       => array( 'DELETE', "/user/$luis", "/user/$no_existe", $ana, array( 'currentPassword' => 'x' ) ),
		'PATCH /event/{id}'       => array( 'PATCH', "/event/{$borrador['event']}", "/event/$no_existe", $comercio, array( 'name' => 'X' ) ),
		'DELETE /event/{id}'      => array( 'DELETE', "/event/{$borrador['event']}", "/event/$no_existe", $comercio, array() ),
		'PATCH /draw/{id}'        => array( 'PATCH', "/draw/{$borrador['draw']}", "/draw/$no_existe", $comercio, array( 'title' => 'X' ) ),
		'DELETE /draw/{id}'       => array( 'DELETE', "/draw/{$borrador['draw']}", "/draw/$no_existe", $comercio, array() ),
		'DELETE /review/{id}'     => array( 'DELETE', "/review/$oculta", "/review/$no_existe", $ana, array() ),
		'PATCH /review/reply/{id}' => array( 'PATCH', "/review/reply/$oculta", "/review/reply/$no_existe", $comercio, array( 'response' => 'x' ) ),
		'PATCH /faq/{id}'         => array( 'PATCH', "/faq/$pregunta", "/faq/$no_existe", $ana, array( 'answer' => 'x' ) ),
	);
	foreach ( $casos as $nombre => list( $metodo, $ajena, $inexistente, $quien, $cuerpo ) ) {
		$de_otro = $llamar( $metodo, $ajena, $cuerpo, $quien );
		$nadie   = $llamar( $metodo, $inexistente, $cuerpo, $quien );
		$ok( "$nombre: lo ajeno, 401", 401 === $de_otro[0] && $sin_permiso === $de_otro[1] );
		$ok( "$nombre: lo que no existe, el mismo 401 (no se adivina)", $de_otro === $nadie );
	}
	// Ids de otro tipo: tampoco se distinguen.
	$ok( 'PATCH /event con el id de un negocio: 401', array( 401, $sin_permiso ) === $llamar( 'PATCH', "/event/$tienda", array( 'name' => 'X' ), $comercio ) );
	$ok( 'PATCH /faq con el id de una reseña: 401', array( 401, $sin_permiso ) === $llamar( 'PATCH', "/faq/$oculta", array( 'answer' => 'x' ), $ana ) );
	if ( $wp_admin ) {
		$ok( 'GET /user de una cuenta de wp-admin (no es de la app): 401, igual que una que no existe', array( 401, $sin_permiso ) === $llamar( 'GET', "/user/$wp_admin", array(), $ana ) );
	}

	// La administración de la app lo ve todo: a ella el 404 no le descubre nada.
	foreach ( array( "/user/$no_existe" => 'GET', "/event/$no_existe" => 'PATCH', "/draw/$tienda" => 'PATCH', "/review/$no_existe" => 'DELETE', "/review/reply/$no_existe" => 'PATCH', "/faq/$no_existe" => 'PATCH' ) as $ruta => $metodo ) {
		$ok( "administración de la app: $metodo $ruta que no existe → 404", 404 === $llamar( $metodo, $ruta, array( 'name' => 'X', 'title' => 'X', 'response' => 'x', 'answer' => 'x' ), $admin_app )[0] );
	}
	// En PATCH y DELETE /user solo vale la propia cuenta: ni a la administración se le dice si otra existe.
	$ok( 'administración de la app: PATCH /user de una que no existe → 401 (solo la propia)', 401 === $llamar( 'PATCH', "/user/$no_existe", array( 'name' => 'X' ), $admin_app )[0] );
	$ok( 'administración de la app: DELETE /user de una que no existe → 401 (solo la propia)', 401 === $llamar( 'DELETE', "/user/$no_existe", array( 'currentPassword' => 'x' ), $admin_app )[0] );

	// Lo suyo sigue igual: el dueño edita su borrador.
	$ok( 'el dueño sí edita su borrador (200)', 200 === $llamar( 'PATCH', "/event/{$borrador['event']}", array( 'name' => 'Borrador temporal-adivinar (editado)' ), $otro_com )[0] );
	$ok( 'y la propia cuenta, sus datos (200)', 200 === $llamar( 'GET', "/user/$ana", array(), $ana )[0] );
} finally {
	wp_set_current_user( 0 );
	global $wpdb;
	foreach ( $cuentas as $id ) {
		$u = get_userdata( $id );
		foreach ( array( 'escritura', 'datos' ) as $tipo ) {
			foreach ( $u ? array_keys( ZN_Limite::claves( $tipo, $u->user_email ) ) : array() as $clave ) {
				$wpdb->delete( ZN_Limite::tabla(), array( 'clave' => $clave ) );
			}
		}
	}
	foreach ( array_unique( $entradas ) as $entrada ) {
		if ( $entrada && get_post( $entrada ) ) {
			wp_delete_post( $entrada, true );
		}
	}
	foreach ( $cuentas as $id ) {
		if ( get_userdata( $id ) ) {
			wp_delete_user( $id );
		}
	}
}
$quedan = array_filter( $entradas, static fn( $id ) => $id && get_post( $id ) );
$ok( sprintf( 'limpieza: borradas %d entradas y %d cuentas', count( array_unique( $entradas ) ), count( $cuentas ) ), ! $quedan && ! array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

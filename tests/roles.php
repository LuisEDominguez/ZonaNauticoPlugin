<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Roles de la app y permisos (R22, R35, R36, R37). Uso: wp eval-file tests/roles.php
// Crea cuentas, un negocio, una novedad y comentarios temporales («temporal-roles») y los borra al terminar.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

// Contraseña al azar en cada ejecución (R61), nunca una fija.
$clave   = wp_generate_password( 24 );
$creados = array( 'usuarios' => array(), 'entradas' => array(), 'comentarios' => array() );
$cuenta  = static function ( string $rol_wp, int $padre = 0 ) use ( &$creados, $clave ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $creados['usuarios'] ) . '-temporal-roles';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => $clave, 'role' => $rol_wp, 'first_name' => 'Temporal' ) );
	if ( $padre ) {
		update_user_meta( $id, 'zn_parent_id', $padre );
	}
	$creados['usuarios'][] = $id;
	return $id;
};
$entrada = static function ( string $tipo, int $dueno ) use ( &$creados ) {
	$id                    = wp_insert_post( array( 'post_type' => $tipo, 'post_status' => 'publish', 'post_title' => 'Temporal roles', 'meta_input' => array( 'user_id' => $dueno ) ) );
	$creados['entradas'][] = $id;
	return get_post( $id );
};
$comentario = static function ( int $negocio, string $tipo, array $datos ) use ( &$creados ) {
	$id                       = wp_insert_comment( $datos + array( 'comment_post_ID' => $negocio, 'comment_type' => $tipo, 'comment_approved' => 1, 'comment_author' => 'Temporal roles' ) );
	$creados['comentarios'][] = $id;
	return get_comment( $id );
};
$puede = static fn( int $quien, string $permiso, $elemento = null ) => ZN_Roles::puede( $permiso, $elemento, $quien );

try {
	// --- Los 5 roles, con los permisos del Laravel y ninguno de WordPress ---
	$esperados = array( 'administrator' => 51, 'federation' => 21, 'association' => 27, 'commerce' => 22, 'user' => 13 );
	foreach ( ZN_Roles::ROLES_APP as $rol_wp => $rol_app ) {
		$rol  = get_role( $rol_wp );
		$caps = array_keys( array_filter( $rol ? $rol->capabilities : array() ) );
		$ok( "$rol_wp: los {$esperados[ $rol_app ]} permisos de «{$rol_app}» del Laravel", count( $caps ) === $esperados[ $rol_app ] && ! array_diff( $caps, ZN_Roles::permisos( $rol_app ) ) );
		$ok( "$rol_wp: ningún permiso de WordPress (ni read)", ! array_filter( $caps, static fn( $c ) => 0 !== strpos( $c, 'zn:' ) ) );
	}

	// --- R35: poner al día los roles no los borra; R60: el rol por defecto nunca es de la app ---
	$por_defecto = get_option( 'default_role' );
	update_option( 'default_role', 'zn_usuario' );
	$ok( 'R60: el rol por defecto no se puede poner a un rol de la app', 'zn_usuario' !== get_option( 'default_role' ) );
	$ok( 'R60: nadie se registra por wp-login.php (users_can_register = 0)', ! get_option( 'users_can_register' ) );
	$con_rol = $cuenta( 'zn_usuario' );
	get_role( 'zn_usuario' )->add_cap( 'zn:sobra' );
	get_role( 'zn_usuario' )->remove_cap( 'zn:review:read' );
	delete_option( 'zn_roles_version' );
	ZN_Roles::actualizar();
	$caps = get_role( 'zn_usuario' )->capabilities;
	$ok( 'R35: quita los permisos que sobran y añade los que faltan', empty( $caps['zn:sobra'] ) && ! empty( $caps['zn:review:read'] ) );
	$ok( 'R35: las cuentas conservan su rol tras poner al día los roles', 'user' === ZN_Roles::rol_app( get_userdata( $con_rol ) ) );
	$ok( 'R35: el rol por defecto no cambia al poner al día los roles', $por_defecto === get_option( 'default_role' ) );

	// --- La API enseña el rol de la app de cada cuenta ---
	foreach ( ZN_Roles::ROLES_APP as $rol_wp => $rol_app ) {
		$publico = ZN_API::usuario_publico( $cuenta( $rol_wp ) );
		if ( 'administrator' === $rol_app ) {
			$ok( 'R71: una cuenta de administración de la app no sale en la API (user null)', null === $publico );
		} else {
			$ok( "usuario público de $rol_wp: role «{$rol_app}»", $rol_app === ( $publico['role'] ?? null ) );
		}
	}

	// --- Cuentas y elementos de prueba ---
	$federacion = $cuenta( 'zn_federacion' );
	$asociacion = $cuenta( 'zn_asociacion', $federacion );
	$comercio   = $cuenta( 'zn_comercio', $asociacion );
	$otro       = $cuenta( 'zn_comercio' );
	$usuario    = $cuenta( 'zn_usuario' );
	$hijo       = $cuenta( 'zn_usuario', $asociacion );
	$admin_app  = $cuenta( 'zn_administrador' );

	$negocio       = $entrada( ZN_Negocios::TIPO, $comercio );
	$negocio_otro  = $entrada( ZN_Negocios::TIPO, $otro );
	$sin_dueno     = $entrada( ZN_Negocios::TIPO, 0 );
	$novedad_asoc  = $entrada( ZN_Eventos::TIPO, $asociacion );
	$novedad_com   = $entrada( ZN_Eventos::TIPO, $comercio );
	$resena_hijo   = $comentario( $negocio->ID, ZN_Comentarios::RESENA, array( 'user_id' => $hijo, 'comment_meta' => array( 'score' => 4 ) ) );
	$resena_propia = $comentario( $negocio_otro->ID, ZN_Comentarios::RESENA, array( 'user_id' => $usuario, 'comment_meta' => array( 'score' => 4 ) ) );
	$pregunta      = $comentario( $negocio->ID, ZN_Comentarios::PREGUNTA, array( 'user_id' => $usuario, 'comment_meta' => array( 'user_id' => $comercio ) ) );

	// --- «Lo suyo», con el dueño sacado del elemento guardado ---
	$ok( 'comercio: edita su negocio (business:save_own)', $puede( $comercio, 'business:save', $negocio ) );
	$ok( 'comercio: no el de otro comercio', ! $puede( $comercio, 'business:save', $negocio_otro ) );
	$ok( 'comercio: no uno sin dueño', ! $puede( $comercio, 'business:save', $sin_dueno ) );
	$ok( 'comercio: sin elemento solo vale el permiso general (no tiene business:save)', ! $puede( $comercio, 'business:save' ) );
	$ok( 'asociación: edita el negocio de un comercio suyo (hereda en business)', $puede( $asociacion, 'business:save', $negocio ) );
	$ok( 'asociación: no el de un comercio ajeno', ! $puede( $asociacion, 'business:save', $negocio_otro ) );
	$ok( 'federación: no tiene permiso de negocios (como en el Laravel)', ! $puede( $federacion, 'business:save', $negocio ) );
	$ok( 'federación: edita una novedad de su asociación (hereda en event)', $puede( $federacion, 'event:save', $novedad_asoc ) );
	$ok( 'federación: un nivel solo (no la de un comercio de su asociación)', ! $puede( $federacion, 'event:save', $novedad_com ) );
	$ok( 'asociación: gestiona las preguntas de su comercio (hereda en faq)', $puede( $asociacion, 'faq:save', $pregunta ) );
	$ok( 'comercio: gestiona las preguntas que le hacen (faq:save_own por destinatario)', $puede( $comercio, 'faq:delete', $pregunta ) );
	$ok( 'administración de la app: cualquiera', $puede( $admin_app, 'business:save', $negocio_otro ) );

	// --- R41: sobre un elemento, el permiso general solo vale para la administración (y lecturas públicas) ---
	$ok( 'R41: un usuario (tiene faq:save) no puede tocar una pregunta ajena', ! $puede( $usuario, 'faq:save', $pregunta ) );
	$ok( 'R41: la federación (faq:save, sin faq:save_own) tampoco', ! $puede( $federacion, 'faq:save', $pregunta ) );
	$ok( 'R41: un comercio no toca las preguntas de otro comercio', ! $puede( $otro, 'faq:save', $pregunta ) );
	$ok( 'R41: la administración de la app sí', $puede( $admin_app, 'faq:save', $pregunta ) );
	$ok( 'R41: campos: el comercio destinatario cambia pregunta, respuesta y publicación', array( 'question', 'answer', 'published' ) === ZN_Roles::campos_editables_de_pregunta( $pregunta, $comercio ) );
	$ok( 'R41: campos: su asociación, también', array( 'question', 'answer', 'published' ) === ZN_Roles::campos_editables_de_pregunta( $pregunta, $asociacion ) );
	$ok( 'R41: campos: quien preguntó, solo el texto de la pregunta', array( 'question' ) === ZN_Roles::campos_editables_de_pregunta( $pregunta, $usuario ) );
	$ok( 'R41: campos: cualquier otro, ninguno', array() === ZN_Roles::campos_editables_de_pregunta( $pregunta, $hijo ) && array() === ZN_Roles::campos_editables_de_pregunta( $pregunta, $federacion ) );
	$ok( 'R41: lectura pública: cualquiera lee un negocio', $puede( $usuario, 'business:read', $negocio_otro ) );
	$ok( 'R41/R43: un usuario no lee participaciones con el permiso general', ! $puede( $usuario, 'draw_participation:read', $negocio_otro ) );

	// --- R45: participantes de un sorteo: la administración y quien lo organiza ---
	$sorteo_com  = $entrada( ZN_Sorteos::TIPO, $comercio );
	$sorteo_asoc = $entrada( ZN_Sorteos::TIPO, $asociacion );
	$ok( 'R45: el comercio organizador ve sus participantes', $puede( $comercio, 'draw_participation:read', $sorteo_com ) );
	$ok( 'R45: el comercio organizador los exporta (export_own)', $puede( $comercio, 'draw_participation:export', $sorteo_com ) );
	$ok( 'R45: la asociación organizadora los ve y exporta', $puede( $asociacion, 'draw_participation:read', $sorteo_asoc ) && $puede( $asociacion, 'draw_participation:export', $sorteo_asoc ) );
	$ok( 'R45: un comercio ajeno no los ve ni exporta', ! $puede( $otro, 'draw_participation:read', $sorteo_com ) && ! $puede( $otro, 'draw_participation:export', $sorteo_com ) );
	$ok( 'R45: un usuario no los ve', ! $puede( $usuario, 'draw_participation:read', $sorteo_com ) );
	$ok( 'R45: la asociación no ve los del sorteo de su comercio (sin herencia)', ! $puede( $asociacion, 'draw_participation:read', $sorteo_com ) );
	$ok( 'R45: la federación (export general) no exporta un sorteo ajeno', ! $puede( $federacion, 'draw_participation:export', $sorteo_asoc ) );
	$ok( 'R45: la administración de la app, todos', $puede( $admin_app, 'draw_participation:read', $sorteo_com ) && $puede( $admin_app, 'draw_participation:export', $sorteo_asoc ) );

	// --- R42: crear a su nombre (o de una hija), nunca a nombre de otro ---
	$crear = static fn( int $quien, string $permiso, int $dueno ) => ZN_Roles::puede_crear( $permiso, $dueno, $quien );
	$ok( 'R42: el comercio crea un negocio a su nombre (business:save_own)', $crear( $comercio, 'business:save', $comercio ) );
	$ok( 'R42: el comercio no lo crea a nombre de otro', ! $crear( $comercio, 'business:save', $otro ) );
	$ok( 'R42: la asociación lo crea a nombre de su comercio', $crear( $asociacion, 'business:save', $comercio ) );
	$ok( 'R42: la asociación no lo crea a nombre de un comercio ajeno', ! $crear( $asociacion, 'business:save', $otro ) );
	$ok( 'R42: el usuario escribe una reseña a su nombre (review:save_own)', $crear( $usuario, 'review:save', $usuario ) );
	$ok( 'R42: pero no a nombre de otro', ! $crear( $usuario, 'review:save', $hijo ) );
	$ok( 'R42: el usuario participa en un sorteo (draw_participation:save_own)', $crear( $usuario, 'draw_participation:save', $usuario ) );
	$ok( 'R42: el usuario hace una pregunta (faq:save)', $crear( $usuario, 'faq:save', $usuario ) );
	$ok( 'R42: la asociación no participa en nombre de su usuario hijo (sin herencia)', ! $crear( $asociacion, 'draw_participation:save', $hijo ) );
	$ok( 'R42: el usuario no crea negocios', ! $crear( $usuario, 'business:save', $usuario ) );
	$ok( 'R42: la administración de la app crea a nombre de cualquiera', $crear( $admin_app, 'business:save', $otro ) );
	$ok( 'R42: sin dueño, nadie', ! $crear( $admin_app, 'business:save', 0 ) );

	// --- R36: sin herencia donde el Laravel no la tiene ---
	$ok( 'R36: una asociación no borra las reseñas de una cuenta suya (review sin herencia)', ! $puede( $asociacion, 'review:delete', $resena_hijo ) );
	$ok( 'R36: el usuario borra la suya (review:delete_own)', $puede( $usuario, 'review:delete', $resena_propia ) );
	$ok( 'R36: el comercio responde reseñas de su negocio', $puede( $comercio, 'review:reply', $resena_hijo ) );
	$ok( 'R36: la asociación no responde reseñas del comercio hijo (como el Laravel)', ! $puede( $asociacion, 'review:reply', $resena_hijo ) );
	$ok( 'R36: el comercio no responde reseñas de otro negocio', ! $puede( $comercio, 'review:reply', $resena_propia ) );

	// --- R36: cuentas: «lo suyo» es solo uno mismo; las hijas, con el permiso de su rol ---
	$ok( 'R36: cada uno edita su cuenta (user:save_own)', $puede( $comercio, 'user:save', get_userdata( $comercio ) ) );
	$ok( 'R36: el usuario puede borrar su cuenta (user:delete_self)', $puede( $usuario, 'user:delete', get_userdata( $usuario ) ) );
	$ok( 'R36: el comercio no puede borrar su cuenta (no tiene user:delete_self)', ! $puede( $comercio, 'user:delete', get_userdata( $comercio ) ) );
	$ok( 'R36: un comercio no edita la cuenta de un usuario que cuelgue de él', ! $puede( $comercio, 'user:save', get_userdata( $hijo ) ) );
	$ok( 'R36: la asociación edita su comercio hijo (user:save_commerce)', $puede( $asociacion, 'user:save', get_userdata( $comercio ) ) );
	$ok( 'R36: la asociación edita su usuario hijo (user:save_user)', $puede( $asociacion, 'user:save', get_userdata( $hijo ) ) );
	$ok( 'R36: la asociación no edita un comercio que no cuelga de ella', ! $puede( $asociacion, 'user:save', get_userdata( $otro ) ) );
	$ok( 'R36: la asociación no edita a su federación', ! $puede( $asociacion, 'user:save', get_userdata( $federacion ) ) );
	$ok( 'R36: la federación edita su asociación (user:save_association)', $puede( $federacion, 'user:save', get_userdata( $asociacion ) ) );
	$ok( 'R36: la federación no edita el comercio de su asociación (no tiene user:save_commerce)', ! $puede( $federacion, 'user:save', get_userdata( $comercio ) ) );
	$ok( 'R36: la asociación borra su comercio hijo (user:delete_commerce)', $puede( $asociacion, 'user:delete', get_userdata( $comercio ) ) );
	$ok( 'R36: la asociación no borra su usuario hijo (no tiene user:delete_user)', ! $puede( $asociacion, 'user:delete', get_userdata( $hijo ) ) );

	// --- R37: un solo rol ---
	$mixta = $cuenta( 'zn_usuario' );
	( new WP_User( $mixta ) )->add_role( 'zn_administrador' );
	$ok( 'R37: con dos roles de la app no es cuenta de la app', null === ZN_Roles::rol_app( get_userdata( $mixta ) ) );
	$ok( 'R37: y no tiene ningún permiso (ni de admin ni de usuario)', ! $puede( $mixta, 'business:save', $negocio_otro ) && ! $puede( $mixta, 'review:read' ) );
	$ok( 'R37: no sale en la API', null === ZN_API::usuario_publico( $mixta ) );
	$con_wp = $cuenta( 'zn_comercio' );
	( new WP_User( $con_wp ) )->add_role( 'editor' );
	$ok( 'R37: un rol de la app junto a uno de WordPress tampoco cuenta', null === ZN_Roles::rol_app( get_userdata( $con_wp ) ) && ! $puede( $con_wp, 'business:save', $negocio ) );
	$ok( 'R37: pero sigue sin poder entrar por wp-login.php', is_wp_error( wp_authenticate( get_userdata( $con_wp )->user_login, $clave ) ) );

	// --- Ni administradores de WordPress ni permisos de wp-admin ---
	$wp_admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0];
	$ok( 'un administrador de WordPress no tiene permisos de la app', ! $puede( (int) $wp_admin, 'business:save', $negocio ) );
	$ok( 'una cuenta de la app no tiene permisos de wp-admin', ! user_can( $admin_app, 'edit_zn_negocios' ) && ! user_can( $admin_app, 'read' ) );

	// --- Sin wp-login.php para las cuentas de la app ---
	$login     = get_userdata( $comercio )->user_login;
	$buena     = wp_authenticate( $login, $clave );
	$mala      = wp_authenticate( $login, 'mala' );
	$no_existe = wp_authenticate( 'no-existe-temporal-roles', 'mala' );
	$ok( 'cuenta de la app con su contraseña buena: wp-login.php la rechaza', is_wp_error( $buena ) );
	$avisos = array_map( static fn( $e ) => apply_filters( 'login_errors', $e->get_error_message() ), array( $buena, $mala, $no_existe ) );
	$ok( 'wp-login.php: mismo aviso para contraseña buena, mala y cuenta inexistente', 1 === count( array_unique( $avisos ) ) );
	$ok( 'sin recuperar contraseña desde wp-login.php', ! apply_filters( 'allow_password_reset', true, $comercio ) );
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
clean_comment_cache( $creados['comentarios'] );
$quedan = array_merge(
	array_filter( $creados['usuarios'], static fn( $id ) => false !== get_userdata( $id ) ),
	array_filter( $creados['entradas'], static fn( $id ) => null !== get_post( $id ) ),
	array_filter( $creados['comentarios'], static fn( $id ) => null !== get_comment( $id ) )
);
$ok( sprintf( 'limpieza: borradas %d cuentas, %d entradas y %d comentarios temporales', count( $creados['usuarios'] ), count( $creados['entradas'] ), count( $creados['comentarios'] ) ), ! $quedan );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

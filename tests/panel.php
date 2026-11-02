<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Panel de wp-admin para asociación y federación, parte 1: entrar y solo ver lo suyo (R40).
// Uso: wp eval-file tests/panel.php
// Crea cuentas y entradas temporales («temporal-panel») y las borra al terminar, con sus intentos de entrada.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
// IP de documentación fija, para que cuenten las reglas por cuenta e IP del límite.
$ip_antes               = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '192.0.2.77';
$clave   = wp_generate_password( 24 );
$creados = array( 'usuarios' => array(), 'entradas' => array() );
$claves  = array();
$cuenta  = static function ( string $rol_wp, int $padre = 0, bool $verificada = true, string $nombre = '' ) use ( &$creados, &$claves, $clave ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $creados['usuarios'] ) . '-temporal-panel';
	// Como las cuentas de /register: display_name es un nombre interno al azar; el nombre, en first_name.
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => $clave, 'role' => $rol_wp, 'display_name' => 'znInterno' . count( $creados['usuarios'] ), 'first_name' => $nombre ) );
	if ( $padre ) {
		update_user_meta( $id, 'zn_parent_id', $padre );
	}
	if ( $verificada ) {
		update_user_meta( $id, 'zn_verificado', '1' );
	}
	$creados['usuarios'][] = $id;
	$claves                = array_merge( $claves, array_keys( ZN_Limite::claves( 'login', "$login@ejemplo.invalid" ) ) );
	return $id;
};
$entrada = static function ( string $tipo, int $dueno, string $titulo, string $estado = 'publish' ) use ( &$creados ) {
	$id                    = wp_insert_post( array( 'post_type' => $tipo, 'post_status' => $estado, 'post_title' => $titulo, 'meta_input' => array( 'user_id' => $dueno ) ) );
	$creados['entradas'][] = $id;
	return $id;
};
$ids    = static fn( array $lista ) => array_map( static fn( $e ) => (int) $e->ID, $lista );
$pintar = static function ( int $quien ) {
	wp_set_current_user( $quien );
	ob_start();
	ZN_Panel::pintar();
	$html = (string) ob_get_clean();
	wp_set_current_user( 0 );
	return $html;
};

try {
	// --- Roles: asociación y federación entran a wp-admin; el resto, no ---
	foreach ( ZN_Roles::ROLES_APP as $rol_wp => $rol_app ) {
		$caps  = array_keys( array_filter( get_role( $rol_wp )->capabilities ) );
		$de_wp = array_values( array_filter( $caps, static fn( $c ) => 0 !== strpos( $c, 'zn:' ) ) );
		sort( $de_wp );
		if ( in_array( $rol_wp, ZN_Roles::ROLES_PANEL, true ) ) {
			$ok( "$rol_wp: de WordPress solo read y zn_panel_ver", array( 'read', 'zn_panel_ver' ) === $de_wp );
		} else {
			$ok( "$rol_wp: ningún permiso de WordPress (ni read)", array() === $de_wp );
		}
	}

	// --- Cuentas y elementos de prueba ---
	$federacion = $cuenta( 'zn_federacion', 0, true, 'Federación temporal' );
	$asociacion = $cuenta( 'zn_asociacion', $federacion, true, 'Asociación temporal' );
	$comercio   = $cuenta( 'zn_comercio', $asociacion, true, 'Comercio hijo temporal' );
	$usuario    = $cuenta( 'zn_usuario', $asociacion, true, 'Usuario hijo temporal' );
	$ajeno      = $cuenta( 'zn_comercio', 0, true, 'Comercio ajeno temporal' );
	$otra_asoc  = $cuenta( 'zn_asociacion', 0, true, 'Otra asociación temporal' );
	$sin_verif  = $cuenta( 'zn_asociacion', 0, false );
	$admin_app  = $cuenta( 'zn_administrador' );

	$neg_hijo    = $entrada( ZN_Negocios::TIPO, $comercio, 'Negocio del hijo <script>alert(1)</script>' );
	$neg_borr    = $entrada( ZN_Negocios::TIPO, $comercio, 'Negocio borrador del hijo', 'draft' );
	$neg_papel   = $entrada( ZN_Negocios::TIPO, $comercio, 'Negocio en la papelera', 'trash' );
	$neg_ajeno   = $entrada( ZN_Negocios::TIPO, $ajeno, 'Negocio ajeno temporal' );
	$nov_asoc    = $entrada( ZN_Eventos::TIPO, $asociacion, 'Novedad de la asociación' );
	$nov_hijo    = $entrada( ZN_Eventos::TIPO, $comercio, 'Novedad del hijo' );
	$nov_ajena   = $entrada( ZN_Eventos::TIPO, $otra_asoc, 'Novedad de otra asociación' );
	$sor_asoc    = $entrada( ZN_Sorteos::TIPO, $asociacion, 'Sorteo de la asociación' );
	$sor_hijo    = $entrada( ZN_Sorteos::TIPO, $comercio, 'Sorteo del hijo' );
	$sor_ajeno   = $entrada( ZN_Sorteos::TIPO, $ajeno, 'Sorteo ajeno temporal' );
	$sor_federac = $entrada( ZN_Sorteos::TIPO, $federacion, 'Sorteo de la federación' );

	// --- Quién entra por wp-login.php ---
	$entra = static fn( int $id ) => wp_authenticate( get_userdata( $id )->user_email, $clave );
	$ok( 'R40: la asociación verificada entra con su contraseña', $entra( $asociacion ) instanceof WP_User );
	$ok( 'R40: la federación verificada entra con su contraseña', $entra( $federacion ) instanceof WP_User );
	$ok( 'R40: también con su nombre de acceso', wp_authenticate( get_userdata( $asociacion )->user_login, $clave ) instanceof WP_User );
	$ok( 'R40: una asociación sin verificar, no', is_wp_error( $entra( $sin_verif ) ) );
	$ok( 'R40: un comercio, no', is_wp_error( $entra( $comercio ) ) );
	$ok( 'R40: un usuario, no', is_wp_error( $entra( $usuario ) ) );
	$ok( 'R40: la administración de la app, no', is_wp_error( $entra( $admin_app ) ) );
	$mixta = $cuenta( 'zn_asociacion' );
	( new WP_User( $mixta ) )->add_role( 'zn_comercio' );
	$ok( 'R40: una asociación con otro rol más, no (R37)', is_wp_error( $entra( $mixta ) ) && ! ZN_Panel::puede_entrar( get_userdata( $mixta ) ) );
	$ok( 'R40: contraseña mala, no', is_wp_error( wp_authenticate( get_userdata( $otra_asoc )->user_email, 'mala' ) ) );
	$avisos = array_map(
		static fn( $e ) => apply_filters( 'login_errors', $e->get_error_message() ),
		array( $entra( $sin_verif ), $entra( $comercio ), wp_authenticate( get_userdata( $otra_asoc )->user_email, 'mala' ), wp_authenticate( 'no-existe-temporal-panel', 'mala' ) )
	);
	$ok( 'R40: sin verificar, otro rol, contraseña mala y cuenta inexistente dan el mismo aviso', 1 === count( array_unique( $avisos ) ) );
	$ok( 'R40: sin recuperar contraseña desde wp-login.php (se hace en la app)', ! apply_filters( 'allow_password_reset', true, $asociacion ) );

	// --- Límite de intentos (el mismo que el login de la app) ---
	for ( $i = 0; $i < 5; $i++ ) {
		wp_authenticate( get_userdata( $otra_asoc )->user_email, 'mala' );
	}
	$ok( 'R40: tras 5 intentos malos, ni con la contraseña buena', is_wp_error( $entra( $otra_asoc ) ) );
	$ok( 'R40: …y el aviso es el de siempre', ZN_Puertas::AVISO_ACCESO === apply_filters( 'login_errors', $entra( $otra_asoc )->get_error_message() ) );
	$ok( 'R40: los intentos de wp-login.php cuentan también para POST /login', null !== ZN_Limite::intentar( 'login', get_userdata( $otra_asoc )->user_email ) );
	wp_authenticate( get_userdata( $asociacion )->user_email, 'mala' );
	do_action( 'wp_login', get_userdata( $asociacion )->user_login, get_userdata( $asociacion ) );
	$cuenta_ip = (string) ( array_keys( ZN_Limite::claves( 'login', get_userdata( $asociacion )->user_email ) )[0] ?? '' ); // la primera es cuenta+IP
	$ok( 'R40: al entrar bien se olvidan sus intentos (como en la app)', '' !== $cuenta_ip && null === $wpdb->get_var( $wpdb->prepare( 'SELECT intentos FROM ' . ZN_Limite::tabla() . ' WHERE clave = %s', $cuenta_ip ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// --- Si deja de poder entrar, la sesión abierta ya no vale ---
	$ok( 'R40: puede_entrar: asociación y federación verificadas', ZN_Panel::puede_entrar( get_userdata( $asociacion ) ) && ZN_Panel::puede_entrar( get_userdata( $federacion ) ) );
	$cambia = $cuenta( 'zn_asociacion' );
	( new WP_User( $cambia ) )->set_role( 'zn_comercio' );
	$ok( 'R40: pasada a comercio, ya no puede entrar', ! ZN_Panel::puede_entrar( get_userdata( $cambia ) ) );
	$ok( 'R40: ni una cuenta sin verificar', ! ZN_Panel::puede_entrar( get_userdata( $sin_verif ) ) );
	$ok( 'R40: ni un administrador de WordPress (no es del panel)', ! ZN_Panel::puede_entrar( get_userdata( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0] ) ) );

	// --- Solo la página del panel ---
	$ok( 'R40: la página del panel sí', ZN_Panel::pagina_permitida( 'admin.php', array( 'page' => ZN_Panel::PAGINA ) ) );
	foreach ( array( 'index.php', 'profile.php', 'user-edit.php', 'edit.php', 'post.php', 'post-new.php', 'edit-comments.php', 'upload.php', 'options-general.php', 'admin-post.php', 'about.php', 'tools.php' ) as $pagina ) {
		$ok( "R40: $pagina no", ! ZN_Panel::pagina_permitida( $pagina, array() ) );
	}
	$ok( 'R40: otra página de admin.php no', ! ZN_Panel::pagina_permitida( 'admin.php', array( 'page' => 'otra' ) ) );
	$ok( 'R97: la página se mira en la dirección ($_GET), no en el cuerpo', ! ZN_Panel::pagina_permitida( 'admin.php', array( 'page' => 'otra' ), array( 'page' => ZN_Panel::PAGINA ) ) && ! ZN_Panel::pagina_permitida( 'admin.php', array(), array( 'page' => ZN_Panel::PAGINA ) ) );
	$ok( 'R97: con «action» (en la dirección o en el cuerpo), no', ! ZN_Panel::pagina_permitida( 'admin.php', array( 'page' => ZN_Panel::PAGINA, 'action' => 'x' ) ) && ! ZN_Panel::pagina_permitida( 'admin.php', array( 'page' => ZN_Panel::PAGINA ), array( 'action' => 'x' ) ) );
	$ok( 'R40: admin-ajax solo el latido (heartbeat)', ZN_Panel::pagina_permitida( 'admin-ajax.php', array( 'action' => 'heartbeat' ) ) && ! ZN_Panel::pagina_permitida( 'admin-ajax.php', array( 'action' => 'closed-postboxes' ) ) && ! ZN_Panel::pagina_permitida( 'admin-ajax.php', array() ) );
	$ok( 'R40: al entrar va al panel', ZN_Panel::url() === apply_filters( 'login_redirect', admin_url(), '', get_userdata( $asociacion ) ) );
	$wp_admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0];
	$ok( 'R40: un administrador de WordPress sigue yendo a donde iba', admin_url() === apply_filters( 'login_redirect', admin_url(), '', get_userdata( $wp_admin ) ) );

	// --- Sin editar nada: ni su propio perfil ---
	$ok( 'R40: la asociación no puede editar su perfil de WordPress', ! user_can( $asociacion, 'edit_user', $asociacion ) );
	$ok( 'R40: ni la federación el de su asociación', ! user_can( $federacion, 'edit_user', $asociacion ) );
	$ok( 'R40: un administrador de WordPress sí edita su perfil', user_can( $wp_admin, 'edit_user', $wp_admin ) );
	foreach ( array( 'edit_posts', 'edit_zn_negocios', 'edit_zn_eventos', 'edit_zn_sorteos', 'upload_files', 'moderate_comments', 'list_users', 'manage_options' ) as $permiso ) {
		$ok( "R40: la asociación no tiene $permiso", ! user_can( $asociacion, $permiso ) );
	}
	$ok( 'R40: ni puede editar el negocio de su comercio en wp-admin', ! user_can( $asociacion, 'edit_post', $neg_hijo ) && ! user_can( $asociacion, 'delete_post', $neg_hijo ) );
	wp_set_current_user( $asociacion );
	$ok( 'R40: sin barra de administración en la parte pública', ! apply_filters( 'show_admin_bar', true ) );
	require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
	$barra = new WP_Admin_Bar();
	$barra->add_menus();
	do_action_ref_array( 'admin_bar_menu', array( &$barra ) );
	$enlaces = implode( ' ', array_filter( array_map( static fn( $n ) => (string) $n->href, (array) $barra->get_nodes() ) ) );
	$ok( 'R40: la barra de arriba no enlaza al perfil ni al escritorio', false === strpos( $enlaces, 'profile.php' ) && ! $barra->get_node( 'edit-profile' ) && ! $barra->get_node( 'site-name' ) && ! $barra->get_node( 'new-content' ) );
	$ok( 'R40: pero sí deja salir', $barra->get_node( 'logout' ) && false !== strpos( (string) $barra->get_node( 'logout' )->href, 'action=logout' ) );
	wp_set_current_user( 0 );

	// --- R98: entrar en la app no cierra la sesión del panel (sí la de las demás cuentas, R39) ---
	$entrar_app = static function ( int $id ) use ( $clave ) {
		$peticion = new WP_REST_Request( 'POST', '/' . ZN_NAMESPACE . '/login' );
		$peticion->set_header( 'Content-Type', 'application/json' );
		$peticion->set_body( wp_json_encode( array( 'email' => get_userdata( $id )->user_email, 'password' => $clave ) ) );
		return rest_do_request( $peticion )->get_status();
	};
	foreach ( array( 'asociación' => $asociacion, 'federación' => $federacion, 'comercio' => $comercio ) as $quien => $id ) {
		WP_Session_Tokens::get_instance( $id )->create( time() + HOUR_IN_SECONDS );
		$estado = $entrar_app( $id );
		$quedan = count( WP_Session_Tokens::get_instance( $id )->get_all() );
		if ( 'comercio' === $quien ) {
			$ok( 'R98/R39: el comercio entra en la app y se cierran sus sesiones de WordPress', 200 === $estado && 0 === $quedan );
		} else {
			$ok( "R98: la $quien entra en la app y su sesión del panel sigue", 200 === $estado && 1 === $quedan );
		}
	}

	// --- La cookie del panel no vale en la API de la app (allí solo el token) ---
	$servidor = $_SERVER;
	$_SERVER['SCRIPT_NAME'] = '/index.php';
	$_SERVER['REQUEST_URI'] = wp_parse_url( home_url( '/' ), PHP_URL_PATH ) . rest_get_url_prefix() . '/' . ZN_NAMESPACE . '/user/' . $asociacion;
	unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
	$ok( 'R40: en /zonanautico/v1, la cookie de una cuenta de la app no abre sesión', ! ZN_Tokens::usuario_del_token( $asociacion ) );
	$ok( 'R40: la de un administrador de WordPress sigue valiendo', $wp_admin === (int) ZN_Tokens::usuario_del_token( $wp_admin ) );
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
	$ok( 'R40: fuera de la API, la cookie del panel sí vale', $asociacion === (int) ZN_Tokens::usuario_del_token( $asociacion ) );
	$_SERVER = $servidor;

	// --- Qué ve: lo suyo y lo de las cuentas que cuelgan de él (un nivel, como los permisos de la app) ---
	$yo = get_userdata( $asociacion );
	$ok( 'R40: asociación: sus cuentas son su comercio y su usuario', array( $comercio, $usuario ) == array_values( array_intersect( $ids( ZN_Panel::cuentas( $yo ) ), array( $comercio, $usuario ) ) ) && 2 === count( ZN_Panel::cuentas( $yo ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$ok( 'R40: asociación: negocios del comercio hijo, también borradores, sin papelera ni ajenos', array( $neg_borr, $neg_hijo ) == array_values( array_intersect( array( $neg_borr, $neg_hijo ), $ids( ZN_Panel::entradas( $yo, ZN_Negocios::TIPO ) ) ) ) && 2 === count( ZN_Panel::entradas( $yo, ZN_Negocios::TIPO ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$nov = $ids( ZN_Panel::entradas( $yo, ZN_Eventos::TIPO ) );
	$ok( 'R40: asociación: novedades suyas y de su comercio, no de otra', in_array( $nov_asoc, $nov, true ) && in_array( $nov_hijo, $nov, true ) && ! in_array( $nov_ajena, $nov, true ) );
	$sor = $ids( ZN_Panel::entradas( $yo, ZN_Sorteos::TIPO ) );
	$ok( 'R40: asociación: sorteos suyos y de su comercio, no ajenos ni de su federación', array( $sor_asoc, $sor_hijo ) == array_values( array_intersect( array( $sor_asoc, $sor_hijo ), $sor ) ) && 2 === count( $sor ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$fed = get_userdata( $federacion );
	// Luis (02-11): la federación VE dos niveles (sus asociaciones y los comercios de estas), solo para leer.
	$ok( 'R40: federación: ve su asociación y el comercio de esta, no el usuario de la asociación', array( $asociacion, $comercio ) === $ids( ZN_Panel::cuentas( $fed ) ) );
	$sor = $ids( ZN_Panel::entradas( $fed, ZN_Sorteos::TIPO ) );
	$ok( 'R40: federación: sorteos suyos, de su asociación y del comercio de esta, no ajenos', array( $sor_asoc, $sor_hijo, $sor_federac ) == array_values( array_intersect( array( $sor_asoc, $sor_hijo, $sor_federac ), $sor ) ) && 3 === count( $sor ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$neg = $ids( ZN_Panel::entradas( $fed, ZN_Negocios::TIPO ) );
	$ok( 'R40: federación: negocios del comercio de su asociación, sin papelera ni ajenos', 2 === count( $neg ) && ! array_diff( array( $neg_hijo, $neg_borr ), $neg ) );
	$otra_fed  = $cuenta( 'zn_federacion' );
	$asoc_otra = $cuenta( 'zn_asociacion', $otra_fed );
	$ok( 'R40: otra federación no ve nada de esta rama', array( $asoc_otra ) === $ids( ZN_Panel::cuentas( get_userdata( $otra_fed ) ) ) && ! array_intersect( array( $sor_hijo, $sor_asoc ), $ids( ZN_Panel::entradas( get_userdata( $otra_fed ), ZN_Sorteos::TIPO ) ) ) );
	$nieto = $cuenta( 'zn_comercio', $comercio );
	$ok( 'R40: federación: tres niveles abajo, no', ! in_array( $nieto, $ids( ZN_Panel::cuentas( $fed ) ), true ) );
	$ok( 'R40: asociación: sigue viendo un solo nivel', ! in_array( $nieto, $ids( ZN_Panel::cuentas( $yo ) ), true ) );
	$html_fed = $pintar( $federacion );
	$ok( 'Ronda 24 (Luis): la federación no ve el correo de los comercios de sus asociaciones', false === strpos( $html_fed, get_userdata( $comercio )->user_email ) );
	$ok( 'Ronda 24: sí el de su asociación (user:read_association)', false !== strpos( $html_fed, get_userdata( $asociacion )->user_email ) );
	$ok( 'Ronda 24: la asociación sí ve el correo de su comercio (user:read_commerce)', false !== strpos( $pintar( $asociacion ), get_userdata( $comercio )->user_email ) );
	$ok( 'R40: federación: la página dice de quién depende cada comercio', false !== strpos( $html_fed, 'Comercio hijo temporal' ) && false !== strpos( $html_fed, 'Asociación temporal' ) && false === strpos( $html_fed, 'Usuario hijo temporal' ) );
	$ok( 'R40: un comercio no tiene panel (nada)', array() === ZN_Panel::cuentas( get_userdata( $comercio ) ) && array() === ZN_Panel::entradas( get_userdata( $comercio ), ZN_Sorteos::TIPO ) );
	$ok( 'R40: otra asociación no ve nada de esta rama', ! array_intersect( array( $neg_hijo, $neg_borr ), $ids( ZN_Panel::entradas( get_userdata( $otra_asoc ), ZN_Negocios::TIPO ) ) ) && array() === ZN_Panel::cuentas( get_userdata( $otra_asoc ) ) );

	// --- La página: lo suyo, solo para leer ---
	$html = $pintar( $asociacion );
	$ok( 'R40: la página enseña lo de su rama', false !== strpos( $html, 'Comercio hijo temporal' ) && false !== strpos( $html, 'Sorteo del hijo' ) && false !== strpos( $html, 'Novedad de la asociación' ) && false !== strpos( $html, 'Negocio borrador del hijo' ) );
	$ok( 'Detalle R53: nombres de pila, nunca el nombre interno (display_name)', false === strpos( $html, 'znInterno' ) );
	$ok( 'R40: y nada ajeno', false === strpos( $html, 'ajeno temporal' ) && false === strpos( $html, 'Novedad de otra asociación' ) && false === strpos( $html, 'Sorteo de la federación' ) && false === strpos( $html, 'Negocio en la papelera' ) );
	$ok( 'R40: los textos van escapados', false === strpos( $html, '<script>alert(1)</script>' ) && false !== strpos( $html, '&lt;script&gt;' ) );
	$ok( 'R40: la lista no enlaza a pantallas de WordPress ni tiene formularios', ! preg_match( '/post\.php|post-new\.php|user-edit\.php|profile\.php|action=|<form|<input|<button|<textarea|<select/i', $html ) );
	$ok( 'R40: un comercio no ve la página', '' === trim( $pintar( $comercio ) ) );
	$ok( 'R40: un administrador de WordPress, tampoco (no es su panel)', '' === trim( $pintar( $wp_admin ) ) );

	// --- Parte 2a (Luis, 02-11): editar el contenido de su rama, con los mismos permisos que la app (un nivel) ---
	$edita  = static function ( int $quien, int $id, array $datos ) {
		wp_set_current_user( $quien );
		$r = ZN_Panel::guardar_edicion( get_userdata( $quien ), $id, $datos );
		wp_set_current_user( 0 );
		clean_post_cache( $id );
		return $r;
	};
	$borra  = static function ( int $quien, int $id ) {
		wp_set_current_user( $quien );
		$r = ZN_Panel::borrar( get_userdata( $quien ), $id );
		wp_set_current_user( 0 );
		return $r;
	};
	$meta   = static fn( int $id, string $clave ) => (string) get_post_meta( $id, $clave, true );
	$enlace = static fn( int $id ) => 'editar=' . $id . '"';

	// Enlaces «Editar» solo donde la app le deja guardar.
	$html = $pintar( $asociacion );
	$ok( '2a: la asociación tiene «Editar» en el negocio de su comercio, su novedad, la de su comercio y su sorteo', false !== strpos( $html, $enlace( $neg_hijo ) ) && false !== strpos( $html, $enlace( $nov_hijo ) ) && false !== strpos( $html, $enlace( $nov_asoc ) ) && false !== strpos( $html, $enlace( $sor_asoc ) ) );
	$ok( '2a: no en el sorteo de su comercio (en sorteos la app no hereda)', false === strpos( $html, $enlace( $sor_hijo ) ) );
	$html_fed = $pintar( $federacion );
	$ok( '2a: la federación edita su sorteo y la novedad de su asociación, no lo del segundo nivel', false !== strpos( $html_fed, $enlace( $sor_federac ) ) && false !== strpos( $html_fed, $enlace( $nov_asoc ) ) && false === strpos( $html_fed, $enlace( $neg_hijo ) ) && false === strpos( $html_fed, $enlace( $nov_hijo ) ) && false === strpos( $html_fed, $enlace( $sor_asoc ) ) );
	$ok( '2a: la página de editar está permitida (sin «action»)', ZN_Panel::pagina_permitida( 'admin.php', array( 'page' => ZN_Panel::PAGINA, 'editar' => (string) $neg_hijo ), array( 'zn_hacer' => 'guardar' ) ) );

	// Guardar.
	$r = $edita( $asociacion, $neg_hijo, array( 'post_title' => 'Negocio editado (temporal)', 'city' => 'Cangas', 'web' => 'ejemplo.invalid/tienda', 'estado' => 'draft', 'user_id' => (string) $asociacion ) );
	$ok( '2a: la asociación guarda el negocio de su comercio', $r['ok'] && 'Negocio editado (temporal)' === get_post( $neg_hijo )->post_title && 'Cangas' === $meta( $neg_hijo, 'city' ) && 'https://ejemplo.invalid/tienda' === $meta( $neg_hijo, 'web' ) );
	$ok( '2a: puede pasarlo a borrador', 'draft' === get_post_status( $neg_hijo ) );
	$ok( '2a: el dueño no cambia aunque se mande', $comercio === (int) $meta( $neg_hijo, 'user_id' ) );
	$ok( '2a: guarda sin autor de WordPress (post_author sigue en 0)', 0 === (int) get_post( $neg_hijo )->post_author );
	$r = $edita( $asociacion, $neg_hijo, array( 'post_title' => '', 'email' => 'no-es-un-correo', 'phone' => '600 000 000' ) );
	$ok( '2a: lo que no vale no se guarda y se avisa en español; lo demás, sí', ! $r['ok'] && 'Negocio editado (temporal)' === get_post( $neg_hijo )->post_title && '' === $meta( $neg_hijo, 'email' ) && '600 000 000' === $meta( $neg_hijo, 'phone' ) && 2 === count( $r['avisos'] ) && false !== strpos( implode( ' ', $r['avisos'] ), 'El nombre' ) );
	// Luis (02-11, R99): desde el panel no se publica nada; solo se pasa a borrador. Publicar, la administración.
	$r = $edita( $asociacion, $neg_hijo, array( 'estado' => 'publish' ) );
	$ok( 'R99: no puede volver a publicarlo (solo la administración)', ! $r['ok'] && 'draft' === get_post_status( $neg_hijo ) && false !== strpos( implode( ' ', $r['avisos'] ), 'administración' ) );
	$ok( 'R99: dejarlo en borrador sí vale', $edita( $asociacion, $neg_hijo, array( 'estado' => 'draft' ) )['ok'] );
	$_GET['editar'] = (string) $neg_hijo;
	$ok( 'R99: en borrador, el formulario no ofrece «Publicado»', false === strpos( $pintar( $asociacion ), 'value="publish"' ) );
	unset( $_GET['editar'] );
	wp_update_post( array( 'ID' => $neg_hijo, 'post_status' => 'publish' ) );
	$ok( 'R99: publicado, dejarlo publicado no es publicar', $edita( $asociacion, $neg_hijo, array( 'estado' => 'publish', 'city' => 'Cangas' ) )['ok'] && 'publish' === get_post_status( $neg_hijo ) );
	$r = $edita( $asociacion, $neg_hijo, array( 'estado' => 'private' ) );
	$ok( '2a: solo «publicado» o «borrador»', 'publish' === get_post_status( $neg_hijo ) && ! $r['ok'] );
	$r = $edita( $asociacion, $nov_hijo, array( 'post_title' => 'Novedad editada', 'event_category_id' => '2', 'start_date' => '2026-12-01T10:00', 'end_date' => '2026-12-02T10:00' ) );
	$ok( '2a: la asociación edita la novedad de su comercio (fechas del calendario)', $r['ok'] && 'Novedad editada' === get_post( $nov_hijo )->post_title && '2026-12-01 10:00:00' === $meta( $nov_hijo, 'start_date' ) && '2' === $meta( $nov_hijo, 'event_category_id' ) );
	$r = $edita( $asociacion, $sor_asoc, array( 'start_date' => '', 'end_date' => '2026-01-01T10:00', 'winners' => '0' ) );
	$ok( '2a: sorteo: sin inicio (obligatorio) y 0 ganadores, no se guarda nada de eso', ! $r['ok'] && '' === $meta( $sor_asoc, 'winners' ) && '' === $meta( $sor_asoc, 'start_date' ) );
	$r = $edita( $asociacion, $sor_asoc, array( 'start_date' => '2026-12-10T10:00', 'end_date' => '2026-12-01T10:00', 'winners' => '2' ) );
	$ok( '2a: el fin antes del inicio no se guarda; los ganadores, sí', ! $r['ok'] && '2026-12-10 10:00:00' !== $meta( $sor_asoc, 'end_date' ) && '2026-12-01 10:00:00' !== $meta( $sor_asoc, 'end_date' ) && '2' === $meta( $sor_asoc, 'winners' ) );
	$r = $edita( $asociacion, $sor_asoc, array( 'start_date' => '2026-12-01T10:00', 'end_date' => '2026-12-20T10:00' ) );
	$r = $edita( $asociacion, $sor_asoc, array( 'start_date' => '2026-12-25T10:00' ) );
	$ok( 'R101: solo el inicio, después del fin guardado: no se guarda (como la API)', ! $r['ok'] && '2026-12-01 10:00:00' === $meta( $sor_asoc, 'start_date' ) );
	$r = $edita( $federacion, $nov_asoc, array( 'post_title' => 'Novedad de la asociación, por la federación' ) );
	$ok( '2a: la federación edita la novedad de su asociación (en novedades la app hereda)', $r['ok'] && 'Novedad de la asociación, por la federación' === get_post( $nov_asoc )->post_title );

	// Lo que no puede.
	$titulos = static fn() => array_map( static fn( $id ) => get_post( $id )->post_title, array( $neg_ajeno, $sor_hijo, $neg_borr, $nov_ajena ) );
	$antes   = $titulos();
	$no      = array(
		'la asociación, un negocio ajeno'                 => array( $asociacion, $neg_ajeno ),
		'la asociación, el sorteo de su comercio'         => array( $asociacion, $sor_hijo ),
		'la federación, el negocio del segundo nivel'     => array( $federacion, $neg_borr ),
		'la federación, la novedad de otra asociación'    => array( $federacion, $nov_ajena ),
		'un comercio, su propio negocio (no tiene panel)' => array( $comercio, $neg_borr ),
	);
	foreach ( $no as $caso => $quien_que ) {
		$r = $edita( $quien_que[0], $quien_que[1], array( 'post_title' => 'Cambiado sin permiso' ) );
		$ok( "2a: no guarda: $caso", ! $r['ok'] );
	}
	$ok( '2a: …y nada ha cambiado', $antes === $titulos() );
	$ok( '2a: ni la papelera ni lo que no existe', ! $edita( $asociacion, $neg_papel, array( 'post_title' => 'x' ) )['ok'] && 'Negocio en la papelera' === get_post( $neg_papel )->post_title && ! $edita( $asociacion, 999999999, array( 'post_title' => 'x' ) )['ok'] );

	// Borrar.
	$ok( '2a: la asociación no borra el sorteo de su comercio', ! $borra( $asociacion, $sor_hijo ) && null !== get_post( $sor_hijo ) );
	$ok( '2a: la federación no borra el negocio del segundo nivel', ! $borra( $federacion, $neg_borr ) && null !== get_post( $neg_borr ) );
	// Luis (02-11, R100): borrar desde el panel = a la papelera (se puede deshacer desde wp-admin).
	$ok( 'R100: la asociación manda a la papelera la novedad de su comercio', $borra( $asociacion, $nov_hijo ) && 'trash' === get_post_status( $nov_hijo ) );
	$ok( 'R100: y su propio sorteo', $borra( $asociacion, $sor_asoc ) && 'trash' === get_post_status( $sor_asoc ) );
	$ok( 'R100: lo que está en la papelera ya no se edita ni se vuelve a borrar', ! $edita( $asociacion, $sor_asoc, array( 'winners' => '3' ) )['ok'] && ! $borra( $asociacion, $sor_asoc ) );
	$neg_bor = $entrada( ZN_Negocios::TIPO, $comercio, 'Negocio que se borra (temporal)' );
	$nov_del = $entrada( ZN_Eventos::TIPO, $comercio, 'Novedad del negocio que se borra' );
	update_post_meta( $nov_del, 'business_id', $neg_bor );
	$resena  = wp_insert_comment( array( 'comment_post_ID' => $neg_bor, 'comment_type' => ZN_Comentarios::RESENA, 'comment_approved' => 1, 'user_id' => $usuario, 'comment_content' => 'Temporal panel', 'comment_meta' => array( 'score' => 4 ) ) );
	$ok( 'R100: el negocio de su comercio va a la papelera', $borra( $asociacion, $neg_bor ) && 'trash' === get_post_status( $neg_bor ) );
	$ok( 'R100: sus novedades pasan a borrador (no se quedan colgando)', 'draft' === get_post_status( $nov_del ) );
	$ok( 'R100: sus reseñas se conservan', null !== get_comment( $resena ) );
	wp_delete_comment( $resena, true );

	// El formulario.
	$_GET['editar'] = (string) $neg_hijo;
	$form           = $pintar( $asociacion );
	$ok( '2a: el formulario lleva su sello (nonce) y no usa «action»', false !== strpos( $form, 'name="_wpnonce"' ) && false === strpos( $form, 'name="action"' ) && false !== strpos( $form, 'name="zn_hacer"' ) );
	$ok( 'R99: publicado, el formulario ofrece dejarlo publicado o pasarlo a borrador', false !== strpos( $form, 'value="publish"' ) && false !== strpos( $form, 'value="draft"' ) );
	$ok( '2a: sin campo de dueño ni fotos', false === strpos( $form, '[user_id]' ) && false === strpos( $form, 'photo' ) );
	update_post_meta( $neg_hijo, 'city', '"><script>alert(1)</script>' );
	$form = $pintar( $asociacion );
	$ok( '2a: los valores van escapados', false === strpos( $form, '<script>alert(1)</script>' ) );
	$_GET['editar'] = (string) $neg_ajeno;
	$form           = $pintar( $asociacion );
	$ok( '2a: el formulario de algo ajeno no sale', false === strpos( $form, '<form' ) && false === strpos( $form, 'Negocio ajeno temporal' ) );
	unset( $_GET['editar'] );

	foreach ( array( $federacion, $asociacion, $comercio ) as $id ) {
		$claves = array_merge( $claves, array_keys( ZN_Limite::claves( 'escritura', get_userdata( $id )->user_email ) ) );
	}

	// --- Parte 2b (Luis, 02-11): cuentas. Alta con correo, renombrar y baja desactivando; solo lo que deja la app ---
	// Contador de escrituras a cero para esta parte (las de 2a cuentan para el límite).
	$wpdb->query( 'DELETE FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', array_unique( $claves ) ) ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$correos = array();
	$coger   = static function ( $nulo, $correo ) use ( &$correos ) {
		$correos[] = $correo;
		return true; // No sale (ni a Mailpit).
	};
	add_filter( 'pre_wp_mail', $coger, 1, 2 );
	$alta = static function ( int $quien, string $nombre, string $correo ) use ( &$creados ) {
		wp_set_current_user( $quien );
		$r = ZN_Panel::dar_de_alta( get_userdata( $quien ), array( 'name' => $nombre, 'email' => $correo ) );
		wp_set_current_user( 0 );
		if ( ! empty( $r['id'] ) ) {
			$creados['usuarios'][] = $r['id'];
		}
		return $r;
	};
	$cuantas = static fn() => (int) ( new WP_User_Query( array( 'search' => '*-temporal-panel@*', 'search_columns' => array( 'user_email' ), 'count_total' => true, 'fields' => 'ID' ) ) )->get_total();

	// Alta.
	$r     = $alta( $asociacion, 'Comercio nuevo (temporal)', 'nuevo-comercio-temporal-panel@ejemplo.invalid' );
	$nuevo = get_userdata( (int) ( $r['id'] ?? 0 ) );
	$ok( '2b: la asociación da de alta un comercio que cuelga de ella', $r['ok'] && $nuevo && 'commerce' === ZN_Roles::rol_app( $nuevo ) && $asociacion === ZN_Roles::padre( $nuevo->ID ) );
	$ok( '2b: con su nombre (first_name) y un nombre interno al azar, como /register', 'Comercio nuevo (temporal)' === $nuevo->first_name && 0 === strpos( $nuevo->user_login, 'zn' ) && $nuevo->user_login === $nuevo->display_name );
	$ok( '2b: sin verificar (lo verifica al poner su contraseña con el código)', '1' !== (string) get_user_meta( $nuevo->ID, 'zn_verificado', true ) );
	$aviso = end( $correos );
	$ok( '2b: le llega un correo que explica cómo entrar, sin contraseña', $aviso && 'nuevo-comercio-temporal-panel@ejemplo.invalid' === $aviso['to'] && false !== strpos( $aviso['message'], 'Olvidé la contraseña' ) && false === stripos( $aviso['message'], 'contraseña:' ) );
	$ok( '2b: su contraseña es al azar y nadie la conoce (no es vacía)', ! wp_check_password( '', $nuevo->user_pass, $nuevo->ID ) );
	$antes = $cuantas();
	$ok( '2b: correo repetido: aviso y no se crea', ! $alta( $asociacion, 'Otro', 'nuevo-comercio-temporal-panel@ejemplo.invalid' )['ok'] );
	$ok( '2b: correo mal escrito: aviso en español', false !== strpos( implode( ' ', $alta( $asociacion, 'Otro', 'no-es-un-correo' )['avisos'] ), 'El email' ) );
	$ok( '2b: sin nombre: aviso', ! $alta( $asociacion, '', 'sin-nombre-temporal-panel@ejemplo.invalid' )['ok'] );
	$ok( '2b: …y ninguna de esas se ha creado', $antes === $cuantas() );
	$r          = $alta( $federacion, 'Asociación nueva (temporal)', 'nueva-asociacion-temporal-panel@ejemplo.invalid' );
	$nueva_asoc = get_userdata( (int) ( $r['id'] ?? 0 ) );
	$ok( '2b: la federación da de alta una asociación suya', $r['ok'] && $nueva_asoc && 'association' === ZN_Roles::rol_app( $nueva_asoc ) && $federacion === ZN_Roles::padre( $nueva_asoc->ID ) );
	$antes = $cuantas();
	$ok( '2b: un comercio no da de alta nada (no tiene panel)', ! $alta( $comercio, 'X', 'de-comercio-temporal-panel@ejemplo.invalid' )['ok'] && $antes === $cuantas() );

	// Renombrar.
	$renombra = static function ( int $quien, int $id, string $nombre ) {
		wp_set_current_user( $quien );
		$r = ZN_Panel::renombrar( get_userdata( $quien ), $id, $nombre );
		wp_set_current_user( 0 );
		clean_user_cache( $id );
		return $r;
	};
	$ok( '2b: la asociación cambia el nombre de su comercio', $renombra( $asociacion, $comercio, 'Comercio renombrado (temporal)' )['ok'] && 'Comercio renombrado (temporal)' === get_userdata( $comercio )->first_name );
	$ok( '2b: un nombre vacío no se guarda', ! $renombra( $asociacion, $comercio, '' )['ok'] && 'Comercio renombrado (temporal)' === get_userdata( $comercio )->first_name );
	$ok( '2b: la federación cambia el de su asociación', $renombra( $federacion, $asociacion, 'Asociación renombrada (temporal)' )['ok'] && 'Asociación renombrada (temporal)' === get_userdata( $asociacion )->first_name );
	$no = array(
		'la asociación, un comercio ajeno'           => array( $asociacion, $ajeno ),
		'la asociación, su usuario (no es comercio)' => array( $asociacion, $usuario ),
		'la asociación, a sí misma'                  => array( $asociacion, $asociacion ),
		'la asociación, a su federación'             => array( $asociacion, $federacion ),
		'la federación, el comercio del 2.º nivel'   => array( $federacion, $comercio ),
		'otra asociación, el comercio de esta'       => array( $otra_asoc, $comercio ),
	);
	foreach ( $no as $caso => $quien_que ) {
		$ok( "2b: no renombra: $caso", ! $renombra( $quien_que[0], $quien_que[1], 'Cambiado sin permiso' )['ok'] && 'Cambiado sin permiso' !== get_userdata( $quien_que[1] )->first_name );
	}

	// Baja = desactivar.
	$desactiva = static function ( int $quien, int $id ) {
		wp_set_current_user( $quien );
		$r = ZN_Panel::desactivar( get_userdata( $quien ), $id );
		wp_set_current_user( 0 );
		return $r;
	};
	$neg_baja = $entrada( ZN_Negocios::TIPO, $comercio, 'Negocio de la baja (temporal)' );
	$nov_baja = $entrada( ZN_Eventos::TIPO, $comercio, 'Novedad de la baja (temporal)' );
	$sor_baja = $entrada( ZN_Sorteos::TIPO, $comercio, 'Sorteo de la baja (temporal)' );
	$token    = ZN_Tokens::emitir( $comercio );
	$ok( '2b: no da de baja: la asociación, un comercio ajeno', ! $desactiva( $asociacion, $ajeno ) && ! ZN_Panel::desactivada( $ajeno ) );
	$ok( '2b: no da de baja: la federación, el comercio del 2.º nivel', ! $desactiva( $federacion, $comercio ) && ! ZN_Panel::desactivada( $comercio ) );
	$ok( '2b: la asociación da de baja su comercio (desactivada)', $desactiva( $asociacion, $comercio ) && ZN_Panel::desactivada( $comercio ) );
	$ok( '2b: no se borra nada: la cuenta sigue y sus cosas también', false !== get_userdata( $comercio ) && null !== get_post( $neg_baja ) && null !== get_post( $nov_baja ) && null !== get_post( $sor_baja ) );
	$ok( '2b: sus negocios, novedades y sorteos pasan a borrador', 'draft' === get_post_status( $neg_baja ) && 'draft' === get_post_status( $nov_baja ) && 'draft' === get_post_status( $sor_baja ) );
	$ok( '2b: sus sesiones de la app se cierran', 0 === ZN_Tokens::validar( (string) $token ) );
	$peticion = new WP_REST_Request( 'POST', '/' . ZN_NAMESPACE . '/login' );
	$peticion->set_header( 'Content-Type', 'application/json' );
	$peticion->set_body( wp_json_encode( array( 'email' => get_userdata( $comercio )->user_email, 'password' => $clave ) ) );
	$respuesta = rest_do_request( $peticion );
	$ok( '2b: no puede entrar en la app (la misma respuesta que una contraseña mala)', 401 === $respuesta->get_status() && 'error' === ( $respuesta->get_data()['status'] ?? null ) );
	$ok( '2b: desactivada ya no se edita ni se vuelve a dar de baja', ! $renombra( $asociacion, $comercio, 'Otra vez' )['ok'] && ! $desactiva( $asociacion, $comercio ) );
	$ok( '2b: sus cosas ya no se editan desde el panel de su asociación', ! $edita( $asociacion, $neg_hijo, array( 'city' => 'Bueu' ) )['ok'] );
	$ok( '2b: la federación da de baja su asociación…', $desactiva( $federacion, $nueva_asoc->ID ) && ZN_Panel::desactivada( $nueva_asoc->ID ) );
	update_user_meta( $nueva_asoc->ID, 'zn_verificado', '1' );
	$ok( '2b: …y esa asociación ya no entra al panel', ! ZN_Panel::puede_entrar( get_userdata( $nueva_asoc->ID ) ) );

	// La administración la reactiva desde Usuarios.
	wp_set_current_user( $wp_admin );
	$acciones = apply_filters( 'user_row_actions', array(), get_userdata( $comercio ) );
	$ok( '2b: un administrador ve «Reactivar» en la lista de usuarios', isset( $acciones['zn_reactivar'] ) && false !== strpos( $acciones['zn_reactivar'], '_wpnonce=' ) );
	$ok( '2b: no en una cuenta activa', ! isset( apply_filters( 'user_row_actions', array(), get_userdata( $ajeno ) )['zn_reactivar'] ) );
	wp_set_current_user( $asociacion );
	$ok( '2b: una cuenta del panel no lo ve', ! isset( apply_filters( 'user_row_actions', array(), get_userdata( $comercio ) )['zn_reactivar'] ) );
	wp_set_current_user( 0 );
	$ok( '2b: reactivar la deja entrar otra vez; sus cosas siguen en borrador (las publica la administración)', ZN_Panel::reactivar( $comercio ) && ! ZN_Panel::desactivada( $comercio ) && 200 === rest_do_request( $peticion )->get_status() && 'draft' === get_post_status( $neg_baja ) );

	// La página.
	$html = $pintar( $asociacion );
	$ok( '2b: la asociación tiene «Dar de alta un comercio» y «Editar» en su comercio', false !== strpos( $html, 'cuenta=nueva' ) && false !== strpos( $html, 'Dar de alta un comercio' ) && false !== strpos( $html, 'cuenta=' . $comercio . '"' ) );
	$ok( '2b: no en su usuario ni en cuentas ajenas', false === strpos( $html, 'cuenta=' . $usuario . '"' ) );
	$html_fed = $pintar( $federacion );
	$ok( '2b: la federación: «Dar de alta una asociación», «Editar» en su asociación y no en el comercio', false !== strpos( $html_fed, 'Dar de alta una asociación' ) && false !== strpos( $html_fed, 'cuenta=' . $asociacion . '"' ) && false === strpos( $html_fed, 'cuenta=' . $comercio . '"' ) );
	$ok( '2b: la desactivada sale como tal', false !== strpos( $html_fed, 'Desactivada' ) );
	$_GET['cuenta'] = 'nueva';
	$form           = $pintar( $asociacion );
	$ok( '2b: el alta pide nombre y correo, con sello y sin contraseña', false !== strpos( $form, 'name="zn_cuenta[name]"' ) && false !== strpos( $form, 'name="zn_cuenta[email]"' ) && false !== strpos( $form, 'name="_wpnonce"' ) && false === stripos( $form, 'password' ) && false === strpos( $form, 'name="action"' ) );
	$_GET['cuenta'] = (string) $comercio;
	$form           = $pintar( $asociacion );
	$ok( '2b: editar un comercio: nombre y «Dar de baja»; el correo no se cambia', false !== strpos( $form, 'name="zn_cuenta[name]"' ) && false === strpos( $form, 'name="zn_cuenta[email]"' ) && false !== strpos( $form, 'value="baja"' ) );
	$_GET['cuenta'] = (string) $ajeno;
	$ok( '2b: el de un comercio ajeno no sale', false === strpos( $pintar( $asociacion ), '<form' ) );
	unset( $_GET['cuenta'] );
	$ok( '2b: la página de cuentas está permitida (sin «action»)', ZN_Panel::pagina_permitida( 'admin.php', array( 'page' => ZN_Panel::PAGINA, 'cuenta' => 'nueva' ), array( 'zn_hacer' => 'alta' ) ) );

	// Las altas cuentan para el límite de escrituras de la app.
	for ( $i = 0; $i < 25; $i++ ) {
		$r = $alta( $otra_asoc, "Comercio $i (temporal)", "limite-$i-temporal-panel@ejemplo.invalid" );
	}
	$ok( '2b: pasado el límite de escrituras, no da más altas', ! $r['ok'] && false === email_exists( 'limite-24-temporal-panel@ejemplo.invalid' ) );
	$claves = array_merge( $claves, array_keys( ZN_Limite::claves( 'escritura', get_userdata( $otra_asoc )->user_email ) ) );
	remove_filter( 'pre_wp_mail', $coger, 1 );
	foreach ( array( $federacion, $asociacion, $comercio ) as $id ) {
		$claves = array_merge( $claves, array_keys( ZN_Limite::claves( 'escritura', get_userdata( $id )->user_email ) ) );
	}
	$wpdb->query( 'DELETE FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', array_unique( $claves ) ) ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// Límite de escrituras por cuenta (el de la app, R94): 20 por hora.
	for ( $i = 0; $i < 20; $i++ ) {
		$edita( $federacion, $sor_federac, array( 'winners' => (string) ( $i + 1 ) ) );
	}
	$r = $edita( $federacion, $sor_federac, array( 'winners' => '99' ) );
	$ok( '2a: pasado el límite de escrituras de la app, no guarda', ! $r['ok'] && '99' !== $meta( $sor_federac, 'winners' ) );
} finally {
	wp_set_current_user( 0 );
	$_SERVER['REMOTE_ADDR'] = $ip_antes;
	foreach ( $creados['entradas'] as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $creados['usuarios'] as $id ) {
		wp_delete_user( $id );
	}
	if ( $creados['usuarios'] ) {
		$wpdb->query( 'DELETE FROM ' . ZN_Tokens::tabla() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $creados['usuarios'] ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	if ( $claves ) {
		$wpdb->query( 'DELETE FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', array_unique( $claves ) ) ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
$quedan = array_merge(
	array_filter( $creados['usuarios'], static fn( $id ) => false !== get_userdata( $id ) ),
	array_filter( $creados['entradas'], static fn( $id ) => null !== get_post( $id ) )
);
$intentos = $claves ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', array_unique( $claves ) ) ) . "')" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas, %d entradas y sus intentos', count( $creados['usuarios'] ), count( $creados['entradas'] ) ), ! $quedan && 0 === $intentos );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

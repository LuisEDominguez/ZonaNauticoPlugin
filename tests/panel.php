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
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => $clave, 'role' => $rol_wp, 'display_name' => $nombre ?: $login ) );
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
	$ok( 'R40: federación: su cuenta es la asociación (no el comercio de la asociación)', array( $asociacion ) === $ids( ZN_Panel::cuentas( $fed ) ) );
	$sor = $ids( ZN_Panel::entradas( $fed, ZN_Sorteos::TIPO ) );
	$ok( 'R40: federación: sorteos suyos y de su asociación, no los del comercio', in_array( $sor_federac, $sor, true ) && in_array( $sor_asoc, $sor, true ) && ! in_array( $sor_hijo, $sor, true ) && 2 === count( $sor ) );
	$ok( 'R40: federación: ningún negocio (no tiene)', array() === ZN_Panel::entradas( $fed, ZN_Negocios::TIPO ) );
	$ok( 'R40: un comercio no tiene panel (nada)', array() === ZN_Panel::cuentas( get_userdata( $comercio ) ) && array() === ZN_Panel::entradas( get_userdata( $comercio ), ZN_Sorteos::TIPO ) );
	$ok( 'R40: otra asociación no ve nada de esta rama', ! array_intersect( array( $neg_hijo, $neg_borr ), $ids( ZN_Panel::entradas( get_userdata( $otra_asoc ), ZN_Negocios::TIPO ) ) ) && array() === ZN_Panel::cuentas( get_userdata( $otra_asoc ) ) );

	// --- La página: lo suyo, solo para leer ---
	$html = $pintar( $asociacion );
	$ok( 'R40: la página enseña lo de su rama', false !== strpos( $html, 'Comercio hijo temporal' ) && false !== strpos( $html, 'Sorteo del hijo' ) && false !== strpos( $html, 'Novedad de la asociación' ) && false !== strpos( $html, 'Negocio borrador del hijo' ) );
	$ok( 'R40: y nada ajeno', false === strpos( $html, 'ajeno temporal' ) && false === strpos( $html, 'Novedad de otra asociación' ) && false === strpos( $html, 'Sorteo de la federación' ) && false === strpos( $html, 'Negocio en la papelera' ) );
	$ok( 'R40: los textos van escapados', false === strpos( $html, '<script>alert(1)</script>' ) && false !== strpos( $html, '&lt;script&gt;' ) );
	$ok( 'R40: sin enlaces para editar ni formularios', ! preg_match( '/post\.php|post-new\.php|user-edit\.php|profile\.php|action=|<form|<input|<button|<textarea|<select/i', $html ) );
	$ok( 'R40: un comercio no ve la página', '' === trim( $pintar( $comercio ) ) );
	$ok( 'R40: un administrador de WordPress, tampoco (no es su panel)', '' === trim( $pintar( $wp_admin ) ) );
} finally {
	wp_set_current_user( 0 );
	$_SERVER['REMOTE_ADDR'] = $ip_antes;
	foreach ( $creados['entradas'] as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $creados['usuarios'] as $id ) {
		wp_delete_user( $id );
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

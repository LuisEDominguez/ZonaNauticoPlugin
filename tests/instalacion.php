<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// R68: una sola rutina de instalación y versión (ZN_Instalacion) y un uninstall.php que deja WordPress limpio:
// tablas de tokens e intentos, opciones del plugin (también zn_huella_relleno), sus avisos temporales, los roles de
// la app y sus capacidades. Y el prefijo zn_ en el teléfono y la ubicación de las cuentas (antes phone, latitude y
// longitude, nombres que puede usar cualquier otro plugin).
// Uso: wp eval-file tests/instalacion.php. NO desinstala de verdad (borraría los datos del Local): comprueba las
// listas que usa la desinstalación y que cubren todo lo que el plugin crea. Crea cuentas
// «…-temporal-instalacion@ejemplo.invalid» y un negocio para probar el cambio de prefijo, y los borra.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$raiz     = dirname( __DIR__ );
$cuentas  = array();
$entradas = array();
$cuenta   = static function ( string $rol_wp ) use ( &$cuentas ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-instalacion';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => 'Temporal' ) );
	$cuentas[] = $id;
	return $id;
};

try {
	// --- Una sola rutina de instalación y versión ---
	$ok( 'existe ZN_Instalacion con instalar() y desinstalar()', class_exists( 'ZN_Instalacion' ) && method_exists( 'ZN_Instalacion', 'instalar' ) && method_exists( 'ZN_Instalacion', 'desinstalar' ) );
	$ok( 'la instalación va en plugins_loaded (las tablas, antes de mirar el usuario, R48)', false !== has_action( 'plugins_loaded', array( 'ZN_Instalacion', 'instalar' ) ) );
	$ok( 'tokens e intentos ya no se instalan cada uno por su cuenta', false === has_action( 'plugins_loaded', array( 'ZN_Tokens', 'instalar' ) ) && false === has_action( 'plugins_loaded', array( 'ZN_Limite', 'instalar' ) ) );
	ZN_Instalacion::instalar();
	ZN_Instalacion::instalar();
	$ok( 'instalar dos veces no falla y deja zn_version en la versión del plugin', ZN_VERSION === get_option( 'zn_version' ) );
	$ok( 'las dos tablas existen', ZN_Tokens::tabla() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', ZN_Tokens::tabla() ) ) && ZN_Limite::tabla() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', ZN_Limite::tabla() ) ) );

	// --- Lo que se borra al desinstalar cubre todo lo que crea el plugin ---
	$ok( 'desinstalar borra las dos tablas', array( ZN_Tokens::tabla(), ZN_Limite::tabla() ) === ZN_Instalacion::tablas() );
	$escritas = array();
	foreach ( array_merge( glob( "$raiz/includes/*.php" ), array( "$raiz/zona-nautico.php" ) ) as $archivo ) {
		preg_match_all( "/(?:update|add)_option\(\s*'([a-z0-9_]+)'/", (string) file_get_contents( $archivo ), $m ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$escritas = array_merge( $escritas, $m[1] );
	}
	$escritas = array_values( array_unique( $escritas ) );
	$ok( 'toda opción que escribe el plugin está en la lista de desinstalar (' . implode( ', ', $escritas ) . ')', $escritas && ! array_diff( $escritas, ZN_Instalacion::OPCIONES ) );
	$ok( 'R68: también zn_huella_relleno y zn_version', in_array( 'zn_huella_relleno', ZN_Instalacion::OPCIONES, true ) && in_array( 'zn_version', ZN_Instalacion::OPCIONES, true ) );
	$escritos = array();
	foreach ( glob( "$raiz/includes/*.php" ) as $archivo ) {
		preg_match_all( "/set_transient\(\s*'([a-z0-9_]+)'/", (string) file_get_contents( $archivo ), $m ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$escritos = array_merge( $escritos, $m[1] );
	}
	$ok( 'todo aviso temporal (transient) del plugin empieza por un prefijo que borra desinstalar', ! array_filter( array_unique( $escritos ), static fn( $t ) => ! array_filter( ZN_Instalacion::TEMPORALES, static fn( $p ) => 0 === strpos( $t, $p ) ) ) );
	$ok( 'desinstalar quita los 5 roles de la app', array_keys( ZN_Roles::ROLES_APP ) === ZN_Instalacion::roles() );
	$ok( 'capacidades del plugin: zn:…, zn_panel_ver y las de negocios, novedades y sorteos', ZN_Instalacion::es_del_plugin( 'zn:review:save' ) && ZN_Instalacion::es_del_plugin( 'zn_panel_ver' ) && ZN_Instalacion::es_del_plugin( 'edit_zn_negocios' ) && ZN_Instalacion::es_del_plugin( 'delete_others_zn_eventos' ) && ZN_Instalacion::es_del_plugin( 'publish_zn_sorteos' ) );
	$ok( 'las de WordPress no se tocan', ! ZN_Instalacion::es_del_plugin( 'edit_posts' ) && ! ZN_Instalacion::es_del_plugin( 'read' ) && ! ZN_Instalacion::es_del_plugin( 'manage_options' ) && ! ZN_Instalacion::es_del_plugin( 'edit_zn_otra_cosa' ) );
	$admin = get_role( 'administrator' );
	$suyas = $admin ? array_filter( array_keys( $admin->capabilities ), static fn( $c ) => false !== strpos( $c, 'zn_negocio' ) || false !== strpos( $c, 'zn_evento' ) || false !== strpos( $c, 'zn_sorteo' ) ) : array();
	$ok( 'todas las capacidades que el plugin da al administrador se reconocen como suyas', $suyas && ! array_filter( $suyas, static fn( $c ) => ! ZN_Instalacion::es_del_plugin( $c ) ) );
	$desinstalar = (string) file_get_contents( "$raiz/uninstall.php" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$ok( 'uninstall.php existe, solo corre desde WordPress (WP_UNINSTALL_PLUGIN) y llama a desinstalar()', '' !== $desinstalar && false !== strpos( $desinstalar, "defined( 'WP_UNINSTALL_PLUGIN' ) || exit" ) && false !== strpos( $desinstalar, 'ZN_Instalacion::desinstalar()' ) );
	$ok( 'uninstall.php no carga el plugin entero (solo las piezas que necesita)', false === strpos( $desinstalar, 'zona-nautico.php' ) );

	// --- Prefijo zn_ en el teléfono y la ubicación de las cuentas ---
	$ok( 'esquema de la cuenta: teléfono y ubicación se guardan con zn_', 'zn_phone' === ZN_Esquema::campos( 'cuenta' )['phone']['guardado'] && 'zn_latitude' === ZN_Esquema::campos( 'cuenta' )['latitude']['guardado'] && 'zn_longitude' === ZN_Esquema::campos( 'cuenta' )['longitude']['guardado'] );
	$ok( 'el de los negocios no cambia (son las columnas del Laravel)', 'phone' === ZN_Esquema::campos( 'negocio' )['phone']['guardado'] && 'latitude' === ZN_Esquema::campos( 'negocio' )['latitude']['guardado'] );
	$vieja = $cuenta( 'zn_usuario' );
	update_user_meta( $vieja, 'phone', '+34 600 000 009' );
	update_user_meta( $vieja, 'latitude', '42.2' );
	update_user_meta( $vieja, 'longitude', '-8.7' );
	$ajena = $cuenta( 'subscriber' );
	update_user_meta( $ajena, 'phone', 'de otro plugin' );
	$conflicto = $cuenta( 'zn_comercio' );
	update_user_meta( $conflicto, 'phone', 'viejo' );
	update_user_meta( $conflicto, 'zn_phone', 'nuevo' );
	$tienda     = ZN_Esquema::guardar( 'negocio', null, array( 'post_title' => 'Negocio temporal-instalacion', 'phone' => '986 000 000', 'latitude' => '42.24' ), array( 'post_status' => 'draft', 'post_author' => 0 ) );
	$entradas[] = $tienda;
	ZN_Instalacion::migrar_meta_cuentas();
	$ok( 'R68: los datos de una cuenta de la app pasan a zn_phone, zn_latitude y zn_longitude', '+34 600 000 009' === get_user_meta( $vieja, 'zn_phone', true ) && '42.2' === get_user_meta( $vieja, 'zn_latitude', true ) && '-8.7' === get_user_meta( $vieja, 'zn_longitude', true ) );
	$ok( 'R68: …y las claves viejas desaparecen', '' === get_user_meta( $vieja, 'phone', true ) && '' === get_user_meta( $vieja, 'latitude', true ) && '' === get_user_meta( $vieja, 'longitude', true ) );
	$ok( 'una cuenta que no es de la app no se toca', 'de otro plugin' === get_user_meta( $ajena, 'phone', true ) && '' === get_user_meta( $ajena, 'zn_phone', true ) );
	$ok( 'si ya había un zn_phone, gana el nuevo y el viejo se quita', 'nuevo' === get_user_meta( $conflicto, 'zn_phone', true ) && '' === get_user_meta( $conflicto, 'phone', true ) );
	$ok( 'los negocios conservan phone y latitude', '986 000 000' === get_post_meta( $tienda, 'phone', true ) && '42.24' === get_post_meta( $tienda, 'latitude', true ) );
	ZN_Instalacion::migrar_meta_cuentas();
	$ok( 'pasarla otra vez no cambia nada', '+34 600 000 009' === get_user_meta( $vieja, 'zn_phone', true ) && 1 === count( get_user_meta( $vieja, 'zn_phone' ) ) );

	// La API y las herramientas leen y escriben con el prefijo.
	update_user_meta( $vieja, 'zn_verificado', '1' );
	wp_set_current_user( $vieja );
	$respuesta = rest_do_request( new WP_REST_Request( 'GET', '/' . ZN_NAMESPACE . "/user/$vieja" ) );
	wp_set_current_user( 0 );
	$datos = $respuesta->get_data()['data'] ?? array();
	$ok( 'GET /user/{id} da el teléfono y la ubicación desde zn_*', '+34 600 000 009' === ( $datos['phone'] ?? null ) && 42.2 === ( $datos['latitude'] ?? null ) && -8.7 === ( $datos['longitude'] ?? null ) );
	$exportar = apply_filters( 'wp_privacy_personal_data_exporters', array() )['zona-nautico']['callback'] ?? null;
	$export   = $exportar ? wp_json_encode( call_user_func( $exportar, get_userdata( $vieja )->user_email, 1 ), JSON_UNESCAPED_UNICODE ) : '';
	$ok( 'exportar datos personales trae el teléfono y la ubicación desde zn_*', false !== strpos( $export, '+34 600 000 009' ) && false !== strpos( $export, '42.2' ) );
	$borrar = apply_filters( 'wp_privacy_personal_data_erasers', array() )['zona-nautico']['callback'] ?? null;
	if ( $borrar ) {
		call_user_func( $borrar, get_userdata( $vieja )->user_email, 1 );
	}
	$ok( 'borrar datos personales quita zn_phone, zn_latitude y zn_longitude', '' === get_user_meta( $vieja, 'zn_phone', true ) && '' === get_user_meta( $vieja, 'zn_latitude', true ) && '' === get_user_meta( $vieja, 'zn_longitude', true ) );
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
$quedan  = array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) );
$sueltas = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email LIKE '%-temporal-instalacion@ejemplo.invalid'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas y %d entradas', count( $cuentas ), count( $entradas ) ), ! $quedan && 0 === $sueltas && ! array_filter( $entradas, static fn( $id ) => is_int( $id ) && get_post( $id ) ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

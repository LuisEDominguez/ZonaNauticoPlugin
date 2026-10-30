<?php
// Roles de la app y permisos (R22). Uso: wp eval-file tests/roles.php
// Crea cuentas temporales (login «*-temporal-roles») y las borra al terminar.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

$cuentas = array();
$cuenta  = static function ( string $rol_wp, int $padre = 0 ) use ( &$cuentas ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-temporal-roles';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => 'Clave-de-prueba-1', 'role' => $rol_wp, 'first_name' => 'Temporal' ) );
	if ( $padre ) {
		update_user_meta( $id, 'parent_id', $padre );
	}
	$cuentas[] = $id;
	return $id;
};

try {
	// --- Los 5 roles, con los permisos del Laravel y ninguno de WordPress ---
	$esperados = array( 'administrator' => 51, 'federation' => 21, 'association' => 27, 'commerce' => 22, 'user' => 13 );
	foreach ( ZN_Roles::ROLES_APP as $rol_wp => $rol_app ) {
		$rol = get_role( $rol_wp );
		$ok( "$rol_wp existe", null !== $rol );
		$caps = array_keys( array_filter( $rol ? $rol->capabilities : array() ) );
		$ok( "$rol_wp: los {$esperados[ $rol_app ]} permisos de «{$rol_app}» del Laravel", count( $caps ) === $esperados[ $rol_app ] && ! array_diff( $caps, ZN_Roles::permisos( $rol_app ) ) );
		$ok( "$rol_wp: ningún permiso de WordPress (ni read)", ! array_filter( $caps, static fn( $c ) => 0 !== strpos( $c, 'zn:' ) ) );
	}

	// --- La API enseña el rol de la app de cada cuenta ---
	foreach ( ZN_Roles::ROLES_APP as $rol_wp => $rol_app ) {
		$id = $cuenta( $rol_wp );
		$ok( "usuario público de $rol_wp: role «{$rol_app}»", $rol_app === ZN_API::usuario_publico( $id )['role'] );
	}

	// --- «Lo suyo» por el dueño guardado y por parent_id (un nivel, como el Laravel) ---
	$federacion = $cuenta( 'zn_federacion' );
	$asociacion = $cuenta( 'zn_asociacion', $federacion );
	$comercio   = $cuenta( 'zn_comercio', $asociacion );
	$otro       = $cuenta( 'zn_comercio' );
	$usuario    = $cuenta( 'zn_usuario' );
	$admin_app  = $cuenta( 'zn_administrador' );

	$ok( 'comercio: puede editar su negocio (business:save_own)', user_can( $comercio, 'zn_puede', 'business:save', $comercio ) );
	$ok( 'comercio: no puede editar el negocio de otro', ! user_can( $comercio, 'zn_puede', 'business:save', $otro ) );
	$ok( 'comercio: no puede editar algo sin dueño', ! user_can( $comercio, 'zn_puede', 'business:save', 0 ) );
	$ok( 'asociación: puede editar el negocio de un comercio suyo (parent_id)', user_can( $asociacion, 'zn_puede', 'business:save', $comercio ) );
	$ok( 'asociación: no puede editar el de un comercio ajeno', ! user_can( $asociacion, 'zn_puede', 'business:save', $otro ) );
	$ok( 'federación: no tiene permiso de negocios (como en el Laravel)', ! user_can( $federacion, 'zn_puede', 'business:save', $comercio ) );
	$ok( 'federación: puede editar un evento de su asociación (event:save_own)', user_can( $federacion, 'zn_puede', 'event:save', $asociacion ) );
	$ok( 'federación: un nivel solo: no el de un comercio de su asociación', ! user_can( $federacion, 'zn_puede', 'event:save', $comercio ) );
	$ok( 'administración de la app: cualquiera (business:save)', user_can( $admin_app, 'zn_puede', 'business:save', $otro ) );
	$ok( 'comercio: responde reseñas de lo suyo (review:reply_own)', user_can( $comercio, 'zn_puede', 'review:reply', $comercio ) && ! user_can( $comercio, 'zn_puede', 'review:reply', $otro ) );
	$ok( 'usuario: crea preguntas (faq:save) pero no edita negocios', user_can( $usuario, 'zn_puede', 'faq:save', 0 ) && ! user_can( $usuario, 'zn_puede', 'business:save', $usuario ) );
	$ok( 'permiso inexistente: no', ! user_can( $admin_app, 'zn_puede', 'inventado:hacer', $admin_app ) );
	$ok( 'un administrador de WordPress no tiene permisos de la app', ! user_can( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0], 'zn_puede', 'business:save', $otro ) );
	$ok( 'una cuenta de la app no edita entradas de wp-admin', ! user_can( $admin_app, 'edit_zn_negocios' ) && ! user_can( $admin_app, 'read' ) );

	// --- Sin wp-login.php para las cuentas de la app ---
	$login    = get_userdata( $comercio )->user_login;
	$resultado = wp_authenticate( $login, 'Clave-de-prueba-1' );
	$ok( 'cuenta de la app con su contraseña buena: wp-login.php la rechaza', is_wp_error( $resultado ) );
	$mala = wp_authenticate( $login, 'mala' );
	$no_existe = wp_authenticate( 'no-existe-temporal-roles', 'mala' );
	$ok( 'wp-login.php: mismo aviso para contraseña buena, mala y cuenta inexistente', 1 === count( array_unique( array( apply_filters( 'login_errors', $resultado->get_error_message() ), apply_filters( 'login_errors', $mala->get_error_message() ), apply_filters( 'login_errors', $no_existe->get_error_message() ) ) ) ) );
	$ok( 'sin recuperar contraseña desde wp-login.php', ! apply_filters( 'allow_password_reset', true, $comercio ) );
} finally {
	foreach ( $cuentas as $id ) {
		wp_delete_user( $id );
	}
}
$quedan = array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) );
$ok( sprintf( 'limpieza: borradas %d cuentas temporales', count( $cuentas ) ), ! $quedan );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Sorteos, parte 3: marcar ganador y entrega desde «Mi zona» (quien organiza: asociación o federación) y desde la
// ficha del sorteo en wp-admin (la administración). Lista con nombre y miniatura del ticket (por una dirección
// protegida, nunca la del archivo), botones de ganador y entrega y el CSV. Mismas reglas que la API: R43, R45, R139
// y el aviso privado al ganador (R57: fuera de producción, solo a @ejemplo.invalid).
// Uso: wp eval-file tests/participaciones-admin.php. Crea cuentas «…-temporal-gestion@ejemplo.invalid», sorteos y
// fotos de prueba, y lo borra todo. Los correos se recogen y no salen.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$servidor = $_SERVER;
$cuentas  = array();
$emails   = array();
$entradas = array();
$temporales = array();
$cuenta   = static function ( string $rol_wp, string $nombre, int $padre = 0 ) use ( &$cuentas, &$emails ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-gestion';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => $nombre ) );
	update_user_meta( $id, 'zn_verificado', '1' );
	if ( $padre ) {
		update_user_meta( $id, 'zn_parent_id', $padre );
	}
	$cuentas[] = $id;
	$emails[]  = "$login@ejemplo.invalid";
	return $id;
};
$madrid = static fn( string $cuando ) => ( new DateTimeImmutable( $cuando, new DateTimeZone( 'Europe/Madrid' ) ) )->format( 'Y-m-d H:i:s' );
$sorteo = static function ( int $dueno ) use ( &$entradas, $madrid ) {
	$id         = ZN_Esquema::guardar( 'sorteo', null, array( 'post_title' => 'Sorteo temporal-gestion ' . count( $entradas ), 'user_id' => $dueno, 'winners' => 1, 'start_date' => $madrid( '-1 day' ), 'end_date' => $madrid( '+7 days' ) ), array( 'post_status' => 'publish', 'post_author' => 0 ) );
	$entradas[] = $id;
	return $id;
};
// Participación con una foto de verdad en la carpeta privada.
$participa = static function ( int $sorteo, int $usuario ) use ( $wpdb ) {
	$img = imagecreatetruecolor( 30, 20 );
	$nombre = strtolower( wp_generate_password( 32, false, false ) ) . '.jpg';
	imagejpeg( $img, ZN_Participaciones::carpeta() . "/$nombre" );
	$ahora = current_time( 'mysql', true );
	$wpdb->insert( ZN_Participaciones::tabla(), array( 'draw_id' => $sorteo, 'user_id' => $usuario, 'ticket' => $nombre, 'adulto_en' => $ahora, 'date' => $ahora ) );
	return (int) $wpdb->insert_id;
};
$fila = static fn( int $id ) => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$como = static function ( int $usuario, callable $hacer ) {
	wp_set_current_user( $usuario );
	try {
		return $hacer();
	} finally {
		wp_set_current_user( 0 );
	}
};
$correos = array();
add_filter(
	'pre_wp_mail',
	static function ( $resultado, $correo ) use ( &$correos ) {
		$correos[] = $correo;
		return true;
	},
	PHP_INT_MAX,
	2
);

try {
	$_SERVER['REMOTE_ADDR'] = '192.0.2.100';
	$asociacion = $cuenta( 'zn_asociacion', 'Asociación' );
	$otra_asoc  = $cuenta( 'zn_asociacion', 'Otra asociación' );
	$comercio   = $cuenta( 'zn_comercio', 'Comercio', $asociacion );
	$ana        = $cuenta( 'zn_usuario', 'Ana María' );
	$luis       = $cuenta( 'zn_usuario', 'Luis' );
	$admin_wp   = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
	$suyo       = $sorteo( $asociacion );
	$del_comercio = $sorteo( $comercio );
	$p_ana      = $participa( $suyo, $ana );
	$p_luis     = $participa( $suyo, $luis );
	$p_otro     = $participa( $del_comercio, $luis );

	// --- Quién gestiona (R43): quien organiza y la administración de WordPress ---
	$ok( 'la asociación gestiona SU sorteo', ZN_Participaciones::puede_gestionar( get_post( $suyo ), $asociacion ) );
	$ok( 'R43: no el de su comercio hijo (sin herencia por el padre)', ! ZN_Participaciones::puede_gestionar( get_post( $del_comercio ), $asociacion ) );
	$ok( 'otra asociación, no', ! ZN_Participaciones::puede_gestionar( get_post( $suyo ), $otra_asoc ) );
	$ok( 'una participante, no', ! ZN_Participaciones::puede_gestionar( get_post( $suyo ), $ana ) );
	if ( $admin_wp ) {
		$ok( 'la administración de WordPress, sí', ZN_Participaciones::puede_gestionar( get_post( $suyo ), $admin_wp ) );
	}

	// --- Panel «Mi zona» ---
	$base_panel = ZN_Panel::url_participantes( $suyo );
	$ok( 'R97: las direcciones del panel no llevan «action» (el panel no las cerraría)', ZN_Panel::pagina_permitida( 'admin.php', wp_parse_args( (string) wp_parse_url( $base_panel, PHP_URL_QUERY ) ) ) );
	$html = $como( $asociacion, static fn() => ZN_Panel::html_participantes( wp_get_current_user(), $suyo ) );
	$ok( 'panel: la lista enseña los participantes con su nombre de pila', false !== strpos( $html, 'Ana' ) && false !== strpos( $html, 'Luis' ) && false === strpos( $html, 'Ana María' ) );
	$ok( 'panel: la miniatura va por la dirección protegida del panel, nunca por la del archivo', false !== strpos( $html, 'ticket=' . $p_ana ) && false !== strpos( $html, '_wpnonce' ) && false === strpos( $html, 'zn-privado' ) && false === strpos( $html, '.jpg' ) );
	$ok( 'panel: hay enlaces (con sello) para marcar ganador y para descargar el CSV', false !== strpos( $html, 'hacer=ganador' ) && false !== strpos( $html, 'csv=1' ) );
	$html_otro = $como( $otra_asoc, static fn() => ZN_Panel::html_participantes( wp_get_current_user(), $suyo ) );
	$ok( 'panel: otra asociación no ve nada del sorteo ajeno', false === strpos( $html_otro, 'Ana' ) && false !== strpos( $html_otro, 'No puedes' ) );
	$html_hijo = $como( $asociacion, static fn() => ZN_Panel::html_participantes( wp_get_current_user(), $del_comercio ) );
	$ok( 'panel: tampoco el del comercio hijo (R43)', false === strpos( $html_hijo, 'Luis' ) );

	// La foto y el CSV: solo a quien gestiona.
	$ok( 'ticket: quien organiza lo tiene', '' !== ZN_Participaciones::ticket_para( $p_ana, $asociacion ) );
	$ok( 'ticket: otra asociación, no', '' === ZN_Participaciones::ticket_para( $p_ana, $otra_asoc ) );
	$ok( 'ticket: la propia participante, sí', '' !== ZN_Participaciones::ticket_para( $p_ana, $ana ) );
	$ok( 'ticket: otro participante, no', '' === ZN_Participaciones::ticket_para( $p_ana, $luis ) );
	$csv = ZN_Participaciones::csv( get_post( $suyo ) );
	$ok( 'R45: el CSV lleva nombre y correo de los dos, sin fotos', false !== strpos( $csv, get_userdata( $ana )->user_email ) && false !== strpos( $csv, get_userdata( $luis )->user_email ) && false === strpos( $csv, '.jpg' ) );

	// Marcar desde el panel.
	$correos = array();
	$aviso   = $como( $asociacion, static fn() => ZN_Panel::marcar_participante( wp_get_current_user(), $suyo, $p_ana, 'ganador' ) );
	$ok( 'panel: marcar ganadora la marca', '' === $aviso && '1' === (string) $fila( $p_ana )['ganador'] );
	$ok( 'decisión 4: le llega el aviso privado (a @ejemplo.invalid, R57)', 1 === count( $correos ) && array( strtolower( get_userdata( $ana )->user_email ) ) === ZN_Puertas::destinatarios( $correos[0] ) );
	$aviso = $como( $asociacion, static fn() => ZN_Panel::marcar_participante( wp_get_current_user(), $suyo, $p_ana, 'entregado' ) );
	$ok( 'panel: marcar la entrega', '' === $aviso && null !== $fila( $p_ana )['entregado_en'] );
	$aviso = $como( $asociacion, static fn() => ZN_Panel::marcar_participante( wp_get_current_user(), $suyo, $p_luis, 'entregado' ) );
	$ok( 'panel: entregar a quien no ha ganado: aviso y nada cambia', '' !== $aviso && null === $fila( $p_luis )['entregado_en'] );
	$aviso = $como( $otra_asoc, static fn() => ZN_Panel::marcar_participante( wp_get_current_user(), $suyo, $p_luis, 'ganador' ) );
	$ok( 'panel: otra asociación no puede marcar', '' !== $aviso && '0' === (string) $fila( $p_luis )['ganador'] );
	$aviso = $como( $asociacion, static fn() => ZN_Panel::marcar_participante( wp_get_current_user(), $suyo, $p_otro, 'ganador' ) );
	$ok( 'panel: una participación de otro sorteo con el id de este: aviso y nada cambia', '' !== $aviso && '0' === (string) $fila( $p_otro )['ganador'] );
	$aviso = $como( $asociacion, static fn() => ZN_Panel::marcar_participante( wp_get_current_user(), $suyo, $p_ana, 'no_ganador' ) );
	$ok( 'panel: quitar la marca de ganadora', '' === $aviso && '0' === (string) $fila( $p_ana )['ganador'] && null === $fila( $p_ana )['entregado_en'] );

	// R139: también cuenta en el límite de escrituras.
	$como(
		$asociacion,
		static function () {
			for ( $i = 0; $i < ZN_Limite::REGLAS['escritura']['cuenta'][0]; $i++ ) {
				ZN_API::limite_de_escritura();
			}
		}
	);
	$correos = array();
	$aviso   = $como( $asociacion, static fn() => ZN_Panel::marcar_participante( wp_get_current_user(), $suyo, $p_luis, 'ganador' ) );
	$ok( 'R139: pasado el límite, aviso, sin marcar y sin correo', '' !== $aviso && '0' === (string) $fila( $p_luis )['ganador'] && ! $correos );

	// --- wp-admin: ficha del sorteo (la administración) ---
	$ok( 'wp-admin: hay acciones de admin-post para la lista (ver foto, CSV y marcar)', false !== has_action( 'admin_post_zn_participantes', array( 'ZN_Participaciones', 'atender_admin' ) ) );
	if ( $admin_wp ) {
		$html_admin = $como( $admin_wp, static fn() => ZN_Participaciones::html_lista( get_post( $suyo ), ZN_Participaciones::urls_admin( $suyo ) ) );
		$ok( 'wp-admin: la caja enseña los participantes y la miniatura por admin-post con sello', false !== strpos( $html_admin, 'Luis' ) && false !== strpos( $html_admin, 'admin-post.php' ) && false !== strpos( $html_admin, 'action=zn_participantes' ) && false !== strpos( $html_admin, '_wpnonce' ) && false === strpos( $html_admin, 'zn-privado' ) );
		$aviso = $como( $admin_wp, static fn() => ZN_Participaciones::marcar_desde_gestion( get_post( $suyo ), $p_luis, 'ganador', $admin_wp ) );
		$ok( 'wp-admin: la administración marca ganador', '' === $aviso && '1' === (string) $fila( $p_luis )['ganador'] );
	}
	$html_nadie = $como( $otra_asoc, static fn() => ZN_Participaciones::html_lista( get_post( $suyo ), ZN_Participaciones::urls_admin( $suyo ) ) );
	$ok( 'wp-admin: sin permiso, la caja no enseña nada', false === strpos( $html_nadie, 'Luis' ) );
} finally {
	$claves = array();
	foreach ( array( '127.0.0.1', '::1', '192.0.2.100' ) as $ip ) {
		$_SERVER['REMOTE_ADDR'] = $ip;
		foreach ( $emails as $direccion ) {
			$claves = array_merge( $claves, array_keys( ZN_Limite::claves( 'escritura', $direccion ) ) );
		}
	}
	$_SERVER = $servidor;
	foreach ( $entradas as $entrada ) {
		if ( is_int( $entrada ) && get_post( $entrada ) ) {
			wp_delete_post( $entrada, true );
		}
	}
	foreach ( $cuentas as $id ) {
		if ( get_userdata( $id ) ) {
			wp_delete_user( $id );
		}
	}
	if ( $entradas ) {
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE draw_id IN (' . implode( ',', array_map( 'intval', $entradas ) ) . ')', ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ZN_Participaciones::borrar_fila( $f );
		}
	}
	foreach ( array_chunk( array_unique( $claves ), 100 ) as $trozo ) {
		$wpdb->query( 'DELETE FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', $trozo ) ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
$quedan  = array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) );
$sueltas = $entradas ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Participaciones::tabla() . ' WHERE draw_id IN (' . implode( ',', array_map( 'intval', $entradas ) ) . ')' ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas, %d sorteos, sus participaciones y fotos', count( $cuentas ), count( $entradas ) ), ! $quedan && 0 === $sueltas );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

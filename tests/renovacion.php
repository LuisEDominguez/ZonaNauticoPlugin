<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Refresh token (opción C, Luis: «Sí, hasta 14 días»): POST /login da además un refresh token opaco y largo, de un solo
// uso y ligado a su login (cadena), hasta 14 días desde el login (MAX_RENOVAR). POST /refresh {refreshToken} lo acepta
// aunque el token de acceso haya caducado: da token y refresh nuevos y anula el usado. Si llega uno ya usado, se anula
// toda la cadena de ese login. /logout, cambiar la contraseña o el correo, borrar la cuenta y el cambio de rol anulan
// también los refresh. Sin refreshToken, /refresh sigue como antes. Nunca se renueva un token de acceso caducado.
// Uso: wp eval-file tests/renovacion.php. Crea una cuenta «…-temporal-renovacion» y la borra al terminar.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$servidor = $_SERVER;
$base     = rest_url( ZN_NAMESPACE );
$cuentas  = array();
$claves   = array();
$correos  = array();
$cuenta   = static function ( string $rol_wp ) use ( &$cuentas, &$correos ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-renovacion';
	$clave = wp_generate_password( 20 );
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => $clave, 'role' => $rol_wp, 'first_name' => 'Temporal' ) );
	update_user_meta( $id, 'zn_verificado', '1' );
	$cuentas[] = $id;
	$correos[] = "$login@ejemplo.invalid";
	return array( $id, "$login@ejemplo.invalid", $clave );
};
// Por dentro, sin sesión: POST /login y POST /refresh con el cuerpo dado.
$llamar = static function ( string $ruta, array $cuerpo ) use ( &$claves ) {
	$_SERVER['REMOTE_ADDR'] = '192.0.2.95';
	if ( is_string( $cuerpo['email'] ?? null ) ) {
		$claves = array_merge( $claves, array_keys( ZN_Limite::claves( 'login', $cuerpo['email'] ) ) );
	}
	wp_set_current_user( 0 );
	$peticion = new WP_REST_Request( 'POST', '/' . ZN_NAMESPACE . $ruta );
	$peticion->set_header( 'Content-Type', 'application/json' );
	$peticion->set_body( wp_json_encode( $cuerpo ) );
	$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $peticion ) ), rest_get_server(), $peticion );
	return array( $respuesta->get_status(), $respuesta->get_data() );
};
// Por HTTP de verdad, para lo que depende de la cabecera Authorization.
$http = static function ( string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null ) use ( $base ) {
	$cabeceras = array( 'Content-Type' => 'application/json' );
	if ( $token ) {
		$cabeceras['Authorization'] = "Bearer $token";
	}
	for ( $intento = 0; $intento < 3; $intento++ ) {
		usleep( 400000 );
		$respuesta = wp_remote_request( $base . $ruta, array( 'method' => $metodo, 'timeout' => 60, 'headers' => $cabeceras, 'body' => null === $cuerpo ? null : wp_json_encode( $cuerpo ) ) );
		if ( ! is_wp_error( $respuesta ) && 502 !== wp_remote_retrieve_response_code( $respuesta ) ) {
			break;
		}
		sleep( 15 );
	}
	return is_wp_error( $respuesta ) ? array( 0, null ) : array( wp_remote_retrieve_response_code( $respuesta ), json_decode( wp_remote_retrieve_body( $respuesta ), true ) );
};
$no_autenticado = array( 'message' => 'Unauthenticated.' );
$fila_de        = static fn( string $secreto ) => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Tokens::tabla() . ' WHERE hash = %s', hash( 'sha256', $secreto ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
// ¿Sigue vivo un refresh? (mirando su fila: usarlo por la ruta lo gastaría y el siguiente uso sería un reuso).
$vivo = static function ( string $refresh ) use ( $fila_de ): bool {
	$f = $fila_de( $refresh );
	return $f && 'renovar' === $f->tipo && null === $f->usado && null === $f->anulado && strtotime( $f->caduca . ' UTC' ) > time();
};
// Usarlo de verdad por la ruta (solo con refresh que no se vuelven a usar).
$vale_refresh = static fn( string $refresh ) => 200 === $llamar( '/refresh', array( 'refreshToken' => $refresh ) )[0];

try {
	list( $ana, $email, $clave ) = $cuenta( 'zn_usuario' );
	$entrar = static fn() => $llamar( '/login', array( 'email' => $email, 'password' => $clave ) )[1];

	// --- POST /login da también el refresh token ---
	$login = $entrar();
	$t1    = (string) ( $login['token'] ?? '' );
	$r1    = (string) ( $login['refreshToken'] ?? '' );
	$ok( 'login: {status, token, id, role, refreshToken} (el refresh, al final: la app de hoy lo ignora)', array( 'status', 'token', 'id', 'role', 'refreshToken' ) === array_keys( (array) $login ) );
	$ok( 'el refresh es opaco y largo (64 bytes en base64url), distinto del token', 1 === preg_match( '/^[A-Za-z0-9_-]{86}$/', $r1 ) && $r1 !== $t1 );
	$fila = $fila_de( $r1 );
	$ok( 'se guarda solo su huella, nunca el refresh en claro', $fila && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZN_Tokens::tabla() . ' WHERE hash = %s', $r1 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$ok( 'ligado al login: misma cadena que su token de acceso', $fila && $fila->cadena && $fila->cadena === $fila_de( $t1 )->cadena );
	$ok( 'caduca a los 14 días del login (MAX_RENOVAR)', $fila && abs( strtotime( $fila->caduca . ' UTC' ) - ( strtotime( $fila_de( $t1 )->origen . ' UTC' ) + ZN_Tokens::MAX_RENOVAR ) ) <= 1 );
	$ok( 'el refresh no vale como token de acceso', 0 === ZN_Tokens::validar( $r1 ) );

	// --- POST /refresh {refreshToken} ---
	list( $estado, $cuerpo ) = $llamar( '/refresh', array( 'refreshToken' => $r1 ) );
	$t2 = (string) ( $cuerpo['token'] ?? '' );
	$r2 = (string) ( $cuerpo['refreshToken'] ?? '' );
	$ok( 'refresh con refreshToken (sin token de acceso): 200 con token y refresh nuevos', 200 === $estado && $ana === ZN_Tokens::validar( $t2 ) && 86 === strlen( $r2 ) && $r2 !== $r1 && $ana === ( $cuerpo['id'] ?? null ) );
	$ok( 'el refresh usado queda marcado como usado', null !== $fila_de( $r1 )->usado );
	$ok( 'el token de acceso de antes ya no vale', 0 === ZN_Tokens::validar( $t1 ) );
	$ok( 'los nuevos siguen en la misma cadena, con la fecha del login de siempre (no alarga los 14 días)', $fila_de( $r2 )->cadena === $fila->cadena && $fila_de( $r2 )->caduca === $fila->caduca && $fila_de( $t2 )->origen === $fila_de( $t1 )->origen );

	// También con el token de acceso ya caducado en la cabecera (por HTTP de verdad).
	$wpdb->update( ZN_Tokens::tabla(), array( 'caduca' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'hash' => hash( 'sha256', $t2 ) ) );
	$ok( '(el token de acceso ha caducado)', 0 === ZN_Tokens::validar( $t2 ) );
	list( $estado, $cuerpo ) = $http( 'POST', '/refresh', array( 'refreshToken' => $r2 ), $t2 );
	$t3 = (string) ( $cuerpo['token'] ?? '' );
	$r3 = (string) ( $cuerpo['refreshToken'] ?? '' );
	$ok( 'HTTP: con el token de acceso caducado y el refresh, 200 y sesión nueva', 200 === $estado && $ana === ZN_Tokens::validar( $t3 ) && 86 === strlen( $r3 ) );
	list( $estado ) = $http( 'POST', '/refresh', null, $t2 );
	$ok( 'HTTP: el token de acceso caducado solo, sin refresh, sigue sin renovarse (401)', 401 === $estado );

	// --- Reuso: un refresh ya usado anula toda la cadena de ese login ---
	$otro  = $entrar(); // otro móvil de la misma cuenta: otra cadena
	list( $estado, $cuerpo ) = $llamar( '/refresh', array( 'refreshToken' => $r1 ) );
	$ok( 'reuso de un refresh ya usado: 401 «Unauthenticated.»', 401 === $estado && $no_autenticado === $cuerpo );
	$ok( '…y se anula toda la cadena: su token de acceso de ahora', 0 === ZN_Tokens::validar( $t3 ) );
	$ok( '…y su refresh de ahora', ! $vivo( $r3 ) && ! $vale_refresh( $r3 ) );
	$ok( '…pero no la del otro móvil (otro login)', $ana === ZN_Tokens::validar( (string) $otro['token'] ) && $vivo( (string) $otro['refreshToken'] ) );

	// R87: dos /refresh con el mismo refresh no dan dos sesiones (el segundo es un reuso).
	$l = $entrar();
	list( $e1, $c1 ) = $llamar( '/refresh', array( 'refreshToken' => $l['refreshToken'] ) );
	list( $e2, $c2 ) = $llamar( '/refresh', array( 'refreshToken' => $l['refreshToken'] ) );
	$ok( 'R87: el mismo refresh dos veces: el primero 200, el segundo 401', 200 === $e1 && 401 === $e2 && ! isset( $c2['token'] ) );
	$ok( 'R87: …y no queda ninguna sesión de esa cadena', 0 === ZN_Tokens::validar( (string) $c1['token'] ) && ! $vivo( (string) $c1['refreshToken'] ) );

	// R157: la carrera. Mientras una petición gasta el refresh, otra lo reusa y anula la cadena justo antes de que la
	// primera inserte los tokens nuevos. Lo recién insertado tampoco puede quedar vivo.
	$l          = $entrar();
	$cadena_157 = $fila_de( (string) $l['refreshToken'] )->cadena;
	$hecho_157  = false;
	$carrera    = static function ( $consulta ) use ( $cadena_157, &$hecho_157 ) {
		if ( ! $hecho_157 && 0 === strpos( $consulta, 'INSERT INTO `' . ZN_Tokens::tabla() . '`' ) && false !== strpos( $consulta, $cadena_157 ) ) {
			$hecho_157 = true;
			ZN_Tokens::anular_cadena( $cadena_157 ); // el reuso de la otra petición, justo ahora
		}
		return $consulta;
	};
	add_filter( 'query', $carrera );
	list( $estado, $cuerpo ) = $llamar( '/refresh', array( 'refreshToken' => $l['refreshToken'] ) );
	remove_filter( 'query', $carrera );
	$vivos_157 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZN_Tokens::tabla() . ' WHERE cadena = %s AND anulado IS NULL', $cadena_157 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$ok( 'R157: (la prueba llegó a la carrera)', $hecho_157 );
	$ok( 'R157: si otra petición anula la cadena mientras se renueva, 401 y nada de esa cadena queda vivo', 401 === $estado && ! isset( $cuerpo['token'] ) && 0 === $vivos_157 );

	// H3: {"refreshToken": null} va por la ruta de antes (como si no viniera), no es un refresh malo.
	$l = $entrar();
	list( $estado, $cuerpo ) = $http( 'POST', '/refresh', array( 'refreshToken' => null ), (string) $l['token'] );
	$ok( 'H3: HTTP: refreshToken null con un token de acceso que vale: 200 por la ruta de antes', 200 === $estado && array( 'status', 'token', 'id', 'role' ) === array_keys( (array) $cuerpo ) );

	// --- 14 días ---
	$l      = $entrar();
	$cadena = $fila_de( (string) $l['refreshToken'] )->cadena;
	$wpdb->update( ZN_Tokens::tabla(), array( 'caduca' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'hash' => hash( 'sha256', (string) $l['refreshToken'] ) ) );
	$ok( 'pasados los 14 días, el refresh no vale (401)', ! $vale_refresh( (string) $l['refreshToken'] ) );
	$ok( '(era de otra cadena)', $cadena && $cadena !== $fila->cadena );

	// --- Lo que no es un refresh ---
	foreach ( array( 'un número' => 123, 'un texto corto' => 'abc', 'null' => null, 'un token de acceso' => (string) $otro['token'], 'una lista' => array( 'x' ) ) as $que => $valor ) {
		list( $estado, $cuerpo ) = $llamar( '/refresh', array( 'refreshToken' => $valor ) );
		$ok( "refreshToken con $que: 401", 401 === $estado && $no_autenticado === $cuerpo );
	}
	list( $estado ) = $http( 'GET', '/me', null, (string) $otro['refreshToken'] );
	$ok( 'HTTP: el refresh como Bearer no abre sesión (GET /me: 401)', 401 === $estado );

	// --- Compatibilidad: sin refreshToken, /refresh sigue como antes ---
	$l = $entrar();
	list( $estado, $cuerpo ) = $http( 'POST', '/refresh', null, (string) $l['token'] );
	$ok( 'HTTP: con el token de acceso y sin refresh, 200 {status, token, id, role}, como antes', 200 === $estado && array( 'status', 'token', 'id', 'role' ) === array_keys( (array) $cuerpo ) );
	$ok( '…el token nuevo sigue en su cadena: el refresh del login sigue valiendo', $vivo( (string) $l['refreshToken'] ) );

	// --- /logout anula también el refresh ---
	$l = $entrar();
	list( $estado ) = $http( 'POST', '/logout', null, (string) $l['token'] );
	$ok( 'HTTP: /logout con el token de acceso: 200', 200 === $estado );
	$ok( '…y el refresh de ese login ya no vale', ! $vivo( (string) $l['refreshToken'] ) );
	$l = $entrar();
	list( $estado ) = $llamar( '/logout', array( 'refreshToken' => $l['refreshToken'] ) );
	$ok( '/logout solo con el refresh (el token de acceso ya caducado): 200', 200 === $estado );
	$ok( '…y su cadena queda anulada', 0 === ZN_Tokens::validar( (string) $l['token'] ) && ! $vivo( (string) $l['refreshToken'] ) );
	list( $estado, $cuerpo ) = $llamar( '/logout', array( 'refreshToken' => 'no-vale' ) );
	$ok( '/logout con un refresh que no vale y sin token: 401', 401 === $estado && $no_autenticado === $cuerpo );
	$ok( 'el otro móvil sigue', $ana === ZN_Tokens::validar( (string) $otro['token'] ) );

	// --- Contraseña o correo (todos menos la sesión que hace el cambio, R56), rol y borrado ---
	$quien = $entrar();
	$resto = $entrar();
	ZN_Tokens::anular_todos( $ana, (string) $quien['token'] );
	$ok( 'contraseña o correo cambiados: el refresh de quien hace el cambio sigue (R56)', $vivo( (string) $quien['refreshToken'] ) );
	$ok( '…los de los demás logins, no', ! $vivo( (string) $resto['refreshToken'] ) && ! $vivo( (string) $otro['refreshToken'] ) );
	$l = $entrar();
	get_userdata( $ana )->set_role( 'zn_comercio' );
	$ok( 'cambio de rol: ningún refresh vale', ! $vivo( (string) $l['refreshToken'] ) );
	get_userdata( $ana )->set_role( 'zn_usuario' );
	$l = $entrar();
	wp_delete_user( $ana );
	$ok( 'cuenta borrada: su refresh no vale', ! $vale_refresh( (string) $l['refreshToken'] ) );

	// --- Como mucho MAX_ACTIVOS logins abiertos a la vez también con refresh (R59) ---
	list( $luis, $email, $clave ) = $cuenta( 'zn_usuario' );
	$logins = array();
	for ( $i = 0; $i <= ZN_Tokens::MAX_ACTIVOS; $i++ ) {
		$logins[] = $llamar( '/login', array( 'email' => $email, 'password' => $clave ) )[1];
	}
	$ok( sprintf( 'R59: al hacer el login %d, el refresh del más antiguo deja de valer', ZN_Tokens::MAX_ACTIVOS + 1 ), ! $vivo( (string) $logins[0]['refreshToken'] ) );
	$ok( 'R59: el más nuevo sigue', $vivo( (string) end( $logins )['refreshToken'] ) );
} finally {
	foreach ( array( '127.0.0.1', '::1', '192.0.2.95' ) as $ip ) {
		$_SERVER['REMOTE_ADDR'] = $ip;
		foreach ( $correos as $correo ) {
			$claves = array_merge( $claves, array_keys( ZN_Limite::claves( 'login', $correo ) ) );
		}
	}
	$_SERVER = $servidor;
	wp_set_current_user( 0 );
	foreach ( $cuentas as $id ) {
		if ( get_userdata( $id ) ) {
			wp_delete_user( $id );
		}
	}
	if ( $cuentas ) {
		$wpdb->query( 'DELETE FROM ' . ZN_Tokens::tabla() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $cuentas ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	foreach ( array_chunk( array_unique( $claves ), 100 ) as $trozo ) {
		$wpdb->query( 'DELETE FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', $trozo ) ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
$quedan = array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) );
$tokens = $cuentas ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Tokens::tabla() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $cuentas ) ) . ')' ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas temporales y sus tokens', count( $cuentas ) ), ! $quedan && 0 === $tokens );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

<?php
/**
 * Errores de /zonanautico/v1 con el formato del Laravel, que es el que entiende la app (R12;
 * Zona-Nautico/src/api/cliente.ts y errores.ts):
 * - 401 {"message":"Unauthenticated."}: sin sesión o con un token que no vale (la app cierra la sesión);
 * - 401 {"message":"This action is unauthorized."}: con sesión pero sin permiso (en el Laravel también es 401);
 * - 422 {"message":"…","errors":{"campo":["…"]}}: datos no válidos, campo por campo;
 * - 404 / 405 / 500 solo con {"message":"…"}.
 * Los errores propios de WordPress ({code, message, data}) se traducen a esto al salir.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Errores {

	const NO_AUTENTICADO = 'Unauthenticated.';
	const SIN_PERMISO    = 'This action is unauthorized.';

	// Errores de WordPress que significan «no has iniciado sesión o tu sesión no vale». Los demás 401/403
	// (rest_forbidden, rest_forbidden_context…) dependen de si hay sesión: lo decide is_user_logged_in() (R31).
	const DE_SESION = array( 'rest_not_logged_in', 'rest_cookie_invalid_nonce' );

	// Errores de wp_insert_user y wp_authenticate que se dan como 422 (R30).
	const DE_CUENTAS = array( 'existing_user_login', 'empty_user_login', 'user_login_too_long', 'invalid_username', 'user_nicename_too_long', 'invalid_email', 'empty_email', 'empty_username', 'empty_password', 'incorrect_password' );

	public static function iniciar(): void {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'traducir' ), 10, 3 );
	}

	public static function no_autenticado(): WP_REST_Response {
		return new WP_REST_Response( array( 'message' => self::NO_AUTENTICADO ), 401 );
	}

	public static function sin_permiso(): WP_REST_Response {
		return new WP_REST_Response( array( 'message' => self::SIN_PERMISO ), 401 );
	}

	/**
	 * 422 como el ValidationException del Laravel. $errores = campo => mensaje o lista de mensajes.
	 * El mensaje general es el primero, con «(and N more error[s])» si hay más.
	 */
	public static function no_valido( array $errores ): WP_REST_Response {
		$errores = array_map( static fn( $m ) => array_values( (array) $m ), $errores );
		$total   = array_sum( array_map( 'count', $errores ) );
		$mensaje = reset( $errores )[0] ?? 'The given data was invalid.';
		if ( $total > 1 ) {
			$mensaje .= sprintf( ' (and %d more %s)', $total - 1, 2 === $total ? 'error' : 'errors' );
		}
		return new WP_REST_Response( array( 'message' => $mensaje, 'errors' => $errores ), 422 );
	}

	/** Filtro rest_post_dispatch: solo toca las respuestas de error de nuestra ruta. */
	public static function traducir( $respuesta, $servidor, $peticion ) {
		if ( ! $respuesta instanceof WP_REST_Response || $respuesta->get_status() < 400 || ! self::es_nuestra( $peticion->get_route() ) ) {
			return $respuesta;
		}
		$datos = $respuesta->get_data();
		// Las respuestas que ya montamos con el formato del Laravel no llevan `code`.
		if ( ! is_array( $datos ) || ! isset( $datos['code'] ) ) {
			return $respuesta;
		}

		$codigo  = (string) $datos['code'];
		$detalle = is_array( $datos['data'] ?? null ) ? $datos['data'] : array();
		$estado  = $respuesta->get_status();

		// Red de seguridad (R30): errores de cuentas de WordPress (wp_insert_user, wp_authenticate) sin estado propio
		// saldrían como 500; son datos que no valen. El email repetido, con el texto que la app reconoce.
		if ( 'existing_user_email' === $codigo ) {
			return self::con_cabeceras( self::no_valido( array( 'email' => ZN_Esquema::mensaje( 'unique', 'email' ) ) ), $respuesta );
		}
		if ( in_array( $codigo, self::DE_CUENTAS, true ) ) {
			return self::con_cabeceras( self::no_valido( array( 'email' => 'The given data was invalid.' ) ), $respuesta );
		}
		if ( 'rest_invalid_param' === $codigo || 'rest_missing_callback_param' === $codigo ) {
			return self::con_cabeceras( self::no_valido( self::errores_de_parametros( $codigo, $detalle ) ), $respuesta );
		}
		if ( in_array( $codigo, self::DE_SESION, true ) || ( in_array( $estado, array( 401, 403 ), true ) && ! is_user_logged_in() ) ) {
			return self::con_cabeceras( self::no_autenticado(), $respuesta );
		}
		if ( in_array( $estado, array( 401, 403 ), true ) ) {
			return self::con_cabeceras( self::sin_permiso(), $respuesta );
		}
		if ( 'rest_no_route' === $codigo ) {
			return self::con_cabeceras( self::sin_ruta( $servidor, $peticion ), $respuesta );
		}
		if ( $estado >= 500 ) {
			// Como el Laravel en producción: sin detalles internos.
			return self::con_cabeceras( new WP_REST_Response( array( 'message' => 'Server Error' ), $estado ), $respuesta );
		}
		return self::con_cabeceras( new WP_REST_Response( array( 'message' => (string) ( $datos['message'] ?? '' ) ), $estado ), $respuesta );
	}

	/** WordPress casa las rutas sin distinguir mayúsculas: /ZonaNautico/v1 también es nuestra (R34a). */
	public static function es_nuestra( string $ruta ): bool {
		$ruta = strtolower( $ruta );
		return '/' . ZN_NAMESPACE === $ruta || 0 === strpos( $ruta, '/' . ZN_NAMESPACE . '/' );
	}

	/** {params: {campo: mensaje}, details: …} de WordPress → {campo: [mensaje]}. */
	private static function errores_de_parametros( string $codigo, array $detalle ): array {
		$errores = array();
		if ( 'rest_missing_callback_param' === $codigo ) {
			foreach ( (array) ( $detalle['params'] ?? array() ) as $campo ) {
				$errores[ $campo ] = array( "The $campo field is required." );
			}
		} else {
			foreach ( (array) ( $detalle['params'] ?? array() ) as $campo => $mensaje ) {
				$errores[ $campo ] = array( (string) $mensaje );
			}
		}
		return $errores ?: array( 'request' => array( 'The given data was invalid.' ) );
	}

	/**
	 * WordPress da 404 tanto si la ruta no existe como si existe con otro método. El Laravel distingue:
	 * 405 con los métodos admitidos, o 404 «could not be found».
	 */
	private static function sin_ruta( WP_REST_Server $servidor, WP_REST_Request $peticion ): WP_REST_Response {
		$ruta   = $peticion->get_route();
		$camino = 'wp-json' . $ruta;
		foreach ( $servidor->get_routes( ZN_NAMESPACE ) as $patron => $manejadores ) {
			if ( ! preg_match( '@^' . $patron . '$@i', $ruta ) ) {
				continue;
			}
			$metodos = array();
			foreach ( $manejadores as $manejador ) {
				$metodos = array_merge( $metodos, array_keys( array_filter( (array) $manejador['methods'] ) ) );
			}
			$metodos = array_values( array_unique( $metodos ) );
			if ( in_array( 'GET', $metodos, true ) && ! in_array( 'HEAD', $metodos, true ) ) {
				$metodos[] = 'HEAD';
			}
			return new WP_REST_Response(
				array( 'message' => sprintf( 'The %s method is not supported for route %s. Supported methods: %s.', $peticion->get_method(), $camino, implode( ', ', $metodos ) ) ),
				405
			);
		}
		return new WP_REST_Response( array( 'message' => "The route $camino could not be found." ), 404 );
	}

	/** Conserva las cabeceras de la respuesta original (CORS, Allow…). */
	private static function con_cabeceras( WP_REST_Response $nueva, WP_REST_Response $original ): WP_REST_Response {
		$nueva->set_headers( $original->get_headers() );
		return $nueva;
	}
}

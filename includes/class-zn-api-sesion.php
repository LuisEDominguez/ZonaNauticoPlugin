<?php
/**
 * Entrar y salir de la app: POST /login, POST /refresh y POST /logout (R30, R38, R39; token de R32).
 *
 * - POST /login {email, password}, solo en el cuerpo JSON (nunca en la dirección, H8). Responde como el
 *   AuthController del Laravel (Zona-Nautico/src/api/auth.ts): 200 {status: "success", token, id (número),
 *   role (texto)}; credenciales malas → 401 {status: "error", message: "Unauthorized"}; correo sin verificar →
 *   401 {status: "email-not-verified", …}. Las respuestas se montan a mano: un WP_Error 401 saldría como
 *   «Unauthenticated.» y la app perdería el `status` (R30).
 * - Orden: primero se valida el formato (422, sin contar el intento); después ZN_Limite::intentar() (429 sin
 *   mirar nada más, R59/R75); después la contraseña. Que la cuenta no exista, no sea de la app (R38) o la
 *   contraseña sea mala da la misma respuesta, y siempre se comprueba una contraseña, para que el tiempo
 *   tampoco lo delate (con una huella de relleno si no hay cuenta). «Sin verificar» solo se dice con la
 *   contraseña buena.
 * - Sin wp_authenticate() ni wp_signon(): las cuentas de la app no pueden entrar por wp-login.php (R22, R38).
 *   Tampoco se abre sesión de WordPress ni se ponen cookies; al entrar se cierran las que tuviera la cuenta (R39).
 * - POST /refresh: con un token que vale, uno nuevo (misma respuesta que el login) y el anterior anulado. Con
 *   un token caducado hay que entrar de nuevo. POST /logout: anula el token de la petición (los de otros
 *   móviles de la misma cuenta siguen). Los dos, solo con el token: sin él, 401 «Unauthenticated.».
 * - Respuestas con token: Cache-Control no-store (R49).
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Sesion {

	// Huella de una contraseña al azar que nadie conoce: se compara con ella cuando no hay cuenta, para tardar lo mismo.
	const HUELLA_DE_RELLENO = '$wp$2y$10$B2wJ6YFi6IbosLiCG0.yP.vpPbakYN.1PcAGODTKqo/No0ByWWbfi';

	public static function registrar_rutas(): void {
		register_rest_route(
			ZN_NAMESPACE,
			'/login',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'entrar' ),
				'permission_callback' => '__return_true',
			)
		);
		foreach ( array( 'refresh' => 'renovar', 'logout' => 'salir' ) as $ruta => $metodo ) {
			register_rest_route(
				ZN_NAMESPACE,
				"/$ruta",
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $metodo ),
					'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
				)
			);
		}
	}

	public static function entrar( WP_REST_Request $peticion ): WP_REST_Response {
		// Solo el cuerpo: lo que venga en la dirección se ignora (H8).
		$cuerpo = $peticion->get_json_params() ?: $peticion->get_body_params();
		$cuerpo = is_array( $cuerpo ) ? $cuerpo : array();

		$errores = array();
		$email   = ZN_Esquema::comprobar( ZN_Esquema::campos( 'cuenta' )['email'], $cuerpo['email'] ?? null, 'email' );
		if ( isset( $email['errores'] ) ) {
			$errores += $email['errores'];
		}
		$clave = $cuerpo['password'] ?? null;
		if ( null === $clave || '' === $clave ) {
			$errores['password'] = array( ZN_Esquema::mensaje( 'required', 'password' ) );
		} elseif ( ! is_string( $clave ) ) {
			$errores['password'] = array( ZN_Esquema::mensaje( 'string', 'password' ) );
		}
		if ( $errores ) {
			return ZN_Errores::no_valido( $errores );
		}
		$email = (string) $email['valores']['user_email'];

		$espera = ZN_Limite::intentar( 'login', $email );
		if ( null !== $espera ) {
			return ZN_Limite::demasiados( $espera );
		}

		$cuenta = get_user_by( 'email', $email );
		$de_app = $cuenta && ZN_Roles::rol_app( $cuenta );
		// Una sola comprobación en todos los casos (R38).
		$vale = wp_check_password( $clave, $de_app ? $cuenta->user_pass : self::HUELLA_DE_RELLENO, $de_app ? $cuenta->ID : 0 );
		if ( ! $de_app || ! $vale ) {
			return self::sin_cache( new WP_REST_Response( array( 'status' => 'error', 'message' => 'Unauthorized' ), 401 ) );
		}
		if ( '1' !== (string) get_user_meta( $cuenta->ID, 'zn_verificado', true ) ) {
			return self::sin_cache( new WP_REST_Response( array( 'status' => 'email-not-verified', 'message' => 'Email not verified' ), 401 ) );
		}

		ZN_Limite::olvidar( 'login', $email );
		WP_Session_Tokens::get_instance( $cuenta->ID )->destroy_all();
		return self::con_token( $cuenta );
	}

	public static function renovar(): WP_REST_Response {
		$token = self::token_de_la_sesion();
		if ( null === $token ) {
			return ZN_Errores::no_autenticado();
		}
		$cuenta    = wp_get_current_user();
		$respuesta = self::con_token( $cuenta );
		if ( 200 === $respuesta->get_status() ) {
			ZN_Tokens::anular( $token );
		}
		return $respuesta;
	}

	public static function salir(): WP_REST_Response {
		$token = self::token_de_la_sesion();
		if ( null === $token ) {
			return ZN_Errores::no_autenticado();
		}
		ZN_Tokens::anular( $token );
		return self::sin_cache( new WP_REST_Response( array( 'message' => 'Successfully logged out' ) ) );
	}

	/** El token de la petición si es el que ha dado la sesión (no una cookie de wp-admin); si no, null. */
	private static function token_de_la_sesion(): ?string {
		$id = get_current_user_id();
		return $id ? ZN_Tokens::token_en_uso( $id ) : null;
	}

	/** 200 {status, token, id, role} con un token nuevo para la cuenta. */
	private static function con_token( WP_User $cuenta ): WP_REST_Response {
		$token = ZN_Tokens::emitir( $cuenta->ID );
		if ( null === $token ) {
			return ZN_Errores::no_autenticado();
		}
		return self::sin_cache(
			new WP_REST_Response(
				array(
					'status' => 'success',
					'token'  => $token,
					'id'     => (int) $cuenta->ID,
					'role'   => ZN_Roles::rol_app( $cuenta ),
				)
			)
		);
	}

	private static function sin_cache( WP_REST_Response $respuesta ): WP_REST_Response {
		$respuesta->header( 'Cache-Control', 'no-store, private' );
		return $respuesta;
	}
}

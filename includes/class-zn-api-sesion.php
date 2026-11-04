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
 *   tampoco lo delate (con una huella de relleno si no hay cuenta, hecha con el coste de este servidor, R85). «Sin verificar» solo se dice con la
 *   contraseña buena.
 * - Sin wp_authenticate() ni wp_signon(): las cuentas de la app no pueden entrar por wp-login.php (R22, R38).
 *   Tampoco se abre sesión de WordPress ni se ponen cookies; al entrar se cierran las que tuviera la cuenta (R39),
 *   salvo las del panel de asociación y federación (R98).
 * - El login da también `refreshToken` (opción C, ZN_Tokens): opaco, de un solo uso y ligado a ese login (cadena),
 *   hasta MAX_RENOVAR (14 días) desde el login. Va al final de la respuesta: la app de antes lo ignora.
 * - POST /refresh {refreshToken}: vale aunque el token de acceso haya caducado (no se mira); da token y refresh
 *   nuevos de la misma cadena y gasta el usado. Uno ya usado es un reuso: se anula toda la cadena y 401. Sin
 *   refreshToken, como antes: con un token que vale, uno nuevo (sin refresh) y el anterior anulado; con uno
 *   caducado hay que entrar de nuevo, y también pasados ZN_Tokens::MAX_RENOVAR desde el login (R86). En los dos
 *   casos, de dos /refresh a la vez con lo mismo solo uno da sesión (R87).
 * - POST /logout: con el token de acceso, anula su cadena (ese login: su token y su refresh); los de otros móviles
 *   de la misma cuenta siguen. Si el token de acceso ya ha caducado, vale {refreshToken} en el cuerpo. Sin ninguno
 *   de los dos, 401 «Unauthenticated.».
 * - Respuestas con token: Cache-Control no-store (R49).
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Sesion {

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
					// La sesión se mira dentro: con refreshToken, el token de acceso puede haber caducado.
					'permission_callback' => '__return_true',
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
		// Una cuenta dada de baja en el panel (2b) responde igual que una contraseña mala.
		$de_app = $cuenta && ZN_Roles::rol_app( $cuenta ) && ! ZN_Panel::desactivada( $cuenta->ID );
		// Una sola comprobación en todos los casos (R38).
		$vale = wp_check_password( $clave, $de_app ? $cuenta->user_pass : self::huella_de_relleno(), $de_app ? $cuenta->ID : 0 );
		if ( ! $de_app || ! $vale ) {
			return self::sin_cache( new WP_REST_Response( array( 'status' => 'error', 'message' => 'Unauthorized' ), 401 ) );
		}
		if ( '1' !== (string) get_user_meta( $cuenta->ID, 'zn_verificado', true ) ) {
			return self::sin_cache( new WP_REST_Response( array( 'status' => 'email-not-verified', 'message' => 'Email not verified' ), 401 ) );
		}

		ZN_Limite::olvidar( 'login', $email );
		// R39; salvo la sesión del panel de asociación y federación (R98, decisión de Luis): su cookie no vale aquí.
		if ( ! ZN_Panel::puede_entrar( $cuenta ) ) {
			WP_Session_Tokens::get_instance( $cuenta->ID )->destroy_all();
		}
		return self::con_token( $cuenta, null, null, true );
	}

	public static function renovar( WP_REST_Request $peticion ): WP_REST_Response {
		$cuerpo = self::cuerpo( $peticion );
		if ( array_key_exists( 'refreshToken', $cuerpo ) ) {
			$uso    = is_string( $cuerpo['refreshToken'] ) ? ZN_Tokens::usar_renovacion( $cuerpo['refreshToken'] ) : null;
			$cuenta = $uso ? get_userdata( $uso['user_id'] ) : false;
			return $cuenta ? self::con_token( $cuenta, $uso['origen'], $uso['cadena'], true ) : ZN_Errores::no_autenticado();
		}
		// Sin refreshToken, como antes (R86, R87).
		$token = ZN_API::con_sesion_de_la_app() ? self::token_de_la_sesion() : null;
		if ( null === $token ) {
			return ZN_Errores::no_autenticado();
		}
		$origen = ZN_Tokens::origen( $token );
		$cadena = ZN_Tokens::cadena_de( $token );
		if ( null === $origen || $origen + ZN_Tokens::MAX_RENOVAR < time() || ! ZN_Tokens::anular( $token ) ) {
			return ZN_Errores::no_autenticado();
		}
		return self::con_token( wp_get_current_user(), $origen, $cadena );
	}

	public static function salir( WP_REST_Request $peticion ): WP_REST_Response {
		$token   = ZN_API::con_sesion_de_la_app() ? self::token_de_la_sesion() : null;
		$refresh = self::cuerpo( $peticion )['refreshToken'] ?? null;
		$cadena  = null !== $token ? ZN_Tokens::cadena_de( $token ) : ( is_string( $refresh ) ? ZN_Tokens::cadena_de_renovacion( $refresh ) : null );
		if ( null === $token && null === $cadena ) {
			return ZN_Errores::no_autenticado();
		}
		if ( null !== $token ) {
			ZN_Tokens::anular( $token );
		}
		if ( null !== $cadena ) {
			ZN_Tokens::anular_cadena( $cadena );
		}
		return self::sin_cache( new WP_REST_Response( array( 'message' => 'Successfully logged out' ) ) );
	}

	/** El cuerpo JSON (o de formulario) como lista; nunca lo de la dirección (H8). */
	private static function cuerpo( WP_REST_Request $peticion ): array {
		$cuerpo = $peticion->get_json_params() ?: $peticion->get_body_params();
		return is_array( $cuerpo ) ? $cuerpo : array();
	}

	/** El token de la petición si es el que ha dado la sesión (no una cookie de wp-admin); si no, null. */
	private static function token_de_la_sesion(): ?string {
		$id = get_current_user_id();
		return $id ? ZN_Tokens::token_en_uso( $id ) : null;
	}

	/**
	 * 200 {status, token, id, role} con un token nuevo para la cuenta y, con $con_refresh, también `refreshToken` de la
	 * misma cadena ($cadena, o una nueva en un login). $origen: el login del que viene (null: es un login, ahora).
	 */
	private static function con_token( WP_User $cuenta, ?int $origen = null, ?string $cadena = null, bool $con_refresh = false ): WP_REST_Response {
		$origen = $origen ?? time();
		$cadena = $cadena ?? ( $con_refresh ? ZN_Tokens::nueva_cadena() : null );
		$token  = ZN_Tokens::emitir( $cuenta->ID, $origen, $cadena );
		if ( null === $token ) {
			return ZN_Errores::no_autenticado();
		}
		$datos = array(
			'status' => 'success',
			'token'  => $token,
			'id'     => (int) $cuenta->ID,
			'role'   => ZN_Roles::rol_app( $cuenta ),
		);
		if ( $con_refresh && null !== $cadena ) {
			$datos['refreshToken'] = ZN_Tokens::emitir_renovacion( $cuenta->ID, $cadena, $origen );
		}
		return self::sin_cache( new WP_REST_Response( $datos ) );
	}

	/**
	 * Huella de una contraseña al azar que nadie conoce, para comparar cuando no hay cuenta y tardar lo mismo. Se
	 * hace con wp_hash_password() la primera vez y se rehace si cambia el coste del servidor (p. ej. PHP 8.4, R85).
	 */
	private static function huella_de_relleno(): string {
		$huella = (string) get_option( 'zn_huella_relleno', '' );
		if ( '' === $huella || wp_password_needs_rehash( $huella ) ) {
			$huella = wp_hash_password( wp_generate_password( 32, true, true ) );
			update_option( 'zn_huella_relleno', $huella, false );
		}
		return $huella;
	}

	private static function sin_cache( WP_REST_Response $respuesta ): WP_REST_Response {
		$respuesta->header( 'Cache-Control', 'no-store, private' );
		return $respuesta;
	}
}

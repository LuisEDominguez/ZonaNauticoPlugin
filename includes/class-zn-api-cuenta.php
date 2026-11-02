<?php
/**
 * Alta, verificación del correo y recuperación de la contraseña (R30, R53, R62; H16, H17, H24). Las respuestas
 * son las del Laravel que espera la app (Zona-Nautico/src/api/auth.ts y errores.ts), montadas a mano.
 *
 * - POST /register {name, email, password, phone?, latitude?, longitude?}: 201 {status: "success"}. Siempre con el
 *   rol «user» (el que mande la app se ignora; dar de alta otros roles, con token, va con las escrituras). Valida
 *   con el esquema `cuenta` (email repetido → 422 «The email has already been taken.», que la app reconoce). El
 *   nombre entero va en first_name y los apellidos vacíos (R53). Manda el correo de verificación.
 * - GET /verify?id=&token=: el enlace del correo. Token al azar (32 bytes), del que solo se guarda la huella, que
 *   caduca a las VERIFICAR_HORAS y vale una vez (H16: nada de sha1 del correo). Responde con una página sencilla;
 *   con &format=json, 200 {status: "success"} o 400 {status: "error"}.
 * - GET /send-email?email=: reenvía el correo de verificación. Siempre 200 {status: "success"}, exista o no la
 *   cuenta o esté ya verificada, para no delatar cuentas (H17; la app acepta 200 y 400).
 * - POST /reset-password-code {email}: siempre 200 {status: "success"}; si la cuenta es de la app, le llega por
 *   correo un código de 6 cifras que caduca a los 15 minutos y admite 5 intentos (H24). Sin Bearer compartido.
 * - POST /reset-password {email, code, password}: 200 {status: "success"}; código que no vale (mal escrito,
 *   gastado, caducado o de una cuenta que no existe) → 400 {status: "error", message: "Invalid code"}, no 422 (R30).
 *   Cambia la contraseña con wp_set_password() (que cierra las sesiones de la cuenta, R32) y deja el correo
 *   verificado: quien tiene el código lo ha recibido en él.
 * - Todas: primero el formato (422, sin contar el intento), luego ZN_Limite (429) y luego lo demás.
 * - Los correos salen con wp_mail(): fuera de producción, solo a @ejemplo.invalid (R57).
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Cuenta {

	const VERIFICAR_HORAS = 48;
	const CODIGO_MINUTOS  = 15;
	const CODIGO_INTENTOS = 5;

	public static function iniciar(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'registrar_rutas' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'pagina_de_verificacion' ), 10, 4 );
	}

	public static function registrar_rutas(): void {
		$rutas = array(
			'/register'            => array( WP_REST_Server::CREATABLE, 'registrar' ),
			'/verify'              => array( WP_REST_Server::READABLE, 'verificar' ),
			'/send-email'          => array( WP_REST_Server::READABLE, 'reenviar' ),
			'/reset-password-code' => array( WP_REST_Server::CREATABLE, 'pedir_codigo' ),
			'/reset-password'      => array( WP_REST_Server::CREATABLE, 'cambiar_contrasena' ),
		);
		foreach ( $rutas as $ruta => list( $metodos, $metodo ) ) {
			register_rest_route(
				ZN_NAMESPACE,
				$ruta,
				array(
					'methods'             => $metodos,
					'callback'            => array( __CLASS__, $metodo ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	public static function registrar( WP_REST_Request $peticion ) {
		$espera = ZN_Limite::intentar( 'registro' );
		if ( null !== $espera ) {
			return ZN_Limite::demasiados( $espera );
		}
		$cuerpo = self::cuerpo( $peticion );
		// El código solo es de la recuperación; el rol no lo elige la app.
		unset( $cuerpo['code'] );
		$validado = ZN_Esquema::validar( 'cuenta', $cuerpo );
		if ( $validado['errores'] ) {
			return ZN_Errores::no_valido( $validado['errores'] );
		}
		$valores = $validado['valores'];
		$login   = 'zn' . bin2hex( random_bytes( 8 ) );
		$id      = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $valores['user_email'],
				'user_pass'    => $valores['user_pass'],
				'role'         => 'zn_usuario',
				'first_name'   => $valores['first_name'],
				'last_name'    => '',
				'display_name' => $login,
				'nickname'     => $login,
			)
		);
		if ( is_wp_error( $id ) ) {
			// Lo traduce ZN_Errores (p. ej. un email repetido entre la comprobación y el alta → 422).
			return $id;
		}
		foreach ( array( 'phone', 'latitude', 'longitude' ) as $campo ) {
			if ( isset( $valores[ $campo ] ) ) {
				update_user_meta( $id, $campo, $valores[ $campo ] );
			}
		}
		self::mandar_verificacion( get_userdata( $id ) );
		return new WP_REST_Response( array( 'status' => 'success' ), 201 );
	}

	public static function verificar( WP_REST_Request $peticion ): WP_REST_Response {
		$id    = (int) $peticion->get_param( 'id' );
		$token = (string) $peticion->get_param( 'token' );
		if ( ! self::token_de_verificacion_vale( $id, $token ) ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Invalid token' ), 400 );
		}
		update_user_meta( $id, 'zn_verificado', '1' );
		delete_user_meta( $id, 'zn_verificar' );
		delete_user_meta( $id, 'zn_verificar_caduca' );
		return new WP_REST_Response( array( 'status' => 'success' ) );
	}

	/** ¿Es ese el token de verificación vigente de esa cuenta de la app? */
	public static function token_de_verificacion_vale( int $id, string $token ): bool {
		$cuenta = $id > 0 ? get_userdata( $id ) : false;
		if ( ! $cuenta || ! ZN_Roles::rol_app( $cuenta ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) {
			return false;
		}
		$huella = (string) get_user_meta( $id, 'zn_verificar', true );
		$caduca = (int) get_user_meta( $id, 'zn_verificar_caduca', true );
		return '' !== $huella && $caduca > time() && hash_equals( $huella, hash( 'sha256', $token ) );
	}

	public static function reenviar( WP_REST_Request $peticion ): WP_REST_Response {
		list( $email, $errores ) = self::email( $peticion->get_param( 'email' ) );
		if ( $errores ) {
			return ZN_Errores::no_valido( $errores );
		}
		$espera = ZN_Limite::intentar( 'reenviar', $email );
		if ( null !== $espera ) {
			return ZN_Limite::demasiados( $espera );
		}
		$cuenta = self::cuenta_de_la_app( $email );
		if ( $cuenta && '1' !== (string) get_user_meta( $cuenta->ID, 'zn_verificado', true ) ) {
			self::mandar_verificacion( $cuenta );
		}
		return new WP_REST_Response( array( 'status' => 'success' ) );
	}

	public static function pedir_codigo( WP_REST_Request $peticion ): WP_REST_Response {
		list( $email, $errores ) = self::email( self::cuerpo( $peticion )['email'] ?? null );
		if ( $errores ) {
			return ZN_Errores::no_valido( $errores );
		}
		$espera = ZN_Limite::intentar( 'recuperar', $email );
		if ( null !== $espera ) {
			return ZN_Limite::demasiados( $espera );
		}
		$cuenta = self::cuenta_de_la_app( $email );
		if ( $cuenta ) {
			$codigo = sprintf( '%06d', random_int( 0, 999999 ) );
			update_user_meta( $cuenta->ID, 'zn_codigo', self::huella_codigo( $cuenta->ID, $codigo ) );
			update_user_meta( $cuenta->ID, 'zn_codigo_caduca', time() + self::CODIGO_MINUTOS * MINUTE_IN_SECONDS );
			update_user_meta( $cuenta->ID, 'zn_codigo_fallos', 0 );
			wp_mail(
				$cuenta->user_email,
				'Zona Náutico: código para cambiar la contraseña',
				"Hola:\n\nTu código para cambiar la contraseña en la app Zona Náutico es:\n\n$codigo\n\nCaduca en " . self::CODIGO_MINUTOS . " minutos. Si no lo has pedido tú, no hagas nada: tu contraseña no cambia.\n"
			);
		}
		return new WP_REST_Response( array( 'status' => 'success' ) );
	}

	public static function cambiar_contrasena( WP_REST_Request $peticion ): WP_REST_Response {
		$cuerpo                  = self::cuerpo( $peticion );
		list( $email, $errores ) = self::email( $cuerpo['email'] ?? null );
		$clave                   = ZN_Esquema::comprobar( ZN_Esquema::campos( 'cuenta' )['password'], $cuerpo['password'] ?? null, 'password' );
		$errores                += $clave['errores'] ?? array();
		if ( $errores ) {
			return ZN_Errores::no_valido( $errores );
		}
		$espera = ZN_Limite::intentar( 'codigo', $email );
		if ( null !== $espera ) {
			return ZN_Limite::demasiados( $espera );
		}
		$cuenta = self::cuenta_de_la_app( $email );
		$codigo = $cuerpo['code'] ?? null;
		$codigo = is_int( $codigo ) ? sprintf( '%06d', $codigo ) : ( is_string( $codigo ) ? trim( $codigo ) : '' );
		if ( ! $cuenta || ! self::codigo_vale( $cuenta->ID, $codigo ) ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Invalid code' ), 400 );
		}
		foreach ( array( 'zn_codigo', 'zn_codigo_caduca', 'zn_codigo_fallos', 'zn_verificar', 'zn_verificar_caduca' ) as $clave_meta ) {
			delete_user_meta( $cuenta->ID, $clave_meta );
		}
		update_user_meta( $cuenta->ID, 'zn_verificado', '1' );
		wp_set_password( $clave['valores']['user_pass'], $cuenta->ID );
		ZN_Limite::olvidar( 'codigo', $email );
		return new WP_REST_Response( array( 'status' => 'success' ) );
	}

	/**
	 * Filtro rest_pre_serve_request: el enlace del correo se abre en el navegador, así que GET /verify responde con
	 * una página (salvo con &format=json). Sin datos de la cuenta.
	 */
	public static function pagina_de_verificacion( $servida, $resultado, $peticion, $servidor ) {
		if ( $servida || ! $peticion instanceof WP_REST_Request || '/' . ZN_NAMESPACE . '/verify' !== strtolower( $peticion->get_route() ) || 'json' === $peticion->get_param( 'format' ) ) {
			return $servida;
		}
		$bien = $resultado instanceof WP_HTTP_Response && 200 === $resultado->get_status();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store, private' );
		$titulo = $bien ? 'Cuenta verificada' : 'Este enlace no vale';
		$texto  = $bien ? 'Ya puedes entrar en la app Zona Náutico con tu correo y tu contraseña.' : 'El enlace no vale o ha caducado. Desde la app puedes pedir que te mandemos otro.';
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
			. '<title>' . esc_html( $titulo ) . '</title>'
			. '<style>body{margin:0;font-family:system-ui,sans-serif;background:#f0f4f8;color:#12263a;display:flex;min-height:100vh;align-items:center;justify-content:center}main{background:#fff;border-radius:12px;padding:32px 24px;max-width:420px;margin:16px;text-align:center}h1{color:#00457c;font-size:1.4rem}</style>'
			. '</head><body><main><h1>' . esc_html( $titulo ) . '</h1><p>' . esc_html( $texto ) . '</p></main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
		return true;
	}

	/** Token nuevo de verificación (el anterior deja de valer) y el correo con el enlace. */
	private static function mandar_verificacion( WP_User $cuenta ): void {
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		update_user_meta( $cuenta->ID, 'zn_verificar', hash( 'sha256', $token ) );
		update_user_meta( $cuenta->ID, 'zn_verificar_caduca', time() + self::VERIFICAR_HORAS * HOUR_IN_SECONDS );
		$enlace = add_query_arg(
			array(
				'id'    => $cuenta->ID,
				'token' => $token,
			),
			rest_url( ZN_NAMESPACE . '/verify' )
		);
		wp_mail(
			$cuenta->user_email,
			'Zona Náutico: confirma tu correo',
			"Hola:\n\nPara terminar de crear tu cuenta en la app Zona Náutico, abre este enlace:\n\n$enlace\n\nCaduca en " . self::VERIFICAR_HORAS . " horas. Si no has creado tú la cuenta, no hagas nada.\n"
		);
	}

	/** Código bueno, vigente y con intentos: true. Uno malo suma un fallo; al 5.º, el código deja de valer (H24). */
	private static function codigo_vale( int $id, string $codigo ): bool {
		$huella = (string) get_user_meta( $id, 'zn_codigo', true );
		if ( '' === $huella ) {
			return false;
		}
		if ( (int) get_user_meta( $id, 'zn_codigo_caduca', true ) <= time() ) {
			delete_user_meta( $id, 'zn_codigo' );
			return false;
		}
		if ( preg_match( '/^\d{6}$/', $codigo ) && hash_equals( $huella, self::huella_codigo( $id, $codigo ) ) ) {
			return true;
		}
		$fallos = (int) get_user_meta( $id, 'zn_codigo_fallos', true ) + 1;
		if ( $fallos >= self::CODIGO_INTENTOS ) {
			delete_user_meta( $id, 'zn_codigo' );
		}
		update_user_meta( $id, 'zn_codigo_fallos', $fallos );
		return false;
	}

	/** Con la clave secreta del sitio: un código de 6 cifras se sacaría probando si solo fuera un SHA-256. */
	private static function huella_codigo( int $id, string $codigo ): string {
		return hash_hmac( 'sha256', "$id|$codigo", wp_salt( 'auth' ) );
	}

	/** [email limpio o '', errores 422] con la regla del esquema de cuenta. */
	private static function email( $valor ): array {
		$resultado = ZN_Esquema::comprobar( ZN_Esquema::campos( 'cuenta' )['email'], $valor, 'email' );
		return isset( $resultado['errores'] ) ? array( '', $resultado['errores'] ) : array( (string) $resultado['valores']['user_email'], array() );
	}

	private static function cuenta_de_la_app( string $email ): ?WP_User {
		$cuenta = get_user_by( 'email', $email );
		return $cuenta && ZN_Roles::rol_app( $cuenta ) ? $cuenta : null;
	}

	/** Solo el cuerpo (JSON o formulario): lo que venga en la dirección se ignora (H8). */
	private static function cuerpo( WP_REST_Request $peticion ): array {
		$cuerpo = $peticion->get_json_params() ?: $peticion->get_body_params();
		return is_array( $cuerpo ) ? $cuerpo : array();
	}
}

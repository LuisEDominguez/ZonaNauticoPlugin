<?php
/**
 * Alta, verificación del correo y recuperación de la contraseña (R30, R53, R62; H16, H17, H24). Las respuestas
 * son las del Laravel que espera la app (Zona-Nautico/src/api/auth.ts y errores.ts), montadas a mano.
 *
 * - POST /register {name, email, password, phone?}: 201 {status: "success"}. Siempre con el
 *   rol «user» (el que mande la app se ignora; dar de alta otros roles, con token, va con las escrituras). Valida
 *   con el esquema `cuenta` (email repetido → 422 «The email has already been taken.», que la app reconoce). El
 *   nombre entero va en first_name y los apellidos vacíos (R53). La ubicación no se guarda: la app no la manda y
 *   está sin decidir (RGPD, R91). Manda el correo de verificación.
 * - GET /verify?id=&token=: el enlace del correo. Token al azar (32 bytes), del que solo se guarda la huella, que
 *   caduca a las VERIFICAR_HORAS y vale una vez (H16: nada de sha1 del correo). Abrirlo NO verifica (R88): los
 *   filtros de correo (Safe Links…) abren los enlaces solos y verificarían la cuenta que otro creó con ese correo.
 *   Pinta una página con el botón «Confirmar mi correo», que hace POST /verify con el id y el token: ese sí
 *   verifica. Con &format=json: GET → 200 {status: "pending"}, POST → 200 {status: "success"}; si el token no
 *   vale, 400 {status: "error"}.
 * - GET /send-email?email=: reenvía el correo de verificación. Siempre 200 {status: "success"}, exista o no la
 *   cuenta o esté ya verificada, para no delatar cuentas (H17; la app acepta 200 y 400).
 * - POST /reset-password-code {email}: siempre 200 {status: "success"}; si la cuenta es de la app, le llega por
 *   correo un código de 6 cifras que caduca a los 15 minutos y admite 5 intentos (H24). Sin Bearer compartido.
 * - POST /reset-password {email, code, password}: 200 {status: "success"}; código que no vale (mal escrito,
 *   gastado, caducado o de una cuenta que no existe) → 400 {status: "error", message: "Invalid code"}, no 422 (R30).
 *   Cambia la contraseña con wp_set_password() (que cierra las sesiones de la cuenta, R32) y deja el correo
 *   verificado: quien tiene el código lo ha recibido en él.
 * - Reenvío y recuperación: primero el formato (422, sin contar el intento), luego ZN_Limite (429) y luego lo
 *   demás. El registro mira primero el límite (por IP): así nadie prueba sin freno qué correos ya tienen cuenta (R92).
 * - Que una cuenta existe se puede saber por el tiempo de respuesta de send-email y reset-password-code (mandar un
 *   correo tarda): aceptado por Luis (R90), porque el registro ya lo dice con su 422 y la app lo necesita.
 * - Cambio de correo desde PATCH /user (R69): el nuevo queda pendiente (zn_email_nuevo) y le llega un enlace a
 *   /verify-email?id=&token=, igual que el de /verify (token al azar del que solo se guarda la huella, VERIFICAR_HORAS,
 *   una vez; abrirlo pinta el botón y el POST confirma). Al viejo le llega un aviso sin el enlace. Al confirmar se
 *   cambia user_email (si nadie se lo ha quedado entre medias y la cuenta no está de baja) y se anulan todos sus
 *   tokens: se vuelve a entrar con el correo nuevo; al viejo le llega otro aviso (R107). Si tras guardar resulta que
 *   otra cuenta tiene el mismo correo (dos peticiones a la vez), se deshace (R108). Cambiar la contraseña anula el
 *   cambio pendiente.
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
		// Una contraseña nueva (desde la app o con el código) anula el cambio de correo pendiente.
		add_action( 'wp_set_password', static fn( $clave, $id ) => self::anular_cambio_de_correo( (int) $id ), 10, 2 );
	}

	public static function registrar_rutas(): void {
		$rutas = array(
			'/register'            => array( WP_REST_Server::CREATABLE, 'registrar' ),
			'/verify'              => array( 'GET, POST', 'verificar' ),
			'/verify-email'        => array( 'GET, POST', 'confirmar_correo' ),
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
		// El código solo es de la recuperación; el rol no lo elige la app; la ubicación, sin decidir (R91).
		unset( $cuerpo['code'], $cuerpo['latitude'], $cuerpo['longitude'] );
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
		if ( isset( $valores['zn_phone'] ) ) {
			update_user_meta( $id, 'zn_phone', $valores['zn_phone'] );
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
		// Abrir el enlace no verifica (R88): solo el botón de la página.
		if ( 'POST' !== $peticion->get_method() ) {
			return new WP_REST_Response( array( 'status' => 'pending' ) );
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

	/**
	 * Correo nuevo pedido desde PATCH /user (ya comprobados la contraseña actual y que no es de otra cuenta): queda
	 * pendiente, al nuevo le llega el enlace y al viejo un aviso. Un enlace anterior deja de valer.
	 */
	public static function pedir_cambio_de_correo( WP_User $cuenta, string $email ): void {
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		update_user_meta( $cuenta->ID, 'zn_email_nuevo', $email );
		update_user_meta( $cuenta->ID, 'zn_email_nuevo_token', hash( 'sha256', $token ) );
		update_user_meta( $cuenta->ID, 'zn_email_nuevo_caduca', time() + self::VERIFICAR_HORAS * HOUR_IN_SECONDS );
		$enlace = add_query_arg(
			array(
				'id'    => $cuenta->ID,
				'token' => $token,
			),
			rest_url( ZN_NAMESPACE . '/verify-email' )
		);
		wp_mail(
			$email,
			'Zona Náutico: confirma tu correo nuevo',
			"Hola:\n\nPara usar este correo en tu cuenta de la app Zona Náutico, abre este enlace:\n\n$enlace\n\nCaduca en " . self::VERIFICAR_HORAS . " horas.\n\nSi no tienes cuenta en Zona Náutico o no lo has pedido tú, ignora este correo y no pulses nada.\n"
		);
		wp_mail(
			$cuenta->user_email,
			'Zona Náutico: has pedido cambiar tu correo',
			"Hola:\n\nSe ha pedido cambiar el correo de tu cuenta en la app Zona Náutico. El cambio solo se hace si se confirma desde el correo nuevo.\n\nSi no has sido tú, cambia tu contraseña desde la app (con «Olvidé la contraseña» si hace falta): así el cambio se anula.\n"
		);
	}

	public static function confirmar_correo( WP_REST_Request $peticion ): WP_REST_Response {
		$id     = (int) $peticion->get_param( 'id' );
		$token  = (string) $peticion->get_param( 'token' );
		$cuenta = $id > 0 ? get_userdata( $id ) : false;
		$huella = $cuenta ? (string) get_user_meta( $id, 'zn_email_nuevo_token', true ) : '';
		$email  = $cuenta ? (string) get_user_meta( $id, 'zn_email_nuevo', true ) : '';
		$vale   = $cuenta && ZN_Roles::rol_app( $cuenta ) && ! ZN_Panel::desactivada( $id ) && '' !== $email && '' !== $huella
			&& preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) && (int) get_user_meta( $id, 'zn_email_nuevo_caduca', true ) > time()
			&& hash_equals( $huella, hash( 'sha256', $token ) );
		if ( ! $vale ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Invalid token' ), 400 );
		}
		// Abrir el enlace no cambia nada (como /verify, R88): solo el botón de la página.
		if ( 'POST' !== $peticion->get_method() ) {
			return new WP_REST_Response( array( 'status' => 'pending' ) );
		}
		$otra = email_exists( $email );
		if ( $otra && (int) $otra !== $id ) {
			self::anular_cambio_de_correo( $id );
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'The email has already been taken.' ), 400 );
		}
		self::anular_cambio_de_correo( $id );
		$viejo = $cuenta->user_email;
		// Sin el aviso propio de WordPress: mandamos el nuestro (R107).
		add_filter( 'send_email_change_email', '__return_false', PHP_INT_MAX );
		// profile_update anula todos sus tokens (aquí no hay ninguno en uso).
		$resultado = wp_update_user( array( 'ID' => $id, 'user_email' => $email ) );
		// WordPress no tiene user_email único: si otra petición a la vez se ha quedado el mismo correo, se deshace (R108).
		global $wpdb;
		$repetido = ! is_wp_error( $resultado ) && 1 < (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email = %s", $email ) );
		if ( $repetido ) {
			wp_update_user( array( 'ID' => $id, 'user_email' => $viejo ) );
		}
		remove_filter( 'send_email_change_email', '__return_false', PHP_INT_MAX );
		if ( $repetido ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'The email has already been taken.' ), 400 );
		}
		if ( is_wp_error( $resultado ) ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Invalid token' ), 400 );
		}
		wp_mail(
			$viejo,
			'Zona Náutico: el correo de tu cuenta ha cambiado',
			"Hola:\n\nEl correo de tu cuenta en la app Zona Náutico ya no es este: se ha confirmado el cambio a otro correo. Desde ahora, los avisos y los códigos irán al nuevo.\n\nSi no has sido tú, avisa a Zona Náutico.\n"
		);
		ZN_Tokens::anular_todos( $id );
		update_user_meta( $id, 'zn_verificado', '1' );
		foreach ( array( 'zn_codigo', 'zn_codigo_caduca', 'zn_codigo_fallos' ) as $clave_meta ) {
			delete_user_meta( $id, $clave_meta );
		}
		return new WP_REST_Response( array( 'status' => 'success' ) );
	}

	public static function anular_cambio_de_correo( int $id ): void {
		foreach ( array( 'zn_email_nuevo', 'zn_email_nuevo_token', 'zn_email_nuevo_caduca' ) as $clave_meta ) {
			delete_user_meta( $id, $clave_meta );
		}
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
	 * Filtro rest_pre_serve_request: el enlace del correo se abre en el navegador, así que GET /verify y GET
	 * /verify-email responden con una página (salvo con &format=json). Sin datos de la cuenta.
	 */
	public static function pagina_de_verificacion( $servida, $resultado, $peticion, $servidor ) {
		$ruta = $peticion instanceof WP_REST_Request ? strtolower( $peticion->get_route() ) : '';
		$alta = '/' . ZN_NAMESPACE . '/verify' === $ruta;
		if ( $servida || ( ! $alta && '/' . ZN_NAMESPACE . '/verify-email' !== $ruta ) || 'json' === $peticion->get_param( 'format' ) ) {
			return $servida;
		}
		// Solo el enlace (GET) y el botón (POST); con otro método, el 405 de la API sale en JSON como en las demás rutas.
		if ( ! in_array( $peticion->get_method(), array( 'GET', 'POST' ), true ) ) {
			return $servida;
		}
		$datos  = $resultado instanceof WP_HTTP_Response ? $resultado->get_data() : array();
		$estado = is_array( $datos ) ? ( $datos['status'] ?? 'error' ) : 'error';
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store, private' );
		$boton = '';
		if ( 'pending' === $estado ) {
			$titulo = $alta ? 'Confirma tu correo' : 'Confirma tu correo nuevo';
			$texto  = $alta ? 'Pulsa el botón para terminar de crear tu cuenta en la app Zona Náutico.' : 'Pulsa el botón para usar este correo en tu cuenta de la app Zona Náutico.';
			$boton  = '<form method="post" action="' . esc_url( rest_url( ZN_NAMESPACE . ( $alta ? '/verify' : '/verify-email' ) ) ) . '">'
				. '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $peticion->get_param( 'id' ) ) . '">'
				. '<input type="hidden" name="token" value="' . esc_attr( (string) $peticion->get_param( 'token' ) ) . '">'
				. '<button type="submit">Confirmar mi correo</button></form>';
		} elseif ( 'success' === $estado ) {
			$titulo = $alta ? 'Cuenta verificada' : 'Correo cambiado';
			$texto  = $alta ? 'Ya puedes entrar en la app Zona Náutico con tu correo y tu contraseña.' : 'Vuelve a entrar en la app Zona Náutico con tu correo nuevo y tu contraseña.';
		} else {
			$titulo = 'Este enlace no vale';
			$texto  = $alta ? 'El enlace no vale o ha caducado. Desde la app puedes pedir que te mandemos otro.' : 'El enlace no vale, ha caducado o ese correo ya lo usa otra cuenta. Desde la app puedes pedir el cambio otra vez.';
		}
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
			. '<title>' . esc_html( $titulo ) . '</title>'
			. '<style>body{margin:0;font-family:system-ui,sans-serif;background:#f0f4f8;color:#12263a;display:flex;min-height:100vh;align-items:center;justify-content:center}main{background:#fff;border-radius:12px;padding:32px 24px;max-width:420px;margin:16px;text-align:center}h1{color:#00457c;font-size:1.4rem}button{background:#00457c;color:#fff;border:0;border-radius:24px;padding:12px 28px;font-size:1rem;cursor:pointer}</style>'
			. '</head><body><main><h1>' . esc_html( $titulo ) . '</h1><p>' . esc_html( $texto ) . '</p>' . $boton . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
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
		// Una cuenta dada de baja en el panel no recibe códigos ni correos (R104).
		return $cuenta && ZN_Roles::rol_app( $cuenta ) && ! ZN_Panel::desactivada( $cuenta->ID ) ? $cuenta : null;
	}

	/** Solo el cuerpo (JSON o formulario): lo que venga en la dirección se ignora (H8). */
	private static function cuerpo( WP_REST_Request $peticion ): array {
		return ZN_API::cuerpo( $peticion );
	}
}

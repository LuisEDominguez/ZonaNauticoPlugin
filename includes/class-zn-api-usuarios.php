<?php
/**
 * GET /user/{id} (privado, con el token de la app). La app lo pide al arrancar para comprobar que la
 * sesión guardada sigue valiendo (Zona-Nautico/src/auth/sesion.ts) y para cargar el perfil.
 *
 * Responde como el UserResource del Laravel con token: { data: { _id, id, name, email, verified, role,
 * parent, avatar, latitude, longitude, phone } }, con `id` numérico (src/api/user.ts).
 * - Sin sesión o con un token que no vale: 401 «Unauthenticated.» (la app cierra la sesión).
 * - Con sesión pero sin permiso para ver esa cuenta: 401 «This action is unauthorized.».
 * - Cuenta que no existe o que no es de la app: 404.
 * Quién ve a quién: ZN_Roles::puede( 'user:read', $cuenta ) (uno mismo, la administración de la app o
 * la cuenta padre con user:read_<rol>).
 *
 * PATCH /user/{id} (y PUT, como el Laravel; también POST con ?_method=PATCH): «cambiar mis datos» (R69).
 * - Solo la propia cuenta; la de otra, o el rol (roleId) → 401 «This action is unauthorized.» (por ahora no se
 *   cambian desde aquí).
 * - Campos: name, phone, email, password. Ausente = no tocar; null = borrar (solo el teléfono: los demás no se
 *   pueden borrar → 422); "" = 422 (decisión a). Lo demás que mande la app se ignora.
 * - El correo y la contraseña piden la contraseña actual (currentPassword, decisión A de R69): sin ella, 422
 *   «The current password field is required.»; mala, 422 «The password is incorrect.» (los textos del Laravel).
 *   Primero el formato (422, sin gastar intentos), luego ZN_Limite 'datos' (429) y luego la contraseña actual. Que
 *   el correo nuevo ya es de otra cuenta solo se dice con la contraseña actual buena.
 * - La contraseña cambia al momento con wp_set_password(): se anulan los demás tokens de la cuenta (no el de quien
 *   la cambia, R56), el código de recuperación y el cambio de correo pendiente, y se avisa por correo.
 * - El correo nuevo no vale hasta confirmarlo con el enlace que le llega (ZN_API_Cuenta::pedir_cambio_de_correo);
 *   al viejo le llega un aviso. Hasta entonces se entra con el viejo y la respuesta lo sigue dando. Como mucho 3
 *   correos nuevos pedidos al día por cuenta (R105, 429). Mandar su correo de siempre cancela el pendiente (R106).
 * - Cuenta también en el límite de escrituras de la cuenta (R94). Responde 200 con {data} como GET /user/{id}.
 *
 * DELETE /user/{id}: «Eliminar cuenta» (src/api/user.ts). Borrado definitivo con wp_delete_user(), como al borrarla
 * en Usuarios: sus reseñas y preguntas se borran y sus negocios, novedades y sorteos pasan a borrador y sin dueño
 * (ZN_Privacidad, R65, R95, R96); sus tokens se anulan (ZN_Tokens).
 * - Solo la propia cuenta y solo con user:delete_self (hoy, solo el rol user, R44); si no, 401 «This action is
 *   unauthorized.». Ni la cuenta padre ni la administración de la app borran otra cuenta desde aquí, y la
 *   administración tampoco la suya (su user:delete general no cuenta).
 * - Pide la contraseña actual (currentPassword, R110), igual que PATCH: sin ella, 422 «The current password field is
 *   required.»; mala, 422 «The password is incorrect.»; con el mismo límite de intentos 'datos' (429).
 * - Cuenta en el límite de escrituras de la cuenta (R94; 429). Responde 200 {status: "success"}.
 * - Tras borrar, avisa a su correo de que la cuenta se ha borrado (R111; texto_cuenta_borrada()).
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Usuarios {

	public static function registrar_rutas(): void {
		register_rest_route(
			ZN_NAMESPACE,
			'/user/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'ficha' ),
					// En la entrada, no solo dentro (R51): sin sesión de una cuenta de la app no se ejecuta nada.
					'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( __CLASS__, 'cambiar' ),
					'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'borrar' ),
					'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
				),
			)
		);
	}

	public static function borrar( WP_REST_Request $peticion ): WP_REST_Response {
		$cuenta = get_userdata( (int) $peticion['id'] );
		if ( ! $cuenta || ! ZN_Roles::rol_app( $cuenta ) ) {
			return ZN_API::no_encontrado();
		}
		// Solo uno mismo y solo con user:delete_self: el user:delete general de la administración no vale aquí (R44).
		if ( get_current_user_id() !== (int) $cuenta->ID || ! in_array( 'zn:user:delete_self', ZN_Roles::permisos( ZN_Roles::rol_app( $cuenta ) ), true ) ) {
			return ZN_Errores::sin_permiso();
		}
		$espera = ZN_API::limite_de_escritura();
		if ( null !== $espera ) {
			return ZN_Limite::demasiados( $espera );
		}
		// La contraseña actual, como en cambiar() (R110 = R69): formato, límite 'datos' y luego la contraseña.
		$actual = ZN_API::cuerpo( $peticion )['currentPassword'] ?? null;
		if ( null === $actual || '' === $actual ) {
			return ZN_Errores::no_valido( array( 'currentPassword' => ZN_Esquema::mensaje( 'required', 'currentPassword' ) ) );
		}
		if ( ! is_string( $actual ) ) {
			return ZN_Errores::no_valido( array( 'currentPassword' => ZN_Esquema::mensaje( 'string', 'currentPassword' ) ) );
		}
		$espera = ZN_Limite::intentar( 'datos', $cuenta->user_email );
		if ( null !== $espera ) {
			return ZN_Limite::demasiados( $espera );
		}
		if ( ! wp_check_password( $actual, $cuenta->user_pass, $cuenta->ID ) ) {
			return ZN_Errores::no_valido( array( 'currentPassword' => 'The password is incorrect.' ) );
		}
		ZN_Limite::olvidar( 'datos', $cuenta->user_email );

		$email = $cuenta->user_email;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( ! wp_delete_user( $cuenta->ID ) ) {
			return new WP_REST_Response( array( 'message' => 'Server Error' ), 500 );
		}
		// R111. Fuera de producción solo sale a @ejemplo.invalid (ZN_Puertas, R57).
		wp_mail( $email, 'Zona Náutico: tu cuenta se ha borrado', self::texto_cuenta_borrada() );
		$respuesta = new WP_REST_Response( array( 'status' => 'success' ) );
		$respuesta->header( 'Cache-Control', 'no-store, private' );
		return $respuesta;
	}

	public static function cambiar( WP_REST_Request $peticion ): WP_REST_Response {
		$cuenta = get_userdata( (int) $peticion['id'] );
		if ( ! $cuenta || ! ZN_Roles::rol_app( $cuenta ) ) {
			return ZN_API::no_encontrado();
		}
		$cuerpo = ZN_API::cuerpo( $peticion );
		if ( get_current_user_id() !== (int) $cuenta->ID || array_key_exists( 'roleId', $cuerpo ) ) {
			return ZN_Errores::sin_permiso();
		}
		$espera = ZN_API::limite_de_escritura();
		if ( null !== $espera ) {
			return ZN_Limite::demasiados( $espera );
		}

		// El correo, aparte: si ya es de otra cuenta solo se dice con la contraseña actual buena.
		$validado = ZN_Esquema::validar( 'cuenta', array_intersect_key( $cuerpo, array_flip( array( 'name', 'phone', 'password' ) ) ), array( 'ID' => $cuenta->ID ) );
		$valores  = $validado['valores'];
		$errores  = $validado['errores'];
		$email    = null;
		$cancelar = false;
		if ( array_key_exists( 'email', $cuerpo ) ) {
			$resultado = ZN_Esquema::comprobar( ZN_Esquema::campos( 'cuenta' )['email'], $cuerpo['email'], 'email' );
			if ( isset( $resultado['errores'] ) ) {
				$errores += $resultado['errores'];
			} elseif ( 0 !== strcasecmp( (string) $resultado['valores']['user_email'], $cuenta->user_email ) ) {
				$email = (string) $resultado['valores']['user_email'];
			} else {
				// Su correo de siempre: cancela el cambio pendiente, si lo hay (R106).
				$cancelar = true;
			}
		}
		$pide   = isset( $valores['user_pass'] ) || null !== $email || isset( $errores['password'] ) || isset( $errores['email'] );
		$actual = $cuerpo['currentPassword'] ?? null;
		if ( $pide && ( null === $actual || '' === $actual ) ) {
			$errores['currentPassword'] = array( ZN_Esquema::mensaje( 'required', 'currentPassword' ) );
		} elseif ( $pide && ! is_string( $actual ) ) {
			$errores['currentPassword'] = array( ZN_Esquema::mensaje( 'string', 'currentPassword' ) );
		}
		if ( $errores ) {
			return ZN_Errores::no_valido( $errores );
		}

		if ( $pide ) {
			$espera = ZN_Limite::intentar( 'datos', $cuenta->user_email );
			if ( null !== $espera ) {
				return ZN_Limite::demasiados( $espera );
			}
			if ( ! wp_check_password( $actual, $cuenta->user_pass, $cuenta->ID ) ) {
				return ZN_Errores::no_valido( array( 'currentPassword' => 'The password is incorrect.' ) );
			}
			ZN_Limite::olvidar( 'datos', $cuenta->user_email );
			$otra = null === $email ? false : email_exists( $email );
			if ( $otra && (int) $otra !== (int) $cuenta->ID ) {
				return ZN_Errores::no_valido( array( 'email' => ZN_Esquema::mensaje( 'unique', 'email' ) ) );
			}
			// Cada correo nuevo pedido manda un correo a esa dirección: pocos al día (R105).
			$espera = null === $email ? null : ZN_Limite::intentar( 'correo', $cuenta->user_email );
			if ( null !== $espera ) {
				return ZN_Limite::demasiados( $espera );
			}
		}

		if ( isset( $valores['first_name'] ) ) {
			update_user_meta( $cuenta->ID, 'first_name', $valores['first_name'] );
			update_user_meta( $cuenta->ID, 'last_name', '' );
		}
		if ( array_key_exists( 'zn_phone', $valores ) ) {
			if ( null === $valores['zn_phone'] ) {
				delete_user_meta( $cuenta->ID, 'zn_phone' );
			} else {
				update_user_meta( $cuenta->ID, 'zn_phone', $valores['zn_phone'] );
			}
		}
		// Primero la contraseña (anula el cambio de correo pendiente) y luego el correo nuevo, si viene.
		if ( isset( $valores['user_pass'] ) ) {
			wp_set_password( $valores['user_pass'], $cuenta->ID );
			delete_user_meta( $cuenta->ID, 'zn_codigo' );
			wp_mail(
				$cuenta->user_email,
				'Zona Náutico: tu contraseña ha cambiado',
				"Hola:\n\nLa contraseña de tu cuenta en la app Zona Náutico acaba de cambiar.\n\nSi no has sido tú, pide un código desde la app con «Olvidé la contraseña» para ponerle otra.\n"
			);
		}
		if ( null !== $email ) {
			ZN_API_Cuenta::pedir_cambio_de_correo( get_userdata( $cuenta->ID ), $email );
		} elseif ( $cancelar ) {
			ZN_API_Cuenta::anular_cambio_de_correo( $cuenta->ID );
		}

		clean_user_cache( $cuenta->ID );
		$respuesta = new WP_REST_Response( array( 'data' => self::formatear( get_userdata( $cuenta->ID ) ) ) );
		$respuesta->header( 'Cache-Control', 'no-store, private' );
		return $respuesta;
	}

	/**
	 * Correo de «tu cuenta se ha borrado» (R111). La frase de contacto solo sale con un correo válido en
	 * wp-config.php: define( 'ZN_CORREO_CONTACTO', '…' ); (R103, pendiente de que Luis diga cuál).
	 */
	public static function texto_cuenta_borrada( ?string $correo = null ): string {
		$correo = $correo ?? ( defined( 'ZN_CORREO_CONTACTO' ) ? (string) ZN_CORREO_CONTACTO : '' );
		$texto  = "Hola:\n\nTu cuenta de Zona Náutico se ha borrado.\n";
		return is_email( $correo ) ? $texto . "\nSi no has sido tú, escríbenos a $correo.\n" : $texto;
	}

	public static function ficha( WP_REST_Request $peticion ): WP_REST_Response {
		if ( ! is_user_logged_in() || ! ZN_Roles::rol_app( wp_get_current_user() ) ) {
			return ZN_Errores::no_autenticado();
		}
		$cuenta = get_userdata( (int) $peticion['id'] );
		if ( ! $cuenta || ! ZN_Roles::rol_app( $cuenta ) ) {
			return ZN_API::no_encontrado();
		}
		if ( ! ZN_Roles::puede( 'user:read', $cuenta ) ) {
			return ZN_Errores::sin_permiso();
		}
		return new WP_REST_Response( array( 'data' => self::formatear( $cuenta ) ) );
	}

	/** Cuenta de la app → objeto con los campos del UserResource del Laravel (con token). */
	public static function formatear( WP_User $cuenta, bool $con_padre = true ): array {
		$campo = static function ( string $clave ) use ( $cuenta ): ?string {
			$valor = get_user_meta( $cuenta->ID, $clave, true );
			return '' === $valor ? null : (string) $valor;
		};
		$datos = array(
			'_id'      => (string) $cuenta->ID,
			'id'       => (int) $cuenta->ID,
			'name'     => ZN_API::nombre_publico( $cuenta ) ?? '',
			'email'    => $cuenta->user_email,
			// Se rellenará con la verificación de correo (fase de login).
			'verified' => '1' === $campo( 'zn_verificado' ),
			'role'     => ZN_Roles::rol_app( $cuenta ),
		);
		if ( $con_padre ) {
			$padre           = ZN_Roles::padre( $cuenta->ID ) ? get_userdata( ZN_Roles::padre( $cuenta->ID ) ) : null;
			$datos['parent'] = $padre && ZN_Roles::rol_app( $padre )
				? array_intersect_key( self::formatear( $padre, false ), array_flip( array( '_id', 'id', 'name', 'email', 'role' ) ) )
				: null;
		}
		$datos['avatar']    = null;
		$datos['latitude']  = ZN_API::numero( $campo( 'zn_latitude' ) );
		$datos['longitude'] = ZN_API::numero( $campo( 'zn_longitude' ) );
		$datos['phone']     = $campo( 'zn_phone' );
		return $datos;
	}
}

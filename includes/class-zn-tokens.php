<?php
/**
 * Token de sesión de la app («token opaco», R32; decidido por Luis el 30-10).
 *
 * - El token es un número aleatorio (32 bytes, en base64url) sin ningún dato dentro. La app lo guarda como
 *   texto y lo manda en `Authorization: Bearer …` (Zona-Nautico/src/api/cliente.ts).
 * - En la base de datos solo se guarda su huella SHA-256 (tabla `{prefijo}zn_tokens`), nunca el token.
 * - Caduca a los 60 minutos, como en la app (src/auth/sesion.ts) y en el Laravel.
 * - Como mucho MAX_ACTIVOS tokens activos por cuenta (R59): uno nuevo anula los más antiguos.
 * - Se anula al cerrar sesión, y todos los de una cuenta al cambiar su rol o al borrarla; al cambiar su
 *   contraseña o su correo, todos menos el que está haciendo el cambio (R52, R56).
 * - Solo vale en /zonanautico/v1 (R39, R46): se ignora en admin-ajax, wp-cron, XML-RPC, wp-admin, wp-login
 *   y cualquier fichero que no sea index.php aunque la dirección lleve ?rest_route=/zonanautico/v1; y si
 *   la cuenta vino del token y la ruta REST que se despacha no es la nuestra, se quita la sesión.
 * - Se valida con `determine_current_user` (no en cada ruta): un token que no vale es «sin sesión», así que
 *   en las rutas públicas la respuesta es la normal (200) y en las privadas, 401 «Unauthenticated.».
 * - Solo para cuentas de la app (un único rol zn_*).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Tokens {

	const DURACION = HOUR_IN_SECONDS;
	// Sesiones abiertas a la vez por cuenta (R59): al pasar, se anulan las más antiguas.
	const MAX_ACTIVOS = 10;
	const VERSION_TABLA = '2';

	/** ¿La cuenta de esta petición salió de un token? (para la segunda barrera de R46). */
	private static bool $desde_token = false;

	public static function iniciar(): void {
		// La tabla tiene que existir antes de que WordPress mire quién es el usuario (WP::init va antes que el
		// gancho init, R48): al activar el plugin y, por si se actualiza sin reactivar, en plugins_loaded.
		add_action( 'plugins_loaded', array( __CLASS__, 'instalar' ) );
		add_filter( 'determine_current_user', array( __CLASS__, 'usuario_del_token' ), 30 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'solo_en_nuestra_ruta' ), 0, 3 );

		// Anular todos los tokens de una cuenta cuando cambia lo que la protege (R32).
		// Al cambiar la contraseña se anulan todos menos el de quien la cambia: la app sigue con su token (R56).
		add_action( 'after_password_reset', static fn( $usuario ) => self::anular_todos( (int) $usuario->ID, self::token_en_uso( (int) $usuario->ID ) ) );
		add_action( 'wp_set_password', static fn( $clave, $id ) => self::anular_todos( (int) $id, self::token_en_uso( (int) $id ) ), 10, 2 );
		add_action(
			'profile_update',
			static function ( $id, $antes ) {
				$ahora = get_userdata( $id );
				if ( $antes instanceof WP_User && $ahora && ( $ahora->user_pass !== $antes->user_pass || $ahora->user_email !== $antes->user_email ) ) {
					self::anular_todos( (int) $id, self::token_en_uso( (int) $id ) );
				}
			},
			10,
			2
		);
		add_action( 'set_user_role', static fn( $id ) => self::anular_todos( (int) $id ) );
		add_action( 'add_user_role', static fn( $id ) => self::anular_todos( (int) $id ) );
		add_action( 'remove_user_role', static fn( $id ) => self::anular_todos( (int) $id ) );
		add_action( 'delete_user', static fn( $id ) => self::anular_todos( (int) $id ) );
	}

	public static function tabla(): string {
		global $wpdb;
		return $wpdb->prefix . 'zn_tokens';
	}

	/** Crea o pone al día la tabla (una vez por versión de la tabla). */
	public static function instalar(): void {
		if ( get_option( 'zn_tokens_tabla' ) === self::VERSION_TABLA ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::tabla() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				hash char(64) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				creado datetime NOT NULL,
				caduca datetime NOT NULL,
				anulado datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY hash (hash),
				KEY user_id (user_id),
				KEY caduca (caduca)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'zn_tokens_tabla', self::VERSION_TABLA );
	}

	/** Nuevo token para una cuenta de la app; null si no es cuenta de la app. Devuelve el token en claro (solo esta vez). */
	public static function emitir( int $usuario ): ?string {
		$cuenta = get_userdata( $usuario );
		if ( ! $cuenta || ! ZN_Roles::rol_app( $cuenta ) ) {
			return null;
		}
		global $wpdb;
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$ahora = time();
		$wpdb->insert(
			self::tabla(),
			array(
				'hash'    => self::huella( $token ),
				'user_id' => $usuario,
				'creado'  => gmdate( 'Y-m-d H:i:s', $ahora ),
				'caduca'  => gmdate( 'Y-m-d H:i:s', $ahora + self::DURACION ),
			),
			array( '%s', '%d', '%s', '%s' )
		);
		// Tope de tokens activos por cuenta (R59): se quedan los MAX_ACTIVOS más nuevos.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::tabla() . ' SET anulado = %s WHERE user_id = %d AND anulado IS NULL AND id NOT IN ( SELECT id FROM ( SELECT id FROM ' . self::tabla() . ' WHERE user_id = %d AND anulado IS NULL AND caduca > %s ORDER BY id DESC LIMIT %d ) AS nuevos )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				gmdate( 'Y-m-d H:i:s', $ahora ),
				$usuario,
				$usuario,
				gmdate( 'Y-m-d H:i:s', $ahora ),
				self::MAX_ACTIVOS
			)
		);
		// De paso, se limpian los que caducaron hace más de un día.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::tabla() . ' WHERE caduca < %s', gmdate( 'Y-m-d H:i:s', $ahora - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $token;
	}

	/** Cuenta dueña de un token que vale (existe, no caducado, no anulado, cuenta de la app); 0 si no. */
	public static function validar( string $token ): int {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) {
			return 0;
		}
		global $wpdb;
		$fila = $wpdb->get_row(
			$wpdb->prepare( 'SELECT user_id, caduca, anulado FROM ' . self::tabla() . ' WHERE hash = %s', self::huella( $token ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		if ( ! $fila || null !== $fila->anulado || strtotime( $fila->caduca . ' UTC' ) <= time() ) {
			return 0;
		}
		$cuenta = get_userdata( (int) $fila->user_id );
		return $cuenta && ZN_Roles::rol_app( $cuenta ) ? (int) $cuenta->ID : 0;
	}

	public static function anular( string $token ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tabla() . ' SET anulado = %s WHERE hash = %s AND anulado IS NULL', gmdate( 'Y-m-d H:i:s' ), self::huella( $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Anula todos los tokens de la cuenta, salvo (si se da) el token $menos. */
	public static function anular_todos( int $usuario, ?string $menos = null ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tabla() . ' SET anulado = %s WHERE user_id = %d AND anulado IS NULL AND hash <> %s', gmdate( 'Y-m-d H:i:s' ), $usuario, null === $menos ? '' : self::huella( $menos ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** El token de esta petición si vale y es de esa cuenta (quien está haciendo el cambio); si no, null. */
	public static function token_en_uso( int $usuario ): ?string {
		$token = self::es_peticion_nuestra() ? self::token_de_la_peticion() : null;
		return null !== $token && self::validar( $token ) === $usuario ? $token : null;
	}

	/**
	 * Filtro determine_current_user: si la petición va a /zonanautico/v1 y trae un token que vale, esa es
	 * la cuenta. Si ya hay cuenta (cookie de wp-admin), o el token no vale, no se toca: sin sesión.
	 *
	 * @param int|false $usuario
	 */
	public static function usuario_del_token( $usuario ) {
		if ( $usuario || ! self::es_peticion_nuestra() ) {
			return $usuario;
		}
		$token = self::token_de_la_peticion();
		$id    = null === $token ? 0 : self::validar( $token );
		if ( $id ) {
			self::$desde_token = true;
			return $id;
		}
		return $usuario;
	}

	/**
	 * Segunda barrera (R46): si la cuenta vino del token y lo que se despacha no es /zonanautico/v1 (p. ej. un
	 * ?rest_route distinto del que se comprobó), se quita la sesión antes de ejecutar nada.
	 */
	public static function solo_en_nuestra_ruta( $resultado, $servidor, $peticion ) {
		if ( self::$desde_token && ! ZN_Errores::es_nuestra( $peticion->get_route() ) ) {
			wp_set_current_user( 0 );
			self::$desde_token = false;
		}
		return $resultado;
	}

	/** Token de la cabecera Authorization (también donde la deja Apache tras una reescritura, R39). */
	public static function token_de_la_peticion(): ?string {
		$cabecera = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return preg_match( '/^Bearer\s+(\S+)$/i', trim( (string) $cabecera ), $m ) ? $m[1] : null;
	}

	/**
	 * ¿La petición va a /zonanautico/v1, por la entrada normal de WordPress (index.php)? (R46, R47)
	 * - Nunca en admin-ajax, wp-cron, XML-RPC, wp-admin ni otro fichero que no sea index.php: todos cargan
	 *   WordPress y miran el usuario, y aceptarían el token con un ?rest_route= añadido.
	 * - El camino se compara entero con <ruta de la portada>/<prefijo de la API>/zonanautico/v1, con o sin
	 *   /index.php delante (cubre WordPress en una subcarpeta); ?rest_route= solo vale en la portada o en /index.php.
	 * - Sin distinguir mayúsculas, como WordPress al buscar la ruta.
	 */
	public static function es_peticion_nuestra(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}
		if ( 'index.php' !== basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return false;
		}
		$camino = strtolower( rawurldecode( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base   = rtrim( strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ), '/' );

		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! in_array( rtrim( $camino, '/' ), array( $base, $base . '/index.php' ), true ) ) {
				return false;
			}
			return ZN_Errores::es_nuestra( '/' . ltrim( (string) wp_unslash( $_GET['rest_route'] ), '/' ) ); // phpcs:ignore WordPress.Security
		}

		// Sin rest_url(): usa $wp_rewrite, que aún no existe si alguien pregunta por el usuario en
		// plugins_loaded, y daría un error fatal (R54). Se arma con piezas que ya están: la ruta de la portada
		// y el prefijo de la API, con o sin /index.php delante.
		$api = '/' . strtolower( trim( rest_get_url_prefix(), '/' ) . '/' . ZN_NAMESPACE );
		foreach ( array( $base . $api, $base . '/index.php' . $api ) as $nuestra ) {
			if ( $camino === $nuestra || 0 === strpos( $camino, $nuestra . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function huella( string $token ): string {
		return hash( 'sha256', $token );
	}
}

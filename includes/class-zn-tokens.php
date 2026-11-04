<?php
/**
 * Token de sesión de la app («token opaco», R32; decidido por Luis el 30-10).
 *
 * - El token es un número aleatorio (32 bytes, en base64url) sin ningún dato dentro. La app lo guarda como
 *   texto y lo manda en `Authorization: Bearer …` (Zona-Nautico/src/api/cliente.ts).
 * - En la base de datos solo se guarda su huella SHA-256 (tabla `{prefijo}zn_tokens`), nunca el token.
 * - Caduca a los 60 minutos, como en la app (src/auth/sesion.ts) y en el Laravel.
 * - Como mucho MAX_ACTIVOS tokens activos por cuenta (R59): uno nuevo anula los más antiguos.
 * - Cada token guarda la fecha del login del que viene (`origen`): /refresh no renueva pasados MAX_RENOVAR (R86).
 * - Se anula al cerrar sesión, y todos los de una cuenta al cambiar su rol o al borrarla; al cambiar su
 *   contraseña o su correo, todos menos el que está haciendo el cambio (R52, R56).
 * - Solo vale en /zonanautico/v1 (R39, R46): se ignora en admin-ajax, wp-cron, XML-RPC, wp-admin, wp-login
 *   y cualquier fichero que no sea index.php aunque la dirección lleve ?rest_route=/zonanautico/v1; y si
 *   la cuenta vino del token y la ruta REST que se despacha no es la nuestra, se quita la sesión.
 * - Se valida con `determine_current_user` (no en cada ruta): un token que no vale es «sin sesión», así que
 *   en las rutas públicas la respuesta es la normal (200) y en las privadas, 401 «Unauthenticated.».
 * - Solo para cuentas de la app (un único rol zn_*).
 * - Refresh token (opción C, Luis: «Sí, hasta 14 días»): POST /login da además uno aparte, opaco y largo (64 bytes),
 *   en la misma tabla con tipo «renovar» y solo su huella. Es de un solo uso y va ligado a su login (cadena): todos los
 *   tokens que salen de un login comparten cadena. Caduca a MAX_RENOVAR del login y vale aunque el token de acceso haya
 *   caducado (POST /refresh {refreshToken}). Si llega uno ya usado (reuso), se anula toda su cadena. Al cerrar sesión se
 *   anula su cadena; los cambios de contraseña, correo, rol y el borrado anulan también los refresh (menos el de la
 *   cadena de quien hace el cambio, R56). Como mucho MAX_ACTIVOS logins (cadenas) con refresh a la vez.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Tokens {

	const DURACION = HOUR_IN_SECONDS;
	// Sesiones abiertas a la vez por cuenta (R59): al pasar, se anulan las más antiguas.
	const MAX_ACTIVOS = 10;
	// Desde el login original, /refresh renueva como mucho durante este tiempo (R86; el refresh_ttl por defecto de jwt-auth).
	const MAX_RENOVAR   = 14 * DAY_IN_SECONDS;
	const VERSION_TABLA = '4';

	/** ¿La cuenta de esta petición salió de un token? (para la segunda barrera de R46). */
	private static bool $desde_token = false;

	public static function iniciar(): void {
		// La tabla tiene que existir antes de que WordPress mire quién es el usuario (WP::init va antes que el
		// gancho init, R48): la crea ZN_Instalacion al activar el plugin y en plugins_loaded (R68).
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
				origen datetime DEFAULT NULL,
				anulado datetime DEFAULT NULL,
				tipo varchar(10) NOT NULL DEFAULT 'acceso',
				cadena char(32) DEFAULT NULL,
				usado datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY hash (hash),
				KEY user_id (user_id),
				KEY caduca (caduca),
				KEY cadena (cadena)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'zn_tokens_tabla', self::VERSION_TABLA );
	}

	/**
	 * Nuevo token para una cuenta de la app; null si no es cuenta de la app. Devuelve el token en claro (solo esta
	 * vez). $origen: momento del login del que viene (al renovar); si no se da, es un login nuevo. $cadena: la del login
	 * del que viene (refresh token); sin ella, el token no va en ninguna.
	 */
	public static function emitir( int $usuario, ?int $origen = null, ?string $cadena = null ): ?string {
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
				'origen'  => gmdate( 'Y-m-d H:i:s', $origen ?? $ahora ),
				'tipo'    => 'acceso',
				'cadena'  => $cadena,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		// Tope de tokens activos por cuenta (R59): se quedan los MAX_ACTIVOS más nuevos.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::tabla() . " SET anulado = %s WHERE user_id = %d AND tipo = 'acceso' AND anulado IS NULL AND id NOT IN ( SELECT id FROM ( SELECT id FROM " . self::tabla() . " WHERE user_id = %d AND tipo = 'acceso' AND anulado IS NULL AND caduca > %s ORDER BY id DESC LIMIT %d ) AS nuevos )", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
			$wpdb->prepare( 'SELECT user_id, caduca, anulado FROM ' . self::tabla() . " WHERE hash = %s AND tipo = 'acceso'", self::huella( $token ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		if ( ! $fila || null !== $fila->anulado || strtotime( $fila->caduca . ' UTC' ) <= time() ) {
			return 0;
		}
		$cuenta = get_userdata( (int) $fila->user_id );
		return $cuenta && ZN_Roles::rol_app( $cuenta ) ? (int) $cuenta->ID : 0;
	}

	/** Anula el token; true si lo ha anulado esta llamada (false si no existía o ya estaba anulado, R87). */
	public static function anular( string $token ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tabla() . ' SET anulado = %s WHERE hash = %s AND anulado IS NULL', gmdate( 'Y-m-d H:i:s' ), self::huella( $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Momento del login del que viene el token (los de antes de R86, su creación); null si no existe. */
	public static function origen( string $token ): ?int {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT creado, origen FROM ' . self::tabla() . ' WHERE hash = %s', self::huella( $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $fila ? (int) strtotime( ( $fila->origen ?? $fila->creado ) . ' UTC' ) : null;
	}

	/**
	 * Anula todos los tokens de la cuenta, también los refresh, salvo (si se da) el token $menos y el refresh de su misma
	 * cadena (quien hace el cambio sigue con su sesión, R56).
	 */
	public static function anular_todos( int $usuario, ?string $menos = null ): void {
		global $wpdb;
		$cadena = null === $menos ? '' : (string) self::cadena_de( $menos );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tabla() . " SET anulado = %s WHERE user_id = %d AND anulado IS NULL AND hash <> %s AND NOT ( tipo = 'renovar' AND cadena = %s )", gmdate( 'Y-m-d H:i:s' ), $usuario, null === $menos ? '' : self::huella( $menos ), $cadena ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Cadena (login de origen) de un token de acceso; null si no tiene o no existe. */
	public static function cadena_de( string $token ): ?string {
		global $wpdb;
		$cadena = $wpdb->get_var( $wpdb->prepare( 'SELECT cadena FROM ' . self::tabla() . " WHERE hash = %s AND tipo = 'acceso'", self::huella( $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_string( $cadena ) && '' !== $cadena ? $cadena : null;
	}

	/** Una cadena nueva, para un login nuevo. */
	public static function nueva_cadena(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/** Anula todo lo de una cadena (tokens de acceso y refresh). */
	public static function anular_cadena( string $cadena ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tabla() . ' SET anulado = %s WHERE cadena = %s AND anulado IS NULL', gmdate( 'Y-m-d H:i:s' ), $cadena ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Refresh token nuevo de la cadena (en claro, solo esta vez). Caduca a MAX_RENOVAR del login ($origen): renovar no
	 * alarga los 14 días. Si la cuenta pasa de MAX_ACTIVOS cadenas con refresh, se anulan las más antiguas (R59).
	 */
	public static function emitir_renovacion( int $usuario, string $cadena, int $origen ): string {
		global $wpdb;
		$refresh = rtrim( strtr( base64_encode( random_bytes( 64 ) ), '+/', '-_' ), '=' );
		$ahora   = time();
		$wpdb->insert(
			self::tabla(),
			array(
				'hash'    => self::huella( $refresh ),
				'user_id' => $usuario,
				'creado'  => gmdate( 'Y-m-d H:i:s', $ahora ),
				'caduca'  => gmdate( 'Y-m-d H:i:s', $origen + self::MAX_RENOVAR ),
				'origen'  => gmdate( 'Y-m-d H:i:s', $origen ),
				'tipo'    => 'renovar',
				'cadena'  => $cadena,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$vivas = $wpdb->get_col( $wpdb->prepare( 'SELECT cadena FROM ' . self::tabla() . " WHERE user_id = %d AND tipo = 'renovar' AND anulado IS NULL AND usado IS NULL AND caduca > %s GROUP BY cadena ORDER BY MAX(id) DESC", $usuario, gmdate( 'Y-m-d H:i:s', $ahora ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( array_slice( $vivas, self::MAX_ACTIVOS ) as $vieja ) {
			self::anular_cadena( (string) $vieja );
		}
		return $refresh;
	}

	/**
	 * Gasta un refresh token: si vale (existe, sin usar, sin anular, dentro de los 14 días, cuenta de la app activa), lo
	 * marca como usado, anula los tokens de acceso de su cadena y devuelve {user_id, origen, cadena}. Si ya estaba usado
	 * (reuso: lo tiene alguien más), anula toda la cadena. Si no, null. El UPDATE con «usado IS NULL» hace que, de dos
	 * peticiones a la vez con el mismo, solo una lo gaste: la otra es un reuso (R87).
	 */
	public static function usar_renovacion( string $refresh ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{86}$/', $refresh ) ) {
			return null;
		}
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, caduca, origen, anulado, usado, cadena FROM ' . self::tabla() . " WHERE hash = %s AND tipo = 'renovar'", self::huella( $refresh ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $fila || null !== $fila->anulado || strtotime( $fila->caduca . ' UTC' ) <= time() ) {
			return null;
		}
		$ahora   = gmdate( 'Y-m-d H:i:s' );
		$gastado = null === $fila->usado && 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tabla() . ' SET usado = %s WHERE id = %d AND usado IS NULL AND anulado IS NULL', $ahora, (int) $fila->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $gastado ) {
			self::anular_cadena( (string) $fila->cadena );
			return null;
		}
		$cuenta = get_userdata( (int) $fila->user_id );
		if ( ! $cuenta || ! ZN_Roles::rol_app( $cuenta ) || ZN_Panel::desactivada( $cuenta->ID ) ) {
			self::anular_cadena( (string) $fila->cadena );
			return null;
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tabla() . " SET anulado = %s WHERE cadena = %s AND tipo = 'acceso' AND anulado IS NULL", $ahora, (string) $fila->cadena ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array( 'user_id' => (int) $fila->user_id, 'origen' => (int) strtotime( $fila->origen . ' UTC' ), 'cadena' => (string) $fila->cadena );
	}

	/** Cadena de un refresh token que no esté anulado ni caducado (para /logout sin token de acceso); null si no. */
	public static function cadena_de_renovacion( string $refresh ): ?string {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{86}$/', $refresh ) ) {
			return null;
		}
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT cadena, caduca, anulado FROM ' . self::tabla() . " WHERE hash = %s AND tipo = 'renovar'", self::huella( $refresh ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $fila && null === $fila->anulado && strtotime( $fila->caduca . ' UTC' ) > time() ? (string) $fila->cadena : null;
	}

	/** El token de esta petición si vale y es de esa cuenta (quien está haciendo el cambio); si no, null. */
	public static function token_en_uso( int $usuario ): ?string {
		$token = self::es_peticion_nuestra() ? self::token_de_la_peticion() : null;
		return null !== $token && self::validar( $token ) === $usuario ? $token : null;
	}

	/**
	 * Filtro determine_current_user: si la petición va a /zonanautico/v1 y trae un token que vale, esa es
	 * la cuenta. Si ya hay cuenta por la cookie de wp-admin, se queda, salvo que sea una cuenta de la app (el
	 * panel de R40): en nuestra API las cuentas de la app solo valen con su token, nunca con la cookie.
	 *
	 * @param int|false $usuario
	 */
	public static function usuario_del_token( $usuario ) {
		if ( ! self::es_peticion_nuestra() ) {
			return $usuario;
		}
		if ( $usuario ) {
			$cuenta = get_userdata( (int) $usuario );
			if ( ! $cuenta || ! ZN_Roles::tiene_rol_de_la_app( $cuenta ) ) {
				return $usuario;
			}
			$usuario = false;
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

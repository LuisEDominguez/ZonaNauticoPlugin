<?php
/**
 * Token de sesión de la app («token opaco», R32; decidido por Luis el 30-10).
 *
 * - El token es un número aleatorio (32 bytes, en base64url) sin ningún dato dentro. La app lo guarda como
 *   texto y lo manda en `Authorization: Bearer …` (Zona-Nautico/src/api/cliente.ts).
 * - En la base de datos solo se guarda su huella SHA-256 (tabla `{prefijo}zn_tokens`), nunca el token.
 * - Caduca a los 60 minutos, como en la app (src/auth/sesion.ts) y en el Laravel.
 * - Se anula al cerrar sesión, y todos los de una cuenta al cambiar su contraseña, su rol o al borrarla.
 * - Solo vale en /zonanautico/v1 (R39): en el resto de WordPress (admin-ajax, /wp/v2…) se ignora.
 * - Se valida con `determine_current_user` (no en cada ruta): un token que no vale es «sin sesión», así que
 *   en las rutas públicas la respuesta es la normal (200) y en las privadas, 401 «Unauthenticated.».
 * - Solo para cuentas de la app (un único rol zn_*).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Tokens {

	const DURACION = HOUR_IN_SECONDS;
	const VERSION_TABLA = '1';

	public static function iniciar(): void {
		add_action( 'init', array( __CLASS__, 'instalar' ), 20 );
		add_filter( 'determine_current_user', array( __CLASS__, 'usuario_del_token' ), 30 );

		// Anular todos los tokens de una cuenta cuando cambia lo que la protege (R32).
		add_action( 'after_password_reset', static fn( $usuario ) => self::anular_todos( (int) $usuario->ID ) );
		add_action( 'wp_set_password', static fn( $clave, $id ) => self::anular_todos( (int) $id ), 10, 2 );
		add_action(
			'profile_update',
			static function ( $id, $antes ) {
				$ahora = get_userdata( $id );
				if ( $antes instanceof WP_User && $ahora && $ahora->user_pass !== $antes->user_pass ) {
					self::anular_todos( (int) $id );
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
				KEY user_id (user_id)
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

	public static function anular_todos( int $usuario ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tabla() . ' SET anulado = %s WHERE user_id = %d AND anulado IS NULL', gmdate( 'Y-m-d H:i:s' ), $usuario ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
		return $id ?: $usuario;
	}

	/** Token de la cabecera Authorization (también donde la deja Apache tras una reescritura, R39). */
	public static function token_de_la_peticion(): ?string {
		$cabecera = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return preg_match( '/^Bearer\s+(\S+)$/i', trim( (string) $cabecera ), $m ) ? $m[1] : null;
	}

	/** ¿La petición va a /zonanautico/v1? (por /wp-json/… o por ?rest_route=…, sin distinguir mayúsculas). */
	private static function es_peticion_nuestra(): bool {
		$ruta = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security
		$ruta = strtolower( rawurldecode( $ruta ) );
		if ( isset( $_GET['rest_route'] ) ) {
			return 0 === strpos( ltrim( $ruta, '/' ), ZN_NAMESPACE . '/' ) || ltrim( $ruta, '/' ) === ZN_NAMESPACE;
		}
		$prefijo = strtolower( '/' . rest_get_url_prefix() . '/' . ZN_NAMESPACE );
		$base    = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		$ruta    = '/' . ltrim( substr( $ruta, strlen( rtrim( $base, '/' ) ) ), '/' );
		return $ruta === $prefijo || 0 === strpos( $ruta, $prefijo . '/' );
	}

	private static function huella( string $token ): string {
		return hash( 'sha256', $token );
	}
}

<?php
/**
 * Límite de intentos común (R59): para el login, la recuperación de contraseña, el reenvío del correo de
 * verificación y el registro (R30, R62). Cada acción tiene varias reglas; basta con que una se pase.
 *
 * - Para que nadie pueda bloquear a otra persona (R75), la regla estricta es por cuenta + IP: quien falla
 *   desde su IP se para, pero el dueño de la cuenta sigue entrando desde la suya. Por cuenta sola hay un tope
 *   mucho más alto, contra ataques repartidos entre muchas IPs; y por IP, otro para quien prueba muchas cuentas.
 * - La cuenta se cuenta por su número si existe y, si no, por el correo en minúsculas (R77).
 * - Las IPv6 cuentan por su bloque /64, que suele ser de una sola casa o un solo móvil (R76).
 * - Ventana fija: cada clave cuenta sus intentos desde el primero; pasada la ventana, vuelve a empezar.
 * - Tabla `{prefijo}zn_limites` con huellas HMAC-SHA256 con la clave secreta del sitio (wp_salt): ni la IP ni
 *   el correo se guardan en claro ni se pueden sacar probando (R77). Las filas caducadas se borran en cada llamada.
 * - Las rutas llaman a intentar() ANTES de comprobar la contraseña o el código: con 429 no se comprueba nada,
 *   así no se delata si era la buena (R75).
 * - Al pasarse: 429 {"message":"Too Many Attempts."} con Retry-After, como el ThrottleRequests del Laravel;
 *   la app ya lo entiende (src/api/errores.ts).
 * - La IP es REMOTE_ADDR. Solo si REMOTE_ADDR es un proxy de confianza (constante ZN_PROXIES en wp-config.php,
 *   p. ej. el de Dinahosting) se mira X-Forwarded-For, de derecha a izquierda, saltando los proxies de
 *   confianza. Sin esa constante, X-Forwarded-For no se mira nunca: cualquiera podría inventárselo.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Limite {

	const VERSION_TABLA = '1';

	// Acción => [regla => [intentos, segundos]]. Reglas: cuenta_ip (cuenta + IP), cuenta (sola) e ip (sola).
	// Valores de partida; se pueden ajustar sin tocar las rutas.
	const REGLAS = array(
		// POST /login.
		'login'     => array( 'cuenta_ip' => array( 5, 900 ), 'cuenta' => array( 30, HOUR_IN_SECONDS ), 'ip' => array( 50, 900 ) ),
		// POST /reset-password-code y GET /send-email: mandan correos.
		'recuperar' => array( 'cuenta_ip' => array( 3, HOUR_IN_SECONDS ), 'cuenta' => array( 10, HOUR_IN_SECONDS ), 'ip' => array( 20, HOUR_IN_SECONDS ) ),
		'reenviar'  => array( 'cuenta_ip' => array( 3, HOUR_IN_SECONDS ), 'cuenta' => array( 10, HOUR_IN_SECONDS ), 'ip' => array( 20, HOUR_IN_SECONDS ) ),
		// POST /reset-password: además, 5 intentos por código (R62, con el código).
		'codigo'    => array( 'cuenta_ip' => array( 5, 900 ), 'cuenta' => array( 20, HOUR_IN_SECONDS ), 'ip' => array( 50, 900 ) ),
		'registro'  => array( 'ip' => array( 10, HOUR_IN_SECONDS ) ),
	);

	public static function iniciar(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'instalar' ) );
	}

	public static function tabla(): string {
		global $wpdb;
		return $wpdb->prefix . 'zn_limites';
	}

	/** Crea o pone al día la tabla (una vez por versión de la tabla). */
	public static function instalar(): void {
		if ( get_option( 'zn_limites_tabla' ) === self::VERSION_TABLA ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::tabla() . " (
				clave char(64) NOT NULL,
				intentos int(10) unsigned NOT NULL DEFAULT 0,
				desde datetime NOT NULL,
				PRIMARY KEY  (clave),
				KEY desde (desde)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'zn_limites_tabla', self::VERSION_TABLA );
	}

	/**
	 * Apunta un intento de $accion (por cuenta + IP, cuenta e IP). Devuelve null si se puede seguir, o los
	 * segundos que hay que esperar si alguna de las reglas ya se ha pasado (el intento cuenta igual).
	 * Llamarla antes de mirar la contraseña o el código.
	 */
	public static function intentar( string $accion, ?string $email = null ): ?int {
		self::borrar_caducadas();
		$espera = null;
		foreach ( self::claves( $accion, $email ) as $clave => $regla ) {
			list( $maximo, $ventana ) = $regla;
			$fila = self::contar( $clave, $ventana );
			if ( $fila['intentos'] > $maximo ) {
				$espera = max( (int) $espera, max( 1, $fila['desde'] + $ventana - time() ) );
			}
		}
		return $espera;
	}

	/**
	 * Tras un acierto (p. ej. un login correcto), los intentos de esa cuenta desde esa IP vuelven a cero. Los de
	 * la cuenta sola y los de la IP, no: si no, quien acierta una vez podría seguir probando otras cuentas.
	 */
	public static function olvidar( string $accion, string $email ): void {
		global $wpdb;
		$clave = self::huella( $accion, 'cuenta_ip', self::quien( $email ) . '|' . self::grupo( self::ip() ) );
		$wpdb->delete( self::tabla(), array( 'clave' => $clave ), array( '%s' ) );
	}

	/** 429 con el formato del Laravel. */
	public static function demasiados( int $espera ): WP_REST_Response {
		$respuesta = new WP_REST_Response( array( 'message' => 'Too Many Attempts.' ), 429 );
		$respuesta->header( 'Retry-After', (string) $espera );
		return $respuesta;
	}

	/** IP de quien hace la petición (ver arriba: X-Forwarded-For solo detrás de un proxy de confianza). */
	public static function ip(): string {
		$remota  = self::ip_valida( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$proxies = self::proxies();
		if ( '' === $remota || ! $proxies || ! self::en_lista( $remota, $proxies ) ) {
			return $remota;
		}
		$cadena = array_reverse( array_map( 'trim', explode( ',', (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $cadena as $salto ) {
			$ip = self::ip_valida( $salto );
			if ( null === $ip ) {
				// Algo que no es una IP: a partir de aquí no hay nada fiable; se queda la última buena.
				break;
			}
			if ( ! self::en_lista( $ip, $proxies ) ) {
				return $ip;
			}
			$remota = $ip;
		}
		return $remota;
	}

	/** Lo que cuenta como «la misma IP»: la IPv4 entera, o el bloque /64 de una IPv6 (R76). */
	public static function grupo( string $ip ): string {
		$ip      = self::ip_valida( $ip ) ?? '';
		$binaria = '' === $ip ? false : inet_pton( $ip );
		if ( false === $binaria || 16 !== strlen( $binaria ) ) {
			return $ip;
		}
		return inet_ntop( substr( $binaria, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/**
	 * Huella => [intentos, segundos] de cada regla de la acción con la IP de esta petición. Pública para las
	 * pruebas (para borrar justo sus filas).
	 */
	public static function claves( string $accion, ?string $email ): array {
		$ip     = self::grupo( self::ip() );
		$quien  = null === $email || '' === trim( $email ) ? '' : self::quien( $email );
		$claves = array();
		foreach ( self::REGLAS[ $accion ] ?? array() as $tipo => $regla ) {
			switch ( $tipo ) {
				case 'cuenta_ip':
					$valor = '' === $quien || '' === $ip ? '' : "$quien|$ip";
					break;
				case 'cuenta':
					$valor = $quien;
					break;
				default:
					$valor = $ip;
			}
			if ( '' !== $valor ) {
				$claves[ self::huella( $accion, $tipo, $valor ) ] = $regla;
			}
		}
		return $claves;
	}

	/** La cuenta por su número si existe (la base de datos da por iguales variantes del correo, R77); si no, el correo. */
	private static function quien( string $email ): string {
		$email  = self::normalizar( $email );
		$cuenta = get_user_by( 'email', $email );
		return $cuenta ? 'id:' . $cuenta->ID : 'email:' . $email;
	}

	/** Las filas cuya ventana ya pasó no sirven para nada: fuera en cada llamada (R77). */
	private static function borrar_caducadas(): void {
		global $wpdb;
		$mas_larga = 0;
		foreach ( self::REGLAS as $reglas ) {
			foreach ( $reglas as $regla ) {
				$mas_larga = max( $mas_larga, $regla[1] );
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::tabla() . ' WHERE desde < %s', gmdate( 'Y-m-d H:i:s', time() - $mas_larga ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Suma un intento de forma atómica (una sola consulta) y devuelve los intentos y el inicio de la ventana. */
	private static function contar( string $clave, int $ventana ): array {
		global $wpdb;
		$ahora  = time();
		$inicio = gmdate( 'Y-m-d H:i:s', $ahora );
		$viejo  = gmdate( 'Y-m-d H:i:s', $ahora - $ventana );
		// MySQL asigna de izquierda a derecha: `intentos` se calcula con el `desde` de antes de cambiarlo.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::tabla() . ' (clave, intentos, desde) VALUES (%s, 1, %s)
				ON DUPLICATE KEY UPDATE intentos = IF(desde <= %s, 1, intentos + 1), desde = IF(desde <= %s, %s, desde)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$clave,
				$inicio,
				$viejo,
				$viejo,
				$inicio
			)
		);
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT intentos, desde FROM ' . self::tabla() . ' WHERE clave = %s', $clave ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'intentos' => $fila ? (int) $fila->intentos : 1,
			'desde'    => $fila ? (int) strtotime( $fila->desde . ' UTC' ) : $ahora,
		);
	}

	/** HMAC con la clave secreta del sitio: sin ella no se puede probar con todas las IPs o una lista de correos (R77). */
	private static function huella( string $accion, string $tipo, string $valor ): string {
		return hash_hmac( 'sha256', "$accion|$tipo|$valor", wp_salt( 'auth' ) );
	}

	/** El mismo correo escrito con mayúsculas o espacios cuenta como el mismo. */
	private static function normalizar( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/** ZN_PROXIES: lista o texto separado por comas de IPs o rangos («10.0.0.0/8», «2001:db8::/32»). */
	private static function proxies(): array {
		if ( ! defined( 'ZN_PROXIES' ) ) {
			return array();
		}
		$lista = is_array( ZN_PROXIES ) ? ZN_PROXIES : explode( ',', (string) ZN_PROXIES );
		return array_values( array_filter( array_map( 'trim', $lista ) ) );
	}

	/**
	 * La IP si es válida. Una IPv4 escrita como IPv6 (::ffff:1.2.3.4, como la dan algunos servidores que escuchan
	 * en IPv6) se pasa a su forma normal: si no, todas caerían en el mismo /64 (R83) y un proxy 10.0.0.0/8 no se
	 * reconocería.
	 */
	private static function ip_valida( string $ip ): ?string {
		$ip = trim( $ip );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$binaria = inet_pton( $ip );
		if ( 16 === strlen( $binaria ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $binaria, 0, 12 ) ) {
			return inet_ntop( substr( $binaria, 12 ) );
		}
		return $ip;
	}

	/** ¿La IP es una de la lista (exacta o dentro de un rango)? Sirve para IPv4 e IPv6. */
	private static function en_lista( string $ip, array $lista ): bool {
		$binaria = inet_pton( $ip );
		foreach ( $lista as $entrada ) {
			list( $red, $bits ) = array_pad( explode( '/', $entrada, 2 ), 2, null );
			$red_binaria        = self::ip_valida( $red ) ? inet_pton( $red ) : false;
			if ( false === $red_binaria || strlen( $red_binaria ) !== strlen( $binaria ) ) {
				continue;
			}
			$bits = null === $bits ? strlen( $binaria ) * 8 : (int) $bits;
			if ( $bits < 0 || $bits > strlen( $binaria ) * 8 ) {
				continue;
			}
			$bytes = intdiv( $bits, 8 );
			$resto = $bits % 8;
			if ( substr( $binaria, 0, $bytes ) !== substr( $red_binaria, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $resto || ( ord( $binaria[ $bytes ] ) >> ( 8 - $resto ) ) === ( ord( $red_binaria[ $bytes ] ) >> ( 8 - $resto ) ) ) {
				return true;
			}
		}
		return false;
	}
}

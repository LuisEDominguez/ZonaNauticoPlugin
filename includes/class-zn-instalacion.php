<?php
/**
 * Instalación, versión y desinstalación del plugin (R68), en un solo sitio.
 *
 * - instalar(): al activar y en cada carga (plugins_loaded, antes de que WordPress mire quién es el usuario, R48).
 *   Crea o pone al día las tablas de tokens y de intentos (cada una con su versión de tabla) y, una vez por versión
 *   del plugin (opción zn_version), pasa los cambios de datos que haga falta (hoy: migrar_meta_cuentas()).
 *   Los roles y sus permisos se ponen al día aparte, en init (ZN_Roles::actualizar), porque necesitan los tipos
 *   de entrada registrados.
 * - desinstalar(): lo llama uninstall.php al borrar el plugin desde wp-admin. Borra las tablas, las opciones, los
 *   avisos temporales, los roles de la app y las capacidades que el plugin dio a otros roles. No borra contenido:
 *   negocios, novedades, sorteos, reseñas, preguntas ni cuentas (con sus datos) se quedan, como con cualquier plugin.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Instalacion {

	// Opciones que guarda el plugin (la prueba tests/instalacion.php comprueba que no falta ninguna).
	const OPCIONES = array( 'zn_version', 'zn_tokens_tabla', 'zn_limites_tabla', 'zn_favoritos_tabla', 'zn_participaciones_tabla', 'zn_roles_version', 'zn_huella_relleno' );

	// Prefijos de los avisos temporales (transients) del plugin.
	const TEMPORALES = array( 'zn_avisos_', 'zn_panel_avisos_' );

	// Datos de las cuentas con nombre propio (R68): antes sin prefijo, como cualquier otro plugin podría usarlos.
	const META_CUENTA = array(
		'phone'     => 'zn_phone',
		'latitude'  => 'zn_latitude',
		'longitude' => 'zn_longitude',
	);

	public static function iniciar(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'instalar' ) );
	}

	public static function instalar(): void {
		ZN_Tokens::instalar();
		ZN_Limite::instalar();
		ZN_Favoritos::instalar();
		ZN_Participaciones::instalar();
		if ( get_option( 'zn_version' ) === ZN_VERSION ) {
			return;
		}
		self::migrar_meta_cuentas();
		update_option( 'zn_version', ZN_VERSION );
	}

	/**
	 * phone, latitude y longitude de las cuentas de la app pasan a zn_phone, zn_latitude y zn_longitude. Solo las
	 * de la app (un único rol, y de la app: R37, R120): en otras cuentas esas claves pueden ser de otro plugin. Si
	 * ya hay valor nuevo, gana el nuevo.
	 */
	public static function migrar_meta_cuentas(): void {
		$ids = get_users(
			array(
				'role__in'   => array_keys( ZN_Roles::ROLES_APP ),
				'meta_query' => array_merge(
					array( 'relation' => 'OR' ),
					array_map( static fn( $vieja ) => array( 'key' => $vieja, 'compare' => 'EXISTS' ), array_keys( self::META_CUENTA ) )
				),
				'fields'     => 'ID',
				'number'     => -1,
			)
		);
		foreach ( array_map( 'intval', $ids ) as $id ) {
			// R120: role__in también trae cuentas con otro rol además del de la app; esas no son de la app (R37).
			$cuenta = get_userdata( $id );
			if ( ! $cuenta || ! ZN_Roles::rol_app( $cuenta ) ) {
				continue;
			}
			foreach ( self::META_CUENTA as $vieja => $nueva ) {
				$valor = get_user_meta( $id, $vieja, true );
				if ( '' !== $valor && '' === get_user_meta( $id, $nueva, true ) ) {
					update_user_meta( $id, $nueva, $valor );
				}
				delete_user_meta( $id, $vieja );
			}
		}
	}

	public static function tablas(): array {
		return array( ZN_Tokens::tabla(), ZN_Limite::tabla(), ZN_Favoritos::tabla(), ZN_Participaciones::tabla() );
	}

	public static function roles(): array {
		return array_keys( ZN_Roles::ROLES_APP );
	}

	/** ¿Es una capacidad que pone el plugin? Los permisos zn:…, la del panel y las de sus tipos de entrada. */
	public static function es_del_plugin( string $capacidad ): bool {
		return 0 === strpos( $capacidad, 'zn:' )
			|| 'zn_panel_ver' === $capacidad
			|| (bool) preg_match( '/_zn_(negocio|evento|sorteo|noticia|slide)s?$/', $capacidad );
	}

	public static function desinstalar(): void {
		global $wpdb;
		// Las fotos de los tickets (fuera de la biblioteca de medios) y la tarea diaria de limpieza.
		ZN_Participaciones::borrar_carpeta();
		wp_clear_scheduled_hook( ZN_Participaciones::TAREA );
		foreach ( self::tablas() as $tabla ) {
			$wpdb->query( "DROP TABLE IF EXISTS `$tabla`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		foreach ( self::OPCIONES as $opcion ) {
			delete_option( $opcion );
		}
		foreach ( self::TEMPORALES as $prefijo ) {
			foreach ( array( '_transient_', '_transient_timeout_' ) as $tipo ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $tipo . $prefijo ) . '%' ) );
			}
		}
		foreach ( self::roles() as $rol ) {
			remove_role( $rol );
		}
		foreach ( array_keys( wp_roles()->roles ) as $nombre ) {
			$rol = get_role( $nombre );
			foreach ( array_filter( array_keys( (array) $rol->capabilities ), array( __CLASS__, 'es_del_plugin' ) ) as $capacidad ) {
				$rol->remove_cap( $capacidad );
			}
		}
	}
}

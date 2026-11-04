<?php
/**
 * Favoritos (bloque 2, parte C), como business_favorites del traspaso (user_id, business_id), en una tabla propia
 * {prefijo}zn_favoritos: una fila por cuenta y negocio. Rutas, con el token y solo lo propio (toda cuenta de la app):
 * - GET /favorite: mis favoritos, el último añadido primero, cada uno con el formato de GET /business/{id}. Los
 *   negocios que no se ven (borrador, papelera, con contraseña) no salen, pero la fila se guarda por si vuelven.
 * - POST /favorite {businessId}: 201 {data: negocio}; si ya era favorito, 200. businessId que falta o no es un
 *   número: 422; negocio que no existe o no está publicado: 404. Cuenta en el límite de escrituras (R94; 429).
 * - DELETE /favorite/{businessId}: 200 {status: "success"}, lo fuera o no (no delata nada).
 * Sin contadores públicos. RGPD (R65): al borrar la cuenta o el negocio se borran sus filas; exportar y borrar datos
 * personales los incluyen (ZN_Privacidad). uninstall.php borra la tabla (R68).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Favoritos {

	const VERSION_TABLA = '1';

	public static function iniciar(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'registrar_rutas' ) );
		add_action( 'delete_user', array( __CLASS__, 'al_borrar_cuenta' ) );
		add_action( 'wpmu_delete_user', array( __CLASS__, 'al_borrar_cuenta' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'al_borrar_negocio' ) );
	}

	public static function tabla(): string {
		global $wpdb;
		return $wpdb->prefix . 'zn_favoritos';
	}

	/** Crea o pone al día la tabla (una vez por versión de la tabla). La llama ZN_Instalacion. */
	public static function instalar(): void {
		if ( get_option( 'zn_favoritos_tabla' ) === self::VERSION_TABLA ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::tabla() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				business_id bigint(20) unsigned NOT NULL,
				creado datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY cuenta_negocio (user_id,business_id),
				KEY business_id (business_id)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'zn_favoritos_tabla', self::VERSION_TABLA );
	}

	public static function registrar_rutas(): void {
		register_rest_route(
			ZN_NAMESPACE,
			'/favorite',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'listar' ),
					'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'anadir' ),
					'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
				),
			)
		);
		register_rest_route(
			ZN_NAMESPACE,
			'/favorite/(?P<businessId>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'quitar' ),
				'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
			)
		);
	}

	/** Ids de los negocios favoritos de una cuenta, el último añadido primero (vean o no). */
	public static function de( int $usuario ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT business_id FROM ' . self::tabla() . ' WHERE user_id = %d ORDER BY creado DESC, id DESC', $usuario ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function listar(): WP_REST_Response {
		$negocios = array_filter( array_map( static fn( $id ) => ZN_API::entrada( $id, ZN_Negocios::TIPO ), self::de( get_current_user_id() ) ) );
		$respuesta = new WP_REST_Response( array( 'data' => array_values( array_map( array( 'ZN_API_Negocios', 'formatear' ), $negocios ) ) ) );
		$respuesta->header( 'Cache-Control', 'no-store, private' );
		return $respuesta;
	}

	public static function anadir( WP_REST_Request $peticion ): WP_REST_Response {
		$espera = ZN_API::limite_de_escritura();
		if ( null !== $espera ) {
			return ZN_Limite::demasiados( $espera );
		}
		$valor = ZN_API::cuerpo( $peticion )['businessId'] ?? null;
		if ( null === $valor || '' === $valor ) {
			return ZN_Errores::no_valido( array( 'businessId' => ZN_Esquema::mensaje( 'required', 'businessId' ) ) );
		}
		if ( ! ( is_int( $valor ) || ( is_string( $valor ) && ctype_digit( $valor ) ) ) || (int) $valor < 1 ) {
			return ZN_Errores::no_valido( array( 'businessId' => ZN_Esquema::mensaje( 'integer', 'businessId' ) ) );
		}
		$negocio = ZN_API::entrada( (int) $valor, ZN_Negocios::TIPO );
		if ( ! $negocio ) {
			return ZN_API::no_encontrado();
		}
		global $wpdb;
		// INSERT IGNORE: si ya estaba (clave única cuenta + negocio), no se duplica ni falla.
		$hechas = (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::tabla() . ' (user_id, business_id, creado) VALUES (%d, %d, %s)', get_current_user_id(), $negocio->ID, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return new WP_REST_Response( array( 'data' => ZN_API_Negocios::formatear( $negocio ) ), $hechas ? 201 : 200 );
	}

	public static function quitar( WP_REST_Request $peticion ): WP_REST_Response {
		global $wpdb;
		$wpdb->delete( self::tabla(), array( 'user_id' => get_current_user_id(), 'business_id' => (int) $peticion['businessId'] ), array( '%d', '%d' ) );
		return new WP_REST_Response( array( 'status' => 'success' ) );
	}

	/** Borra todos los favoritos de una cuenta; devuelve cuántos (para la herramienta de borrar datos personales). */
	public static function borrar_de( int $usuario ): int {
		global $wpdb;
		return (int) $wpdb->delete( self::tabla(), array( 'user_id' => $usuario ), array( '%d' ) );
	}

	/** Acción delete_user (R65). */
	public static function al_borrar_cuenta( $id ): void {
		self::borrar_de( (int) $id );
	}

	/** Acción before_delete_post: un negocio borrado del todo se lleva sus favoritos (R65). */
	public static function al_borrar_negocio( $id ): void {
		if ( ZN_Negocios::TIPO !== get_post_type( (int) $id ) ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( self::tabla(), array( 'business_id' => (int) $id ), array( '%d' ) );
	}
}

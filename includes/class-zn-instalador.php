<?php
/**
 * Tablas propias del plugin. Las columnas copian las de la tabla `businesses` del Laravel
 * para que el día del traspaso los datos reales se puedan copiar tal cual.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Instalador {

	const VERSION_BD = '1';
	const OPCION_BD  = 'zn_version_bd';

	public static function tabla_negocios(): string {
		global $wpdb;
		return $wpdb->prefix . 'zn_businesses';
	}

	public static function activar(): void {
		self::crear_tablas();
	}

	public static function actualizar_si_hace_falta(): void {
		if ( get_option( self::OPCION_BD ) !== self::VERSION_BD ) {
			self::crear_tablas();
		}
	}

	private static function crear_tablas(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tabla   = self::tabla_negocios();
		$charset = $wpdb->get_charset_collate();

		// dbDelta exige dos espacios tras PRIMARY KEY y una columna por línea.
		dbDelta(
			"CREATE TABLE $tabla (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NULL,
			business_category_id bigint(20) unsigned NULL,
			name varchar(255) NOT NULL,
			description text NULL,
			latitude decimal(10,7) NULL,
			longitude decimal(10,7) NULL,
			address varchar(255) NULL,
			postcode varchar(20) NULL,
			state varchar(100) NULL,
			city varchar(100) NULL,
			country varchar(2) NULL,
			phone varchar(50) NULL,
			email varchar(255) NULL,
			web varchar(255) NULL,
			schedule text NULL,
			photo_0 varchar(500) NULL,
			photo_1 varchar(500) NULL,
			photo_2 varchar(500) NULL,
			video_0 varchar(500) NULL,
			video_1 varchar(500) NULL,
			video_2 varchar(500) NULL,
			score decimal(3,2) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY business_category_id (business_category_id),
			KEY user_id (user_id),
			KEY name (name(50))
			) $charset;"
		);

		update_option( self::OPCION_BD, self::VERSION_BD, false );
	}
}

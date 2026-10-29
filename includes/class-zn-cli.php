<?php
/**
 * Órdenes de WP-CLI para desarrollo: `wp zona-nautico <orden>`.
 */

defined( 'ABSPATH' ) || exit;

class ZN_CLI {

	/**
	 * Mete negocios inventados para probar la API. Solo en un WordPress local y con la tabla vacía.
	 *
	 * ## EXAMPLES
	 *
	 *     wp zona-nautico datos-prueba
	 *
	 * @subcommand datos-prueba
	 */
	public function datos_prueba(): void {
		global $wpdb;

		if ( 'local' !== wp_get_environment_type() ) {
			WP_CLI::error( 'Solo se pueden meter datos de prueba en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
		}

		$tabla = ZN_Instalador::tabla_negocios();
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tabla" ) > 0 ) {
			WP_CLI::error( 'La tabla de negocios ya tiene datos; no se toca.' );
		}

		foreach ( self::negocios_inventados() as $negocio ) {
			$wpdb->insert( $tabla, $negocio );
		}
		WP_CLI::success( sprintf( '%d negocios inventados creados.', count( self::negocios_inventados() ) ) );
	}

	/** Datos ficticios: nombres, teléfonos y correos no corresponden a ningún negocio real. */
	private static function negocios_inventados(): array {
		$horario = "lunes\t10:00–14:00, 16:30–20:30\r\nmartes\t10:00–14:00, 16:30–20:30\r\nmiércoles\t10:00–20:30\r\njueves\t10:00–14:00, 16:30–20:30\r\nviernes\t10:00–14:00, 16:30–20:30\r\nsábado\t11:00–14:00\r\ndomingo\tCerrado";

		return array(
			array(
				'business_category_id' => 1,
				'name'                 => 'Taberna O Peirao (prueba)',
				'description'          => 'Negocio inventado para probar la API. Pulpo, empanada y vinos de la tierra.',
				'latitude'             => 42.2386,
				'longitude'            => -8.7262,
				'address'              => 'Rúa de Proba 1, Vigo, 36202',
				'country'              => 'ES',
				'phone'                => '600000001',
				'email'                => 'peirao@ejemplo.invalid',
				'web'                  => 'https://ejemplo.invalid/peirao',
				'schedule'             => $horario,
				'score'                => 4.5,
			),
			array(
				'business_category_id' => 2,
				'name'                 => 'Moda Ría (prueba)',
				'description'          => "Tienda inventada.\nRopa y complementos marineros.",
				'latitude'             => 42.2391,
				'longitude'            => -8.7248,
				'address'              => 'Rúa de Proba 2, Vigo, 36202',
				'country'              => 'ES',
				'phone'                => '600000002',
				'email'                => 'moda@ejemplo.invalid',
				'schedule'             => $horario,
				'score'                => 0,
			),
			array(
				'business_category_id' => 2,
				'name'                 => 'Náutica Cíes (prueba)',
				'description'          => 'Tienda inventada de material náutico.',
				'address'              => 'Rúa de Proba 3, Vigo, 36201',
				'country'              => 'ES',
				'score'                => 3,
			),
			array(
				'business_category_id' => 3,
				'name'                 => 'Café Sen Datos (prueba)',
				'country'              => 'ES',
				'score'                => 0,
			),
		);
	}
}

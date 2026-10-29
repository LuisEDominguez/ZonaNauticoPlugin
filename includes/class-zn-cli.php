<?php
/**
 * Órdenes de WP-CLI para desarrollo: `wp zona-nautico <orden>`.
 */

defined( 'ABSPATH' ) || exit;

class ZN_CLI {

	/**
	 * Mete negocios, novedades y sorteos inventados para probar la API. Solo en un WordPress local;
	 * cada tipo solo se rellena si todavía no tiene ninguna entrada.
	 *
	 * ## EXAMPLES
	 *
	 *     wp zona-nautico datos-prueba
	 *
	 * @subcommand datos-prueba
	 */
	public function datos_prueba(): void {
		if ( 'local' !== wp_get_environment_type() ) {
			WP_CLI::error( 'Solo se pueden meter datos de prueba en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
		}

		self::rellenar( ZN_Negocios::TIPO, ZN_Negocios::CAMPOS, self::negocios_inventados(), 'negocios' );

		$taberna = get_posts( array( 'post_type' => ZN_Negocios::TIPO, 'title' => 'Taberna O Peirao (prueba)', 'numberposts' => 1, 'fields' => 'ids' ) );
		self::rellenar( ZN_Eventos::TIPO, ZN_Eventos::CAMPOS, self::eventos_inventados( $taberna[0] ?? null ), 'novedades' );
		self::rellenar( ZN_Sorteos::TIPO, ZN_Sorteos::CAMPOS, self::sorteos_inventados(), 'sorteos' );
	}

	private static function rellenar( string $tipo, array $campos, array $entradas, string $nombre ): void {
		if ( get_posts( array( 'post_type' => $tipo, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) ) ) {
			WP_CLI::log( "Ya hay entradas de $nombre; no se tocan." );
			return;
		}
		foreach ( $entradas as $entrada ) {
			$id = wp_insert_post(
				array(
					'post_type'    => $tipo,
					'post_status'  => 'publish',
					'post_title'   => $entrada['name'],
					'post_content' => $entrada['description'] ?? '',
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				WP_CLI::error( $id->get_error_message() );
			}
			foreach ( array_intersect_key( $entrada, $campos ) as $campo => $valor ) {
				if ( null !== $valor ) {
					update_post_meta( $id, $campo, $valor );
				}
			}
		}
		WP_CLI::success( sprintf( 'Creadas %d entradas de prueba (%s).', count( $entradas ), $nombre ) );
	}

	/** Sorteos ficticios: uno abierto, uno que aún no ha empezado y uno terminado, con fechas relativas a hoy. */
	private static function sorteos_inventados(): array {
		$dia = static fn( string $cuando, string $hora ) => ( new DateTimeImmutable( $cuando, new DateTimeZone( 'Europe/Madrid' ) ) )->format( "Y-m-d $hora:00" );

		return array(
			array(
				'name'        => 'Sorteo abierto de prueba',
				'description' => "Sorteo inventado para probar la API.\n\nCompra en un negocio asociado y sube el ticket.",
				'start_date'  => $dia( '-5 days', '00:00' ),
				'end_date'    => $dia( '+20 days', '23:59' ),
				'winners'     => 5,
				'legal_url'   => 'https://ejemplo.invalid/bases',
			),
			array(
				'name'       => 'Sorteo de prueba que aún no empieza',
				'start_date' => $dia( '+10 days', '00:00' ),
				'end_date'   => $dia( '+30 days', '23:59' ),
				'winners'    => 1,
			),
			array(
				'name'       => 'Sorteo de prueba terminado',
				'start_date' => $dia( '-60 days', '00:00' ),
				'end_date'   => $dia( '-30 days', '23:59' ),
				'winners'    => 2,
			),
		);
	}

	/** Novedades ficticias: 2 eventos (uno de un negocio), 1 promoción y 1 noticia, con fechas relativas a hoy. */
	private static function eventos_inventados( ?int $negocio ): array {
		$dia = static fn( string $cuando, string $hora ) => ( new DateTimeImmutable( $cuando, wp_timezone() ) )->format( "Y-m-d $hora:00" );

		return array(
			array(
				'event_category_id' => 1,
				'name'              => 'Regata de prueba en la ría',
				'description'       => "Evento inventado para probar la API.\n\nSalida desde el puerto deportivo.",
				'latitude'          => 42.2412,
				'longitude'         => -8.7271,
				'start_date'        => $dia( '+10 days', '11:00' ),
				'end_date'          => $dia( '+10 days', '14:00' ),
			),
			array(
				'event_category_id' => 1,
				'business_id'       => $negocio,
				'name'              => 'Noche de pulpo (prueba)',
				'description'       => 'Evento inventado de un negocio de prueba.',
				'start_date'        => $dia( '+3 days', '20:30' ),
				'end_date'          => null,
			),
			array(
				'event_category_id' => 2,
				'name'              => 'Promoción 2x1 de prueba',
				'description'       => 'Promoción inventada.',
				'start_date'        => $dia( '-5 days', '00:00' ),
				'end_date'          => $dia( '+20 days', '23:59' ),
			),
			array(
				'event_category_id' => 3,
				'name'              => 'Noticia de prueba sin fechas',
			),
		);
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

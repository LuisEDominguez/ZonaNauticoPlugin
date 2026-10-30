<?php
/**
 * Órdenes de WP-CLI para desarrollo: `wp zona-nautico <orden>`.
 */

defined( 'ABSPATH' ) || exit;

class ZN_CLI {

	/**
	 * Mete negocios, novedades, sorteos, reseñas y preguntas inventados para probar la API. Solo en un WordPress local;
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
		self::dueno_de_prueba( $taberna[0] ?? null );
		self::rellenar_comentarios( ZN_Comentarios::RESENA, self::resenas_inventadas(), 'reseñas' );
		self::rellenar_comentarios( ZN_Comentarios::PREGUNTA, self::preguntas_inventadas(), 'preguntas' );
	}

	/**
	 * Las preguntas son del dueño del negocio (autor de la entrada). Los negocios creados desde WP-CLI
	 * no tienen autor: a la Taberna se le pone una cuenta inventada sin privilegios.
	 */
	private static function dueno_de_prueba( ?int $negocio ): void {
		if ( ! $negocio || get_post_field( 'post_author', $negocio ) ) {
			return;
		}
		$dueno = get_user_by( 'login', 'comercio-prueba' );
		$id    = $dueno ? $dueno->ID : wp_insert_user(
			array(
				'user_login'   => 'comercio-prueba',
				'user_email'   => 'comercio-prueba@ejemplo.invalid',
				'user_pass'    => wp_generate_password( 32 ),
				'display_name' => 'Comercio Prueba',
				'role'         => 'subscriber',
			)
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}
		wp_update_post( array( 'ID' => $negocio, 'post_author' => $id ) );
		WP_CLI::success( 'La Taberna de prueba ya tiene dueño (cuenta inventada «Comercio Prueba»).' );
	}

	/** Como rellenar(), con comentarios. Sin cuentas de usuario: solo el nombre de quien escribe. */
	private static function rellenar_comentarios( string $tipo, array $comentarios, string $nombre ): void {
		if ( get_comments( array( 'type' => $tipo, 'status' => 'all', 'number' => 1, 'fields' => 'ids' ) ) ) {
			WP_CLI::log( "Ya hay $nombre; no se tocan." );
			return;
		}
		foreach ( $comentarios as $c ) {
			$negocio = get_posts( array( 'post_type' => ZN_Negocios::TIPO, 'title' => $c['negocio'], 'numberposts' => 1, 'fields' => 'ids' ) );
			if ( ! $negocio ) {
				WP_CLI::error( "No está el negocio «{$c['negocio']}»." );
			}
			// wp_insert_comment no avisa por correo a nadie (wp_new_comment sí).
			$id = wp_insert_comment(
				array(
					'comment_post_ID'  => $negocio[0],
					'comment_type'     => $tipo,
					'comment_author'   => $c['autor'],
					'comment_content'  => $c['texto'] ?? '',
					'comment_approved' => $c['aprobado'] ?? 1,
					'comment_date'     => $c['fecha'] ?? current_time( 'mysql' ),
					'comment_meta'     => $c['campos'] ?? array(),
				)
			);
			if ( ! $id ) {
				WP_CLI::error( "No se pudo crear una de las $nombre." );
			}
		}
		WP_CLI::success( sprintf( 'Creadas %d %s de prueba.', count( $comentarios ), $nombre ) );
	}

	/** Reseñas ficticias: la Taberna queda con media 4,5 y Náutica Cíes con 3; una sin aprobar no cuenta. */
	private static function resenas_inventadas(): array {
		$hace = static fn( string $cuando ) => ( new DateTimeImmutable( $cuando, wp_timezone() ) )->format( 'Y-m-d H:i:s' );

		return array(
			array(
				'negocio' => 'Taberna O Peirao (prueba)',
				'autor'   => 'Marta Prueba',
				'texto'   => "Reseña inventada.
El pulpo, de diez.",
				'fecha'   => $hace( '-20 days 13:40' ),
				'campos'  => array( 'score' => 5, 'response' => '¡Gracias, Marta! (respuesta inventada)' ),
			),
			array(
				'negocio' => 'Taberna O Peirao (prueba)',
				'autor'   => 'Xoán Proba',
				'texto'   => 'Reseña inventada. Buen sitio, algo de espera.',
				'fecha'   => $hace( '-3 days 21:15' ),
				'campos'  => array( 'score' => 4 ),
			),
			array(
				'negocio'  => 'Taberna O Peirao (prueba)',
				'autor'    => 'Pendiente Prueba',
				'texto'    => 'Reseña inventada sin aprobar: no debe salir en la API.',
				'aprobado' => 0,
				'campos'   => array( 'score' => 1 ),
			),
			array(
				'negocio' => 'Náutica Cíes (prueba)',
				'autor'   => 'Uxía Proba',
				'fecha'   => $hace( '-10 days 11:00' ),
				'campos'  => array( 'score' => 3 ),
			),
		);
	}

	/** Preguntas ficticias a la Taberna: dos publicadas (una sin responder) y una sin publicar (no debe salir). */
	private static function preguntas_inventadas(): array {
		return array(
			array(
				'negocio' => 'Taberna O Peirao (prueba)',
				'autor'   => 'Marta Prueba',
				'texto'   => '¿Tenéis opciones sin gluten? (pregunta inventada)',
				'campos'  => array( 'answer' => 'Sí, empanada y postres sin gluten. (respuesta inventada)' ),
			),
			array(
				'negocio' => 'Taberna O Peirao (prueba)',
				'autor'   => 'Xoán Proba',
				'texto'   => '¿Se puede reservar? (pregunta inventada, sin responder)',
			),
			array(
				'negocio'  => 'Taberna O Peirao (prueba)',
				'autor'    => 'Pendiente Prueba',
				'texto'    => 'Pregunta inventada sin publicar: no debe salir en la API.',
				'aprobado' => 0,
			),
		);
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

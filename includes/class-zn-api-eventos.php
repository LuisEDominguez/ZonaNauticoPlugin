<?php
/**
 * GET /event y GET /event/{id} (públicos, solo lectura).
 *
 * Responde igual que el Laravel (api-muestras/event.json de la app): campos _id, name, description,
 * latitude, longitude, photo_0..2, video_0..2 y las fechas en tres formatos
 * (startDate "Y-m-d H:i:s", startDateLongFormat "d-m-Y H:i", startDateShortFormat "d-m-Y"; igual endDate).
 * Filtros: category, business. Inclusiones: includesCategory ({_id, name}), includesBusiness, includesUser.
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Eventos {

	// ?orderby= admitidos → cómo se ordena. Nombres de columna del Laravel.
	const ORDENABLES = array( 'id', '_id', 'name', 'start_date', 'end_date' );

	public static function registrar_rutas(): void {
		$inclusiones = array(
			'includesCategory' => array( 'type' => 'boolean', 'default' => false ),
			'includesBusiness' => array( 'type' => 'boolean', 'default' => false ),
			'includesUser'     => array( 'type' => 'boolean', 'default' => false ),
		);

		register_rest_route(
			ZN_NAMESPACE,
			'/event',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'listar' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					ZN_API::argumentos_paginacion(),
					array(
						'category' => array( 'type' => 'integer', 'minimum' => 1 ),
						'business' => array( 'type' => 'integer', 'minimum' => 1 ),
						'orderby'  => array( 'type' => 'string', 'enum' => self::ORDENABLES, 'default' => 'id' ),
						'order'    => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'asc' ),
					),
					$inclusiones
				),
			)
		);

		register_rest_route(
			ZN_NAMESPACE,
			'/event/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ficha' ),
				'permission_callback' => '__return_true',
				'args'                => $inclusiones,
			)
		);
	}

	public static function listar( WP_REST_Request $peticion ): WP_REST_Response {
		$sentido    = 'desc' === $peticion['order'] ? 'DESC' : 'ASC';
		$argumentos = array(
			'post_type'  => ZN_Eventos::TIPO,
			'meta_query' => array(),
		);

		foreach ( array( 'category' => 'event_category_id', 'business' => 'business_id' ) as $filtro => $clave ) {
			if ( ! empty( $peticion[ $filtro ] ) ) {
				$argumentos['meta_query'][] = array( 'key' => $clave, 'value' => (int) $peticion[ $filtro ], 'type' => 'NUMERIC' );
			}
		}

		// Las novedades de un negocio que no se ve (papelera, borrador, contraseña…) tampoco salen (R15).
		$argumentos['meta_query'][] = array(
			'relation' => 'OR',
			array( 'key' => 'business_id', 'compare' => 'NOT EXISTS' ),
			array( 'key' => 'business_id', 'value' => ZN_Negocios::visibles() ?: array( 0 ), 'compare' => 'IN', 'type' => 'NUMERIC' ),
		);

		// Desempate por ID para que la paginación sea estable.
		switch ( $peticion['orderby'] ) {
			case 'name':
				$argumentos['orderby'] = array( 'title' => $sentido, 'ID' => 'ASC' );
				break;
			case 'start_date':
			case 'end_date':
				$argumentos['meta_query'][] = ZN_API::orden_por_campo( 'fecha', $peticion['orderby'], 'DATETIME' );
				$argumentos['orderby']      = array( 'fecha' => $sentido, 'ID' => 'ASC' );
				break;
			default:
				$argumentos['orderby'] = array( 'ID' => $sentido );
		}

		$inclusiones = self::inclusiones( $peticion );
		return ZN_API::listado(
			$peticion,
			$argumentos,
			'event',
			static fn( WP_Post $entrada ) => self::formatear( $entrada, $inclusiones )
		);
	}

	/** Sin negocio, o con un negocio que la API enseña. */
	private static function negocio_visible( int $evento ): bool {
		$negocio = (int) ZN_API::campo( $evento, 'business_id' );
		return ! $negocio || ZN_API::entrada( $negocio, ZN_Negocios::TIPO );
	}

	public static function ficha( WP_REST_Request $peticion ): WP_REST_Response {
		$entrada = ZN_API::entrada( (int) $peticion['id'], ZN_Eventos::TIPO );
		if ( ! $entrada || ! self::negocio_visible( $entrada->ID ) ) {
			return ZN_API::no_encontrado();
		}
		return new WP_REST_Response( array( 'data' => self::formatear( $entrada, self::inclusiones( $peticion ) ) ) );
	}

	private static function inclusiones( WP_REST_Request $peticion ): array {
		return array(
			'category' => (bool) $peticion['includesCategory'],
			'business' => (bool) $peticion['includesBusiness'],
			'user'     => (bool) $peticion['includesUser'],
		);
	}

	/** Entrada `evento` → objeto con los mismos campos y tipos que el EventResource del Laravel. */
	public static function formatear( WP_Post $entrada, array $incluir = array() ): array {
		$id = $entrada->ID;

		// name, description, coordenadas, photo_0..2 y video_0..2 salen del esquema común (R58); las fechas, en tres formatos.
		$evento = array( '_id' => (string) $id ) + ZN_Esquema::salida( ZN_Eventos::TIPO, $entrada );
		foreach ( array( 'start' => 'start_date', 'end' => 'end_date' ) as $prefijo => $clave ) {
			$evento += self::fechas( $prefijo, ZN_API::campo( $id, $clave ) );
		}

		if ( ! empty( $incluir['category'] ) ) {
			$categoria          = (int) ZN_API::campo( $id, 'event_category_id' );
			$evento['category'] = isset( ZN_Eventos::CATEGORIAS[ $categoria ] )
				? array( '_id' => (string) $categoria, 'name' => ZN_Eventos::CATEGORIAS[ $categoria ] )
				: null;
		}
		if ( ! empty( $incluir['business'] ) ) {
			$negocio            = ZN_API::entrada( (int) ZN_API::campo( $id, 'business_id' ), ZN_Negocios::TIPO );
			$evento['business'] = $negocio ? ZN_API_Negocios::formatear( $negocio ) : null;
		}
		// Los usuarios todavía no están en el plugin.
		if ( ! empty( $incluir['user'] ) ) {
			$evento['user'] = ZN_API::usuario_publico( (int) ZN_API::campo( $id, 'user_id' ) );
		}
		return $evento;
	}

	/** "2024-02-15 19:00:00" → startDate, startDateLongFormat ("15-02-2024 19:00") y startDateShortFormat ("15-02-2024"). */
	private static function fechas( string $prefijo, ?string $valor ): array {
		$fecha = $valor ? DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $valor ) : false;
		return array(
			"{$prefijo}Date"            => $fecha ? $fecha->format( 'Y-m-d H:i:s' ) : null,
			"{$prefijo}DateLongFormat"  => $fecha ? $fecha->format( 'd-m-Y H:i' ) : null,
			"{$prefijo}DateShortFormat" => $fecha ? $fecha->format( 'd-m-Y' ) : null,
		);
	}
}

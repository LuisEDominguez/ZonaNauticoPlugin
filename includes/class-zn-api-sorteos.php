<?php
/**
 * GET /draw y GET /draw/{id} (públicos, solo lectura).
 *
 * Responde igual que el Laravel (api-muestras/draw.json de la app): _id, title, description, startDate, endDate
 * ("Y-m-d H:i:s"), winners, photo_0..2, video_0..2, legalUrl y dos banderas calculadas:
 * - controlParticipar = 1 si ahora está entre el inicio y el fin;
 * - controlVisibilidad = 1 si todavía no ha terminado.
 * Con ?controlParticipar=1 solo salen los sorteos abiertos.
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Sorteos {

	// ?orderby= admitidos. Nombres de columna del Laravel.
	const ORDENABLES = array( 'id', '_id', 'title', 'start_date', 'end_date' );

	public static function registrar_rutas(): void {
		register_rest_route(
			ZN_NAMESPACE,
			'/draw',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'listar' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					ZN_API::argumentos_paginacion(),
					array(
						'controlParticipar' => array( 'type' => 'boolean', 'default' => false ),
						'orderby'           => array( 'type' => 'string', 'enum' => self::ORDENABLES, 'default' => 'id' ),
						'order'             => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'asc' ),
						'includesUser'      => array( 'type' => 'boolean', 'default' => false ),
					)
				),
			)
		);

		register_rest_route(
			ZN_NAMESPACE,
			'/draw/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ficha' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'includesUser' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
	}

	public static function listar( WP_REST_Request $peticion ): WP_REST_Response {
		$sentido    = 'desc' === $peticion['order'] ? 'DESC' : 'ASC';
		$argumentos = array(
			'post_type'  => ZN_Sorteos::TIPO,
			'meta_query' => array(),
		);

		if ( $peticion['controlParticipar'] ) {
			$ahora                      = ZN_Sorteos::ahora();
			$argumentos['meta_query'][] = array( 'key' => 'start_date', 'value' => $ahora, 'compare' => '<=', 'type' => 'DATETIME' );
			$argumentos['meta_query'][] = array( 'key' => 'end_date', 'value' => $ahora, 'compare' => '>=', 'type' => 'DATETIME' );
		}

		// Desempate por ID para que la paginación sea estable.
		switch ( $peticion['orderby'] ) {
			case 'title':
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

		$incluir_usuario = (bool) $peticion['includesUser'];
		return ZN_API::listado(
			$peticion,
			$argumentos,
			'draw',
			static fn( WP_Post $entrada ) => self::formatear( $entrada, $incluir_usuario )
		);
	}

	public static function ficha( WP_REST_Request $peticion ): WP_REST_Response {
		$entrada = ZN_API::entrada( (int) $peticion['id'], ZN_Sorteos::TIPO );
		if ( ! $entrada ) {
			return ZN_API::no_encontrado();
		}
		return new WP_REST_Response( array( 'data' => self::formatear( $entrada, (bool) $peticion['includesUser'] ) ) );
	}

	/** Entrada `sorteo` → objeto con los mismos campos y tipos que el DrawResource del Laravel. */
	public static function formatear( WP_Post $entrada, bool $incluir_usuario = false ): array {
		$id     = $entrada->ID;
		$inicio = ZN_API::campo( $id, 'start_date' );
		$fin    = ZN_API::campo( $id, 'end_date' );
		$ahora  = ZN_Sorteos::ahora();

		$sorteo = array(
			'_id'         => (string) $id,
			'title'       => $entrada->post_title,
			'description' => '' === $entrada->post_content ? null : $entrada->post_content,
			'startDate'   => $inicio,
			'endDate'     => $fin,
			'winners'     => (int) ZN_API::campo( $id, 'winners' ),
		);
		foreach ( array( 'photo', 'video' ) as $tipo ) {
			for ( $i = 0; $i < 3; $i++ ) {
				$sorteo[ "{$tipo}_{$i}" ] = ZN_API::campo( $id, "{$tipo}_{$i}" );
			}
		}
		$sorteo['legalUrl']           = ZN_API::campo( $id, 'legal_url' );
		$sorteo['controlParticipar']  = $inicio && $fin && $inicio <= $ahora && $ahora <= $fin ? 1 : 0;
		$sorteo['controlVisibilidad'] = $fin && $ahora <= $fin ? 1 : 0;

		// Los usuarios todavía no están en el plugin.
		if ( $incluir_usuario ) {
			$sorteo['user'] = null;
		}
		return $sorteo;
	}
}

<?php
/**
 * GET /business y GET /business/{id} (públicos, solo lectura).
 *
 * Responde igual que el Laravel (api-muestras/business.json de la app):
 * - Listado: { data: [...], links: {first,last,prev,next}, meta: {current_page, from, last_page, links, path, per_page, to, total} }
 * - Ficha:   { data: {...} }
 * - `_id` es una cadena; fotos en photo_0..2 y vídeos en video_0..2.
 * Los datos salen de las entradas `negocio` publicadas (ver ZN_Negocios).
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Negocios {

	// Campos por los que se puede ordenar (?orderby=); el valor de la API nunca llega a la consulta.
	const ORDENABLES = array( '_id', 'name', 'score' );

	public static function registrar_rutas(): void {
		register_rest_route(
			ZN_NAMESPACE,
			'/business',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'listar' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					ZN_API::argumentos_paginacion(),
					array(
						'category'     => array( 'type' => 'integer', 'minimum' => 1 ),
						'orderby'      => array( 'type' => 'string', 'enum' => self::ORDENABLES, 'default' => '_id' ),
						'order'        => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'asc' ),
						'includesUser' => array( 'type' => 'boolean', 'default' => false ),
					)
				),
			)
		);

		register_rest_route(
			ZN_NAMESPACE,
			'/business/(?P<id>\d+)',
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
			'post_type'  => ZN_Negocios::TIPO,
			'meta_query' => array(),
		);

		if ( ! empty( $peticion['category'] ) ) {
			$argumentos['meta_query'][] = array(
				'key'   => 'business_category_id',
				'value' => (int) $peticion['category'],
				'type'  => 'NUMERIC',
			);
		}

		// Desempate por ID para que la paginación sea estable.
		switch ( $peticion['orderby'] ) {
			case 'name':
				$argumentos['orderby'] = array( 'title' => $sentido, 'ID' => 'ASC' );
				break;
			case 'score':
				$argumentos['meta_query'][] = ZN_API::orden_por_campo( 'puntuacion', 'score', 'DECIMAL(3,2)' );
				$argumentos['orderby']      = array( 'puntuacion' => $sentido, 'ID' => 'ASC' );
				break;
			default:
				$argumentos['orderby'] = array( 'ID' => $sentido );
		}

		$incluir_usuario = (bool) $peticion['includesUser'];
		return ZN_API::listado(
			$peticion,
			$argumentos,
			'business',
			static fn( WP_Post $entrada ) => self::formatear( $entrada, $incluir_usuario )
		);
	}

	public static function ficha( WP_REST_Request $peticion ): WP_REST_Response {
		$entrada = ZN_API::entrada( (int) $peticion['id'], ZN_Negocios::TIPO );
		if ( ! $entrada ) {
			return ZN_API::no_encontrado();
		}
		return new WP_REST_Response( array( 'data' => self::formatear( $entrada, (bool) $peticion['includesUser'] ) ) );
	}

	/** Entrada `negocio` → objeto con los mismos campos y tipos que el BusinessResource del Laravel. */
	public static function formatear( WP_Post $entrada, bool $incluir_usuario = false ): array {
		$id = $entrada->ID;

		$negocio = array(
			'_id'         => (string) $id,
			'name'        => $entrada->post_title,
			'description' => '' === $entrada->post_content ? null : $entrada->post_content,
			'latitude'    => ZN_API::numero( ZN_API::campo( $id, 'latitude' ) ),
			'longitude'   => ZN_API::numero( ZN_API::campo( $id, 'longitude' ) ),
		);
		foreach ( array( 'address', 'postcode', 'state', 'city', 'country', 'phone', 'email', 'web', 'schedule' ) as $clave ) {
			$negocio[ $clave ] = ZN_API::campo( $id, $clave );
		}
		foreach ( array( 'photo', 'video' ) as $tipo ) {
			for ( $i = 0; $i < 3; $i++ ) {
				$negocio[ "{$tipo}_{$i}" ] = ZN_API::campo( $id, "{$tipo}_{$i}" );
			}
		}
		$negocio['score'] = ZN_API::numero( ZN_API::campo( $id, 'score' ) ) ?? 0;

		// El dueño es el autor de la entrada; solo sus datos públicos (H27). La app lo usa para pedir sus preguntas.
		if ( $incluir_usuario ) {
			$negocio['user'] = ZN_API::usuario_publico( (int) $entrada->post_author );
		}
		return $negocio;
	}
}

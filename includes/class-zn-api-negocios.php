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
						'includesCategory' => array( 'type' => 'boolean', 'default' => false ),
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
					'includesUser'     => array( 'type' => 'boolean', 'default' => false ),
					'includesCategory' => array( 'type' => 'boolean', 'default' => false ),
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

		$incluir_usuario   = (bool) $peticion['includesUser'];
		$incluir_categoria = (bool) $peticion['includesCategory'];
		return ZN_API::listado(
			$peticion,
			$argumentos,
			'business',
			static fn( WP_Post $entrada ) => self::formatear( $entrada, $incluir_usuario, $incluir_categoria )
		);
	}

	public static function ficha( WP_REST_Request $peticion ): WP_REST_Response {
		$entrada = ZN_API::entrada( (int) $peticion['id'], ZN_Negocios::TIPO );
		if ( ! $entrada ) {
			return ZN_API::no_encontrado();
		}
		return new WP_REST_Response( array( 'data' => self::formatear( $entrada, (bool) $peticion['includesUser'], (bool) $peticion['includesCategory'] ) ) );
	}

	/** Entrada `negocio` → objeto con los mismos campos y tipos que el BusinessResource del Laravel. */
	public static function formatear( WP_Post $entrada, bool $incluir_usuario = false, bool $incluir_categoria = false ): array {
		$id = $entrada->ID;

		// Los campos que salen tal cual, del esquema común (R58): name, description, coordenadas, dirección,
		// contacto, horario, photo_0..2 y video_0..2.
		$negocio          = array( '_id' => (string) $id ) + ZN_Esquema::salida( ZN_Negocios::TIPO, $entrada );
		$negocio['score'] = ZN_API::numero( ZN_API::campo( $id, 'score' ) ) ?? 0;

		// El dueño (cuenta Comercio elegida en la ficha), solo con sus datos públicos (H27). La app lo usa para pedir sus preguntas.
		if ( $incluir_usuario ) {
			$negocio['user'] = ZN_API::usuario_publico( ZN_Negocios::dueno( $id ) );
		}
		// category {_id, name}, como en el traspaso (bloque 2); el orden sale de GET /business-category.
		if ( $incluir_categoria ) {
			$negocio['category'] = ZN_Portada::categoria_de( $id );
		}
		return $negocio;
	}
}

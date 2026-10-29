<?php
/**
 * GET /business y GET /business/{id} (públicos, solo lectura).
 *
 * Responde igual que el Laravel (api-muestras/business.json de la app):
 * - Listado: { data: [...], links: {first,last,prev,next}, meta: {current_page, from, last_page, links, path, per_page, to, total} }
 * - Ficha:   { data: {...} }
 * - `_id` es una cadena; fotos en photo_0..2 y vídeos en video_0..2.
 * Diferencia a propósito: perPage tiene tope de 50 (el Laravel no lo tiene, H32).
 * Los datos salen de las entradas `negocio` publicadas (ver ZN_Negocios).
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Negocios {

	const POR_PAGINA_DEFECTO = 15;
	const POR_PAGINA_MAX     = 50;

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
				'args'                => array(
					'page'         => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					'perPage'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => self::POR_PAGINA_DEFECTO ),
					'category'     => array( 'type' => 'integer', 'minimum' => 1 ),
					'orderby'      => array( 'type' => 'string', 'enum' => self::ORDENABLES, 'default' => '_id' ),
					'order'        => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'asc' ),
					'includesUser' => array( 'type' => 'boolean', 'default' => false ),
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
		$por_pagina = min( (int) $peticion['perPage'], self::POR_PAGINA_MAX );
		$pagina     = (int) $peticion['page'];
		$sentido    = 'desc' === $peticion['order'] ? 'DESC' : 'ASC';

		$argumentos = array(
			'post_type'           => ZN_Negocios::TIPO,
			'post_status'         => 'publish',
			'posts_per_page'      => $por_pagina,
			'paged'               => $pagina,
			'ignore_sticky_posts' => true,
			'meta_query'          => array(),
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
				// EXISTS / NOT EXISTS para no perder los negocios que no tengan el campo guardado.
				$argumentos['meta_query'][] = array(
					'relation'   => 'OR',
					'puntuacion' => array( 'key' => 'score', 'compare' => 'EXISTS', 'type' => 'DECIMAL(3,2)' ),
					array( 'key' => 'score', 'compare' => 'NOT EXISTS' ),
				);
				$argumentos['orderby']      = array( 'puntuacion' => $sentido, 'ID' => 'ASC' );
				break;
			default:
				$argumentos['orderby'] = array( 'ID' => $sentido );
		}

		$consulta = new WP_Query( $argumentos );
		$total    = (int) $consulta->found_posts;

		// WordPress no cuenta el total cuando la página pedida queda vacía; Laravel sí lo da.
		if ( ! $consulta->posts && $pagina > 1 ) {
			$cuenta = new WP_Query( array_merge( $argumentos, array( 'paged' => 1, 'posts_per_page' => 1, 'fields' => 'ids' ) ) );
			$total  = (int) $cuenta->found_posts;
		}

		$incluir_usuario = (bool) $peticion['includesUser'];
		$datos           = array_map(
			static fn( WP_Post $entrada ) => self::formatear( $entrada, $incluir_usuario ),
			$consulta->posts
		);

		return new WP_REST_Response(
			array_merge(
				array( 'data' => $datos ),
				self::paginacion( rest_url( ZN_NAMESPACE . '/business' ), $pagina, $por_pagina, $total, count( $datos ) )
			)
		);
	}

	public static function ficha( WP_REST_Request $peticion ): WP_REST_Response {
		$entrada = get_post( (int) $peticion['id'] );
		if ( ! $entrada || ZN_Negocios::TIPO !== $entrada->post_type || 'publish' !== $entrada->post_status ) {
			return new WP_REST_Response( array( 'message' => 'Not found.' ), 404 );
		}
		return new WP_REST_Response( array( 'data' => self::formatear( $entrada, (bool) $peticion['includesUser'] ) ) );
	}

	/** Entrada `negocio` → objeto con los mismos campos y tipos que el BusinessResource del Laravel. */
	public static function formatear( WP_Post $entrada, bool $incluir_usuario = false ): array {
		$campo = static function ( string $clave ) use ( $entrada ) {
			$valor = get_post_meta( $entrada->ID, $clave, true );
			return '' === $valor ? null : $valor;
		};

		$negocio = array(
			'_id'         => (string) $entrada->ID,
			'name'        => $entrada->post_title,
			'description' => '' === $entrada->post_content ? null : $entrada->post_content,
			'latitude'    => self::numero( $campo( 'latitude' ) ),
			'longitude'   => self::numero( $campo( 'longitude' ) ),
		);
		foreach ( array( 'address', 'postcode', 'state', 'city', 'country', 'phone', 'email', 'web', 'schedule' ) as $clave ) {
			$negocio[ $clave ] = $campo( $clave );
		}
		foreach ( array( 'photo', 'video' ) as $tipo ) {
			for ( $i = 0; $i < 3; $i++ ) {
				$negocio[ "{$tipo}_{$i}" ] = $campo( "{$tipo}_{$i}" );
			}
		}
		$negocio['score'] = self::numero( $campo( 'score' ) ) ?? 0;

		// Los usuarios todavía no están en el plugin: de momento el dueño siempre sale vacío.
		if ( $incluir_usuario ) {
			$negocio['user'] = null;
		}
		return $negocio;
	}

	/** "42.2388906" → 42.2388906 y "0" → 0, como los números de Laravel. */
	private static function numero( $valor ) {
		if ( null === $valor || '' === $valor || ! is_numeric( $valor ) ) {
			return null;
		}
		$numero = (float) $valor;
		return floor( $numero ) === $numero ? (int) $numero : $numero;
	}

	/** links y meta como el LengthAwarePaginator de Laravel. */
	private static function paginacion( string $ruta, int $pagina, int $por_pagina, int $total, int $en_pagina ): array {
		$ultima = max( 1, (int) ceil( $total / $por_pagina ) );
		$url    = static fn( int $n ) => add_query_arg( 'page', $n, $ruta );

		$enlaces = array(
			array( 'url' => $pagina > 1 ? $url( $pagina - 1 ) : null, 'label' => '&laquo; Previous', 'active' => false ),
		);
		for ( $n = 1; $n <= $ultima; $n++ ) {
			$enlaces[] = array( 'url' => $url( $n ), 'label' => (string) $n, 'active' => $n === $pagina );
		}
		$enlaces[] = array( 'url' => $pagina < $ultima ? $url( $pagina + 1 ) : null, 'label' => 'Next &raquo;', 'active' => false );

		$desde = $en_pagina > 0 ? ( $pagina - 1 ) * $por_pagina + 1 : null;

		return array(
			'links' => array(
				'first' => $url( 1 ),
				'last'  => $url( $ultima ),
				'prev'  => $pagina > 1 ? $url( $pagina - 1 ) : null,
				'next'  => $pagina < $ultima ? $url( $pagina + 1 ) : null,
			),
			'meta'  => array(
				'current_page' => $pagina,
				'from'         => $desde,
				'last_page'    => $ultima,
				'links'        => $enlaces,
				'path'         => $ruta,
				'per_page'     => $por_pagina,
				'to'           => null === $desde ? null : $desde + $en_pagina - 1,
				'total'        => $total,
			),
		);
	}
}

<?php
/**
 * Piezas comunes de las ventanillas de la API: números, campos personalizados y paginación
 * con el mismo formato que Laravel.
 */

defined( 'ABSPATH' ) || exit;

class ZN_API {

	const POR_PAGINA_DEFECTO = 15;
	// El Laravel no pone tope (H32); aquí sí.
	const POR_PAGINA_MAX = 50;

	/** Campo personalizado de una entrada; null si no existe o está vacío (como los null de Laravel). */
	public static function campo( int $id, string $clave ): ?string {
		$valor = get_post_meta( $id, $clave, true );
		return '' === $valor ? null : (string) $valor;
	}

	/** "42.2388906" → 42.2388906 y "0" → 0, como los números de Laravel. */
	public static function numero( $valor ) {
		if ( null === $valor || '' === $valor || ! is_numeric( $valor ) ) {
			return null;
		}
		$numero = (float) $valor;
		return floor( $numero ) === $numero ? (int) $numero : $numero;
	}

	/** Argumentos comunes de los listados: page y perPage. */
	public static function argumentos_paginacion(): array {
		return array(
			'page'    => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			'perPage' => array( 'type' => 'integer', 'minimum' => 1, 'default' => self::POR_PAGINA_DEFECTO ),
		);
	}

	/**
	 * Ejecuta la consulta paginada y devuelve { data, links, meta } como el LengthAwarePaginator de Laravel.
	 * $formatear convierte cada WP_Post en el objeto de la API.
	 */
	public static function listado( WP_REST_Request $peticion, array $argumentos, string $recurso, callable $formatear ): WP_REST_Response {
		$por_pagina = min( (int) $peticion['perPage'], self::POR_PAGINA_MAX );
		$pagina     = (int) $peticion['page'];

		$argumentos = array_merge(
			$argumentos,
			array(
				'post_status'         => 'publish',
				'posts_per_page'      => $por_pagina,
				'paged'               => $pagina,
				'ignore_sticky_posts' => true,
			)
		);

		$consulta = new WP_Query( $argumentos );
		$total    = (int) $consulta->found_posts;

		// WordPress no cuenta el total cuando la página pedida queda vacía; Laravel sí lo da.
		if ( ! $consulta->posts && $pagina > 1 ) {
			$cuenta = new WP_Query( array_merge( $argumentos, array( 'paged' => 1, 'posts_per_page' => 1, 'fields' => 'ids' ) ) );
			$total  = (int) $cuenta->found_posts;
		}

		$datos = array_map( $formatear, $consulta->posts );

		return new WP_REST_Response(
			array_merge(
				array( 'data' => $datos ),
				self::paginacion( rest_url( ZN_NAMESPACE . '/' . $recurso ), $pagina, $por_pagina, $total, count( $datos ) )
			)
		);
	}

	/** Entrada publicada de un tipo, o null. */
	public static function entrada( int $id, string $tipo ): ?WP_Post {
		$entrada = get_post( $id );
		return $entrada && $tipo === $entrada->post_type && 'publish' === $entrada->post_status ? $entrada : null;
	}

	public static function no_encontrado(): WP_REST_Response {
		return new WP_REST_Response( array( 'message' => 'Not found.' ), 404 );
	}

	/** Cláusula de meta_query para ordenar por un campo sin perder las entradas que no lo tienen. */
	public static function orden_por_campo( string $nombre, string $clave, string $tipo ): array {
		return array(
			'relation' => 'OR',
			$nombre    => array( 'key' => $clave, 'compare' => 'EXISTS', 'type' => $tipo ),
			array( 'key' => $clave, 'compare' => 'NOT EXISTS' ),
		);
	}

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

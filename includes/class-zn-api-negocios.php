<?php
/**
 * GET /business y GET /business/{id} (públicos, solo lectura).
 *
 * Responde igual que el Laravel (api-muestras/business.json de la app):
 * - Listado: { data: [...], links: {first,last,prev,next}, meta: {current_page, from, last_page, links, path, per_page, to, total} }
 * - Ficha:   { data: {...} }
 * - `_id` es una cadena; fotos en photo_0..2 y vídeos en video_0..2.
 * Diferencia a propósito: perPage tiene tope de 50 (el Laravel no lo tiene, H32).
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Negocios {

	const POR_PAGINA_DEFECTO = 15;
	const POR_PAGINA_MAX     = 50;

	// Campos por los que se puede ordenar (?orderby=). Nunca se mete en el SQL nada que no esté aquí.
	const ORDENABLES = array(
		'_id'   => 'id',
		'name'  => 'name',
		'score' => 'score',
	);

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
					'orderby'      => array( 'type' => 'string', 'enum' => array_keys( self::ORDENABLES ), 'default' => '_id' ),
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
		global $wpdb;
		$tabla = ZN_Instalador::tabla_negocios();

		$por_pagina = min( (int) $peticion['perPage'], self::POR_PAGINA_MAX );
		$pagina     = (int) $peticion['page'];
		$columna    = self::ORDENABLES[ $peticion['orderby'] ];
		$sentido    = 'desc' === $peticion['order'] ? 'DESC' : 'ASC';

		$donde   = '1=1';
		$valores = array();
		if ( ! empty( $peticion['category'] ) ) {
			$donde     = 'business_category_id = %d';
			$valores[] = (int) $peticion['category'];
		}

		$sql_total = "SELECT COUNT(*) FROM $tabla WHERE $donde";
		$total     = (int) $wpdb->get_var( $valores ? $wpdb->prepare( $sql_total, $valores ) : $sql_total );

		// $columna y $sentido salen de listas fijas; el resto va con prepare(). Desempate por id para que la paginación sea estable.
		$filas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $tabla WHERE $donde ORDER BY $columna $sentido, id ASC LIMIT %d OFFSET %d",
				array_merge( $valores, array( $por_pagina, ( $pagina - 1 ) * $por_pagina ) )
			),
			ARRAY_A
		);

		$incluir_usuario = (bool) $peticion['includesUser'];
		$datos           = array_map(
			static fn( $fila ) => self::formatear( $fila, $incluir_usuario ),
			$filas
		);

		return new WP_REST_Response(
			array_merge(
				array( 'data' => $datos ),
				self::paginacion( rest_url( ZN_NAMESPACE . '/business' ), $pagina, $por_pagina, $total, count( $datos ) )
			)
		);
	}

	public static function ficha( WP_REST_Request $peticion ): WP_REST_Response {
		global $wpdb;
		$tabla = ZN_Instalador::tabla_negocios();

		$fila = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $tabla WHERE id = %d", (int) $peticion['id'] ),
			ARRAY_A
		);
		if ( ! $fila ) {
			return new WP_REST_Response( array( 'message' => 'Not found.' ), 404 );
		}
		return new WP_REST_Response( array( 'data' => self::formatear( $fila, (bool) $peticion['includesUser'] ) ) );
	}

	/** Fila de la tabla → objeto con los mismos campos y tipos que el BusinessResource del Laravel. */
	public static function formatear( array $fila, bool $incluir_usuario = false ): array {
		$negocio = array(
			'_id'         => (string) $fila['id'],
			'name'        => $fila['name'],
			'description' => $fila['description'],
			'latitude'    => self::numero( $fila['latitude'] ),
			'longitude'   => self::numero( $fila['longitude'] ),
			'address'     => $fila['address'],
			'postcode'    => $fila['postcode'],
			'state'       => $fila['state'],
			'city'        => $fila['city'],
			'country'     => $fila['country'],
			'phone'       => $fila['phone'],
			'email'       => $fila['email'],
			'web'         => $fila['web'],
			'schedule'    => $fila['schedule'],
		);
		foreach ( array( 'photo', 'video' ) as $tipo ) {
			for ( $i = 0; $i < 3; $i++ ) {
				$negocio[ "{$tipo}_{$i}" ] = $fila[ "{$tipo}_{$i}" ];
			}
		}
		$negocio['score'] = self::numero( $fila['score'] ) ?? 0;

		// Los usuarios todavía no están en el plugin: de momento el dueño siempre sale vacío.
		if ( $incluir_usuario ) {
			$negocio['user'] = null;
		}
		return $negocio;
	}

	/** "42.2388906" → 42.2388906 y "0.00" → 0, como los números de Laravel. */
	private static function numero( $valor ) {
		if ( null === $valor || '' === $valor ) {
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

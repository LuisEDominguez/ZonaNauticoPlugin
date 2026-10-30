<?php
/**
 * GET /review y GET /review/{id} (públicos, solo lectura).
 *
 * Responde igual que el ReviewResource del Laravel: _id, title, description, response, score y
 * datetime ("Y-m-d H:i:s"), más user (?includesUser=1, solo los 4 campos públicos, H27) y
 * business (?includesBusiness=1). Filtros business y user. Solo salen las reseñas aprobadas
 * de negocios publicados (ver ZN_Comentarios).
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Resenas {

	// ?orderby= admitidos (columnas del Laravel) y su equivalente en WP_Comment_Query.
	const ORDENABLES = array(
		'id'          => 'comment_ID',
		'_id'         => 'comment_ID',
		'datetime'    => 'comment_date',
		'user_id'     => 'user_id',
		'business_id' => 'comment_post_ID',
	);

	public static function registrar_rutas(): void {
		$incluir = array(
			'includesUser'     => array( 'type' => 'boolean', 'default' => false ),
			'includesBusiness' => array( 'type' => 'boolean', 'default' => false ),
		);

		register_rest_route(
			ZN_NAMESPACE,
			'/review',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'listar' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					ZN_API::argumentos_paginacion(),
					$incluir,
					array(
						'business' => array( 'type' => 'integer', 'minimum' => 1 ),
						'user'     => array( 'type' => 'integer', 'minimum' => 1 ),
						'orderby'  => array( 'type' => 'string', 'enum' => array_keys( self::ORDENABLES ), 'default' => 'id' ),
						'order'    => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'asc' ),
					)
				),
			)
		);

		register_rest_route(
			ZN_NAMESPACE,
			'/review/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ficha' ),
				'permission_callback' => '__return_true',
				'args'                => $incluir,
			)
		);
	}

	public static function listar( WP_REST_Request $peticion ): WP_REST_Response {
		$sentido    = 'desc' === $peticion['order'] ? 'DESC' : 'ASC';
		$argumentos = array(
			'type'      => ZN_Comentarios::RESENA,
			'post_type' => ZN_Negocios::TIPO,
			// Desempate por ID para que la paginación sea estable.
			'orderby'   => array_unique( array( self::ORDENABLES[ $peticion['orderby'] ], 'comment_ID' ) ),
			'order'     => $sentido,
		);
		if ( ! empty( $peticion['business'] ) ) {
			$argumentos['post_id'] = (int) $peticion['business'];
		}
		if ( ! empty( $peticion['user'] ) ) {
			$argumentos['user_id'] = (int) $peticion['user'];
		}

		$opciones = self::inclusiones( $peticion );
		return ZN_API::listado_comentarios(
			$peticion,
			$argumentos,
			'review',
			static fn( WP_Comment $resena ) => self::formatear( $resena, ...$opciones )
		);
	}

	public static function ficha( WP_REST_Request $peticion ): WP_REST_Response {
		$resena = ZN_API::comentario( (int) $peticion['id'], ZN_Comentarios::RESENA );
		if ( ! $resena ) {
			return ZN_API::no_encontrado();
		}
		return new WP_REST_Response( array( 'data' => self::formatear( $resena, ...self::inclusiones( $peticion ) ) ) );
	}

	private static function inclusiones( WP_REST_Request $peticion ): array {
		return array( (bool) $peticion['includesUser'], (bool) $peticion['includesBusiness'] );
	}

	/** Comentario `zn_resena` → objeto con los mismos campos que el ReviewResource del Laravel. */
	public static function formatear( WP_Comment $resena, bool $incluir_usuario = false, bool $incluir_negocio = false ): array {
		$id    = (int) $resena->comment_ID;
		$campo = static function ( string $clave ) use ( $id ): ?string {
			$valor = get_comment_meta( $id, $clave, true );
			return '' === $valor ? null : (string) $valor;
		};

		$datos = array(
			'_id'         => (string) $id,
			'title'       => $campo( 'title' ),
			'description' => '' === $resena->comment_content ? null : $resena->comment_content,
			'response'    => $campo( 'response' ),
			'score'       => ZN_API::numero( $campo( 'score' ) ) ?? 0,
			'datetime'    => $resena->comment_date,
		);
		if ( $incluir_usuario ) {
			$datos['user'] = ZN_API::usuario_publico( (int) $resena->user_id, $resena->comment_author );
		}
		if ( $incluir_negocio ) {
			$negocio          = ZN_API::entrada( (int) $resena->comment_post_ID, ZN_Negocios::TIPO );
			$datos['business'] = $negocio ? ZN_API_Negocios::formatear( $negocio ) : null;
		}
		return $datos;
	}
}

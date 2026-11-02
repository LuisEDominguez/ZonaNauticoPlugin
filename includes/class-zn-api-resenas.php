<?php
/**
 * GET /review y GET /review/{id} (públicos), y desde la app, con el token: POST /review, DELETE /review/{id} y
 * PATCH /review/reply/{id}.
 *
 * Responde igual que el ReviewResource del Laravel: _id, title, description, response, score y
 * datetime ("Y-m-d H:i:s", hora de Madrid), más user (?includesUser=1, solo los 4 campos públicos, H27) y
 * business (?includesBusiness=1). Filtros business y user. Solo salen las reseñas aprobadas
 * de negocios visibles (ver ZN_Comentarios).
 *
 * Escrituras, como el ReviewController del Laravel con sus fallos arreglados:
 * - POST /review {businessId, score, description?, title?}: el autor es siempre la cuenta del token (el userId
 *   que manda la app se ignora, H21); la fecha la pone el servidor; puntuación de 1 a 5 y una sola reseña por
 *   persona y negocio (H13; si ya tiene una, 422 en businessId); se publica al momento (decisión c). 201 {data: reseña}. La respuesta del negocio no se puede poner aquí.
 * - DELETE /review/{id}: quien la escribió (review:delete_own) o la administración de la app. Borrado
 *   definitivo, como el Laravel. 200 {status: "success"}.
 * - PATCH /review/reply/{id} {response}: el dueño del negocio reseñado (review:reply_own, sin herencia por el
 *   padre, como el Laravel) o la administración de la app. Solo cambia la respuesta; null la borra y "" es 422
 *   (regla del PATCH; el Laravel no dejaba borrarla). 200 {data: reseña}.
 * La puntuación del negocio se recalcula sola (ZN_Comentarios). Sin permiso, 401 «This action is unauthorized.».
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

		register_rest_route(
			ZN_NAMESPACE,
			'/review',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'crear' ),
				'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
			)
		);
		register_rest_route(
			ZN_NAMESPACE,
			'/review/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'borrar' ),
				'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
			)
		);
		register_rest_route(
			ZN_NAMESPACE,
			'/review/reply/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'responder' ),
				'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
			)
		);
	}

	public static function crear( WP_REST_Request $peticion ): WP_REST_Response {
		$cuerpo = ZN_API::cuerpo( $peticion );
		// La respuesta es del negocio (PATCH /review/reply); userId y datetime los pone el servidor.
		unset( $cuerpo['response'], $cuerpo['userId'], $cuerpo['datetime'] );
		$validado = ZN_Esquema::validar( ZN_Comentarios::RESENA, $cuerpo );
		if ( $validado['errores'] ) {
			return ZN_Errores::no_valido( $validado['errores'] );
		}
		$autor = get_current_user_id();
		if ( ! ZN_Roles::puede_crear( 'review:save', $autor ) ) {
			return ZN_Errores::sin_permiso();
		}
		// H13 (decisión de Luis: A): una reseña por persona y negocio, también si la suya está oculta.
		$tiene = get_comments( array( 'type' => ZN_Comentarios::RESENA, 'post_id' => (int) $validado['valores']['comment_post_ID'], 'user_id' => $autor, 'status' => 'any', 'count' => true ) );
		if ( $tiene ) {
			return ZN_Errores::no_valido( array( 'businessId' => ZN_Esquema::mensaje( 'unique', 'businessId' ) ) );
		}
		$id = ZN_Esquema::guardar( ZN_Comentarios::RESENA, null, $validado['valores'], array( 'user_id' => $autor, 'comment_approved' => 1 ) );
		if ( is_wp_error( $id ) ) {
			return new WP_REST_Response( array( 'message' => 'Server Error' ), 500 );
		}
		return new WP_REST_Response( array( 'data' => self::formatear( get_comment( $id ) ) ), 201 );
	}

	public static function borrar( WP_REST_Request $peticion ): WP_REST_Response {
		$resena = self::resena( (int) $peticion['id'] );
		if ( ! $resena ) {
			return ZN_API::no_encontrado();
		}
		if ( ! ZN_Roles::puede( 'review:delete', $resena ) ) {
			return ZN_Errores::sin_permiso();
		}
		if ( ! wp_delete_comment( $resena, true ) ) {
			return new WP_REST_Response( array( 'message' => 'Server Error' ), 500 );
		}
		return new WP_REST_Response( array( 'status' => 'success' ) );
	}

	public static function responder( WP_REST_Request $peticion ): WP_REST_Response {
		$resena = self::resena( (int) $peticion['id'] );
		if ( ! $resena ) {
			return ZN_API::no_encontrado();
		}
		if ( ! ZN_Roles::puede( 'review:reply', $resena ) ) {
			return ZN_Errores::sin_permiso();
		}
		$validado = ZN_Esquema::validar( ZN_Comentarios::RESENA, array_intersect_key( ZN_API::cuerpo( $peticion ), array( 'response' => true ) ), array() );
		if ( $validado['errores'] ) {
			return ZN_Errores::no_valido( $validado['errores'] );
		}
		if ( $validado['valores'] ) {
			$id = ZN_Esquema::guardar( ZN_Comentarios::RESENA, (int) $resena->comment_ID, $validado['valores'] );
			if ( is_wp_error( $id ) ) {
				return new WP_REST_Response( array( 'message' => 'Server Error' ), 500 );
			}
		}
		return new WP_REST_Response( array( 'data' => self::formatear( get_comment( (int) $resena->comment_ID ) ) ) );
	}

	/** Reseña en cualquier estado (también una oculta desde wp-admin: su autora puede borrarla); o null. */
	private static function resena( int $id ): ?WP_Comment {
		$resena = get_comment( $id );
		return $resena && ZN_Comentarios::RESENA === $resena->comment_type ? $resena : null;
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
			'title'       => ZN_API::texto_escrito( $campo( 'title' ) ),
			'description' => ZN_API::texto_escrito( $resena->comment_content ),
			'response'    => ZN_API::texto_escrito( $campo( 'response' ) ),
			'score'       => ZN_API::numero( $campo( 'score' ) ) ?? 0,
			// En hora de Madrid, sea cual sea la zona horaria del sitio (R5).
			'datetime'    => ZN_API::hora_madrid( $resena->comment_date_gmt ),
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

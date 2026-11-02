<?php
/**
 * GET /faq y GET /faq/{id} (públicos), y desde la app, con el token: POST /faq y PATCH /faq/{id}.
 *
 * Responde igual que el FaqResource del Laravel: _id, question, answer y published, más user (el
 * comercio al que se pregunta) y publisher (quien pregunta) con ?includesUser=1.
 * Filtro user: las preguntas dirigidas a ese comercio. Como en el Laravel, la pregunta guarda su
 * comercio destinatario (campo `user_id`), no depende del dueño actual del negocio (R8).
 * A diferencia del Laravel (H20), las no publicadas no salen nunca, se pida o no ?published=1.
 *
 * Escrituras, como el FaqController del Laravel:
 * - POST /faq {userId, question}: userId es el comercio destinatario (si no es un comercio, 422); quien pregunta
 *   es la cuenta del token; queda sin publicar y sin respuesta, mande lo que mande la app. Cuelga del primer
 *   negocio publicado del comercio (sale en todos sus negocios, decisión d; sin ninguno, 422). 201 {data}.
 * - PATCH /faq/{id} {question?, answer?, published?}: lo que cada cuenta puede cambiar lo dice
 *   ZN_Roles::campos_editables_de_pregunta() (R41): el comercio, su asociación y la administración de la app,
 *   todo; quien preguntó, solo el texto, y la pregunta vuelve a quedar sin publicar. Lo demás se ignora (también
 *   userId: el destinatario no cambia, H15). Regla del PATCH: null borra y "" es 422. 200 {data}.
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Preguntas {

	public static function registrar_rutas(): void {
		$incluir = array(
			'includesUser' => array( 'type' => 'boolean', 'default' => false ),
		);

		register_rest_route(
			ZN_NAMESPACE,
			'/faq',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'listar' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					ZN_API::argumentos_paginacion(),
					$incluir,
					array(
						'user'      => array( 'type' => 'integer', 'minimum' => 1 ),
						// Se admite por compatibilidad con la app; las no publicadas no salen en ningún caso.
						'published' => array( 'type' => 'boolean' ),
						'order'     => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'asc' ),
					)
				),
			)
		);

		register_rest_route(
			ZN_NAMESPACE,
			'/faq/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ficha' ),
				'permission_callback' => '__return_true',
				'args'                => $incluir,
			)
		);

		register_rest_route(
			ZN_NAMESPACE,
			'/faq',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'crear' ),
				'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
			)
		);
		register_rest_route(
			ZN_NAMESPACE,
			'/faq/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'modificar' ),
				'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
			)
		);
	}

	public static function crear( WP_REST_Request $peticion ): WP_REST_Response {
		$cuerpo   = array_intersect_key( ZN_API::cuerpo( $peticion ), array_flip( array( 'userId', 'question' ) ) );
		$validado = ZN_Esquema::validar( ZN_Comentarios::PREGUNTA, $cuerpo );
		$comercio = (int) ( $validado['valores']['user_id'] ?? 0 );
		$negocio  = 0;
		if ( $comercio && ! isset( $validado['errores']['userId'] ) ) {
			$cuenta  = get_userdata( $comercio );
			$negocio = $cuenta && 'commerce' === ZN_Roles::rol_app( $cuenta ) ? self::primer_negocio( $comercio ) : 0;
			if ( ! $negocio ) {
				$validado['errores']['userId'] = array( ZN_Esquema::mensaje( 'exists', 'userId' ) );
			}
		}
		if ( $validado['errores'] ) {
			return ZN_Errores::no_valido( $validado['errores'] );
		}
		$autor = get_current_user_id();
		if ( ! ZN_Roles::puede_crear( 'faq:save', $autor ) ) {
			return ZN_Errores::sin_permiso();
		}
		$id = ZN_Esquema::guardar(
			ZN_Comentarios::PREGUNTA,
			null,
			$validado['valores'],
			array( 'comment_post_ID' => $negocio, 'user_id' => $autor, 'comment_approved' => 0 )
		);
		if ( is_wp_error( $id ) ) {
			return new WP_REST_Response( array( 'message' => 'Server Error' ), 500 );
		}
		return new WP_REST_Response( array( 'data' => self::formatear( get_comment( $id ) ) ), 201 );
	}

	public static function modificar( WP_REST_Request $peticion ): WP_REST_Response {
		$pregunta = get_comment( (int) $peticion['id'] );
		if ( ! $pregunta || ZN_Comentarios::PREGUNTA !== $pregunta->comment_type ) {
			return ZN_API::no_encontrado();
		}
		$campos = ZN_Roles::campos_editables_de_pregunta( $pregunta );
		if ( ! $campos ) {
			return ZN_Errores::sin_permiso();
		}
		$validado = ZN_Esquema::validar( ZN_Comentarios::PREGUNTA, array_intersect_key( ZN_API::cuerpo( $peticion ), array_flip( $campos ) ), array() );
		if ( $validado['errores'] ) {
			return ZN_Errores::no_valido( $validado['errores'] );
		}
		$valores = $validado['valores'];
		// Quien preguntó y la cambia: vuelve a moderación (como el Laravel).
		if ( array( 'question' ) === $campos && $valores ) {
			$valores['comment_approved'] = 0;
		}
		if ( $valores ) {
			$id = ZN_Esquema::guardar( ZN_Comentarios::PREGUNTA, (int) $pregunta->comment_ID, $valores );
			if ( is_wp_error( $id ) ) {
				return new WP_REST_Response( array( 'message' => 'Server Error' ), 500 );
			}
		}
		return new WP_REST_Response( array( 'data' => self::formatear( get_comment( (int) $pregunta->comment_ID ) ) ) );
	}

	/** Primer negocio publicado (y visible) del comercio; 0 si no tiene. */
	private static function primer_negocio( int $comercio ): int {
		$ids = get_posts(
			array(
				'post_type'      => ZN_Negocios::TIPO,
				'post_status'    => 'publish',
				'has_password'   => false,
				'meta_query'     => array( array( 'key' => 'user_id', 'value' => $comercio, 'type' => 'NUMERIC' ) ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	public static function listar( WP_REST_Request $peticion ): WP_REST_Response {
		// Pedir solo las no publicadas no da nada.
		if ( false === $peticion['published'] ) {
			return ZN_API::respuesta_paginada( $peticion, 'faq', array(), 0 );
		}

		$argumentos = array(
			'type'      => ZN_Comentarios::PREGUNTA,
			'post_type' => ZN_Negocios::TIPO,
			'orderby'   => 'comment_ID',
			'order'     => 'desc' === $peticion['order'] ? 'DESC' : 'ASC',
		);
		if ( ! empty( $peticion['user'] ) ) {
			$argumentos['meta_query'] = array(
				array( 'key' => 'user_id', 'value' => (int) $peticion['user'], 'type' => 'NUMERIC' ),
			);
		}

		$incluir_usuario = (bool) $peticion['includesUser'];
		return ZN_API::listado_comentarios(
			$peticion,
			$argumentos,
			'faq',
			static fn( WP_Comment $pregunta ) => self::formatear( $pregunta, $incluir_usuario )
		);
	}

	public static function ficha( WP_REST_Request $peticion ): WP_REST_Response {
		$pregunta = ZN_API::comentario( (int) $peticion['id'], ZN_Comentarios::PREGUNTA );
		if ( ! $pregunta ) {
			return ZN_API::no_encontrado();
		}
		return new WP_REST_Response( array( 'data' => self::formatear( $pregunta, (bool) $peticion['includesUser'] ) ) );
	}

	/** Comentario `zn_pregunta` → objeto con los mismos campos que el FaqResource del Laravel. */
	public static function formatear( WP_Comment $pregunta, bool $incluir_usuario = false ): array {
		$id = (int) $pregunta->comment_ID;

		$datos = array(
			'_id'       => (string) $id,
			'question'  => ZN_API::texto_escrito( $pregunta->comment_content ),
			'answer'    => ZN_API::texto_escrito( (string) get_comment_meta( $id, 'answer', true ) ),
			'published' => '1' === $pregunta->comment_approved ? 1 : 0,
		);
		if ( $incluir_usuario ) {
			$datos['user']      = ZN_API::usuario_publico( (int) get_comment_meta( $id, 'user_id', true ) );
			$datos['publisher'] = ZN_API::usuario_publico( (int) $pregunta->user_id, $pregunta->comment_author );
		}
		return $datos;
	}
}

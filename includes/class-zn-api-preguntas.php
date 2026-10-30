<?php
/**
 * GET /faq y GET /faq/{id} (públicos, solo lectura).
 *
 * Responde igual que el FaqResource del Laravel: _id, question, answer y published, más user (el
 * comercio al que se pregunta) y publisher (quien pregunta) con ?includesUser=1.
 * Filtro user: las preguntas de los negocios de ese usuario (en Laravel las FAQ son del comercio,
 * no del negocio). A diferencia del Laravel (H20), las no publicadas no salen nunca, se pida o no
 * ?published=1.
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
			$argumentos['post_author'] = (int) $peticion['user'];
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
		$respuesta = get_comment_meta( (int) $pregunta->comment_ID, 'answer', true );

		$datos = array(
			'_id'       => (string) $pregunta->comment_ID,
			'question'  => '' === $pregunta->comment_content ? null : $pregunta->comment_content,
			'answer'    => '' === $respuesta ? null : (string) $respuesta,
			'published' => '1' === $pregunta->comment_approved ? 1 : 0,
		);
		if ( $incluir_usuario ) {
			$datos['user']      = ZN_API::usuario_publico( (int) get_post_field( 'post_author', (int) $pregunta->comment_post_ID ) );
			$datos['publisher'] = ZN_API::usuario_publico( (int) $pregunta->user_id, $pregunta->comment_author );
		}
		return $datos;
	}
}

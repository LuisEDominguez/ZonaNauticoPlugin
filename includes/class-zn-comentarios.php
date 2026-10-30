<?php
/**
 * Reseñas y preguntas guardadas como comentarios de WordPress sobre la entrada `negocio`:
 * - reseña (tipo `zn_resena`): texto → contenido; score, title y response → campos del comentario;
 *   datetime → fecha del comentario. Solo cuentan las aprobadas.
 * - pregunta (tipo `zn_pregunta`): pregunta → contenido; answer → campo del comentario;
 *   published = aprobada. El comercio destinatario (`user` en Laravel) es el autor del negocio.
 * Quien escribe es el usuario del comentario (o, en datos de prueba, solo su nombre).
 *
 * Los comentarios nativos de WordPress quedan cerrados: la app solo entra por /zonanautico/v1.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Comentarios {

	const RESENA   = 'zn_resena';
	const PREGUNTA = 'zn_pregunta';

	public static function iniciar(): void {
		// Nadie puede comentar por las vías de WordPress (formulario, XML-RPC, /wp/v2/comments).
		add_filter( 'comments_open', array( __CLASS__, 'cerrar_negocios' ), 10, 2 );
		add_filter( 'pings_open', array( __CLASS__, 'cerrar_negocios' ), 10, 2 );
		add_filter( 'rest_endpoints', array( __CLASS__, 'quitar_ruta_comentarios' ) );
		// Y fuera de wp-admin no se cuelan en listas, widgets ni feeds de comentarios.
		add_filter( 'comments_clauses', array( __CLASS__, 'ocultar_fuera_de_admin' ), 10, 2 );
		add_filter( 'comment_feed_where', array( __CLASS__, 'ocultar_en_feed' ) );

		// La puntuación del negocio es la media de sus reseñas aprobadas (H14: nunca se escribe a mano).
		add_action( 'wp_insert_comment', array( __CLASS__, 'al_cambiar_resena' ), 10, 2 );
		add_action( 'edit_comment', array( __CLASS__, 'al_cambiar_resena' ) );
		add_action( 'deleted_comment', array( __CLASS__, 'al_cambiar_resena' ), 10, 2 );
		add_action(
			'transition_comment_status',
			static fn( $nuevo, $antes, $comentario ) => self::al_cambiar_resena( (int) $comentario->comment_ID, $comentario ),
			10,
			3
		);
	}

	public static function cerrar_negocios( $abiertos, $entrada ) {
		return ZN_Negocios::TIPO === get_post_type( $entrada ) ? false : $abiertos;
	}

	public static function quitar_ruta_comentarios( array $rutas ): array {
		foreach ( array_keys( $rutas ) as $ruta ) {
			if ( 0 === strpos( $ruta, '/wp/v2/comments' ) ) {
				unset( $rutas[ $ruta ] );
			}
		}
		return $rutas;
	}

	/** Las consultas que no piden expresamente reseñas o preguntas no las ven (salvo en wp-admin). */
	public static function ocultar_fuera_de_admin( array $sql, WP_Comment_Query $consulta ): array {
		$pedidos = array_merge( (array) $consulta->query_vars['type'], (array) $consulta->query_vars['type__in'] );
		if ( is_admin() || array_intersect( $pedidos, array( self::RESENA, self::PREGUNTA ) ) ) {
			return $sql;
		}
		$sql['where'] .= self::sin_nuestros_tipos();
		return $sql;
	}

	public static function ocultar_en_feed( string $where ): string {
		return $where . self::sin_nuestros_tipos();
	}

	private static function sin_nuestros_tipos(): string {
		global $wpdb;
		return $wpdb->prepare( " AND {$wpdb->comments}.comment_type NOT IN (%s, %s)", self::RESENA, self::PREGUNTA );
	}

	/** @param int|string $id */
	public static function al_cambiar_resena( $id, $comentario = null ): void {
		$comentario = $comentario instanceof WP_Comment ? $comentario : get_comment( $id );
		if ( $comentario && self::RESENA === $comentario->comment_type ) {
			self::recalcular_puntuacion( (int) $comentario->comment_post_ID );
		}
	}

	/** Guarda en el negocio la media (2 decimales) de sus reseñas aprobadas; 0 si no tiene. */
	public static function recalcular_puntuacion( int $negocio ): void {
		if ( ZN_Negocios::TIPO !== get_post_type( $negocio ) ) {
			return;
		}
		$puntos = array();
		foreach ( get_comments( array( 'post_id' => $negocio, 'type' => self::RESENA, 'status' => 'approve', 'fields' => 'ids' ) ) as $id ) {
			$puntos[] = (float) get_comment_meta( $id, 'score', true );
		}
		$media = $puntos ? round( array_sum( $puntos ) / count( $puntos ), 2 ) : 0;
		update_post_meta( $negocio, 'score', $media );
	}
}

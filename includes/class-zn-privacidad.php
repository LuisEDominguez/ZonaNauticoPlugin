<?php
/**
 * Datos personales de la app (R65, RGPD):
 * - Reseñas y preguntas no guardan la IP, el navegador ni el correo de quien escribe: se vacían al crearlas y al
 *   editarlas, vengan de la API, de wp-admin o de otro plugin. Los comentarios que no son de la app no se tocan.
 * - Al borrar una cuenta (decisión e de Luis, como la cascada del Laravel) se borran del todo las reseñas y
 *   preguntas que escribió y las preguntas que le hicieron si es un comercio; las puntuaciones se recalculan
 *   solas (ZN_Comentarios, al borrar cada reseña). Sus negocios, novedades y sorteos no se borran (R95 y R96,
 *   decisión A de Luis): pasan a borrador y se quedan sin dueño, con sus reseñas, y la administración decide qué
 *   hacer con ellos. Los que estén en la papelera siguen allí, también sin dueño.
 * - Herramientas › Exportar / Borrar datos personales de WordPress incluyen los datos de la app: la cuenta
 *   (nombre, correo, teléfono, ubicación, rol), sus reseñas y sus preguntas. Borrar quita sus reseñas y
 *   preguntas, el teléfono y la ubicación; la cuenta (nombre y correo) sigue y se borra en Usuarios.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Privacidad {

	const CLAVE = 'zona-nautico';

	// Comentarios por llamada al exportar o borrar (las herramientas de WordPress van pidiendo páginas).
	const POR_PAGINA = 100;

	public static function iniciar(): void {
		add_action( 'wp_insert_comment', array( __CLASS__, 'vaciar_al_crear' ), 1, 2 );
		add_filter( 'wp_update_comment_data', array( __CLASS__, 'vaciar_al_editar' ), 10, 2 );
		add_action( 'delete_user', array( __CLASS__, 'al_borrar_cuenta' ) );
		add_action( 'wpmu_delete_user', array( __CLASS__, 'al_borrar_cuenta' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'registrar_exportador' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'registrar_borrador' ) );
	}

	private static function es_de_la_app( $comentario ): bool {
		$tipo = $comentario instanceof WP_Comment ? $comentario->comment_type : ( $comentario['comment_type'] ?? '' );
		return in_array( $tipo, array( ZN_Comentarios::RESENA, ZN_Comentarios::PREGUNTA ), true );
	}

	/** Acción wp_insert_comment: wp_insert_comment() no tiene filtro antes de guardar, así que se vacía justo después. */
	public static function vaciar_al_crear( $id, $comentario ): void {
		if ( ! $comentario instanceof WP_Comment || ! self::es_de_la_app( $comentario ) ) {
			return;
		}
		if ( '' === $comentario->comment_author_IP && '' === $comentario->comment_agent && '' === $comentario->comment_author_email ) {
			return;
		}
		global $wpdb;
		$wpdb->update( $wpdb->comments, array( 'comment_author_IP' => '', 'comment_agent' => '', 'comment_author_email' => '' ), array( 'comment_ID' => (int) $id ) );
		clean_comment_cache( (int) $id );
		$comentario->comment_author_IP    = '';
		$comentario->comment_agent        = '';
		$comentario->comment_author_email = '';
	}

	/** Filtro wp_update_comment_data: al editar tampoco se guardan. */
	public static function vaciar_al_editar( $datos, $comentario ) {
		if ( is_array( $datos ) && is_array( $comentario ) && self::es_de_la_app( $comentario ) ) {
			$datos['comment_author_IP']    = '';
			$datos['comment_agent']        = '';
			$datos['comment_author_email'] = '';
		}
		return $datos;
	}

	/** Acción delete_user (antes de borrar la cuenta). */
	public static function al_borrar_cuenta( $id ): void {
		$id = (int) $id;
		foreach ( array_merge( self::escritos_por( $id ), self::preguntas_a( $id ) ) as $comentario ) {
			wp_delete_comment( $comentario, true );
		}
		foreach ( self::entradas_de( $id ) as $entrada ) {
			if ( 'trash' !== get_post_status( $entrada ) ) {
				wp_update_post( array( 'ID' => $entrada, 'post_status' => 'draft' ) );
			}
			delete_post_meta( $entrada, 'user_id' );
		}
	}

	/** Ids de los negocios, novedades y sorteos cuyo dueño (campo user_id) es la cuenta, en cualquier estado. */
	private static function entradas_de( int $id ): array {
		if ( $id <= 0 ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => array( ZN_Negocios::TIPO, ZN_Eventos::TIPO, ZN_Sorteos::TIPO ),
					'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
					'meta_query'     => array( array( 'key' => 'user_id', 'value' => $id, 'type' => 'NUMERIC' ) ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			)
		);
	}

	/** Ids de las reseñas y preguntas que escribió la cuenta, en cualquier estado. */
	private static function escritos_por( int $id, int $cuantos = 0 ): array {
		if ( $id <= 0 ) {
			return array();
		}
		return array_map(
			'intval',
			get_comments(
				array(
					'type__in' => array( ZN_Comentarios::RESENA, ZN_Comentarios::PREGUNTA ),
					'user_id'  => $id,
					'status'   => 'any',
					'fields'   => 'ids',
					'orderby'  => 'comment_ID',
					'order'    => 'ASC',
					'number'   => $cuantos,
				)
			)
		);
	}

	/** Ids de las preguntas dirigidas a la cuenta (si es un comercio), en cualquier estado. */
	private static function preguntas_a( int $id ): array {
		if ( $id <= 0 ) {
			return array();
		}
		return array_map(
			'intval',
			get_comments(
				array(
					'type'       => ZN_Comentarios::PREGUNTA,
					'status'     => 'any',
					'fields'     => 'ids',
					'meta_query' => array( array( 'key' => 'user_id', 'value' => $id, 'type' => 'NUMERIC' ) ),
				)
			)
		);
	}

	public static function registrar_exportador( array $exportadores ): array {
		$exportadores[ self::CLAVE ] = array(
			'exporter_friendly_name' => 'App Zona Náutico',
			'callback'               => array( __CLASS__, 'exportar' ),
		);
		return $exportadores;
	}

	public static function registrar_borrador( array $borradores ): array {
		$borradores[ self::CLAVE ] = array(
			'eraser_friendly_name' => 'App Zona Náutico',
			'callback'             => array( __CLASS__, 'borrar' ),
		);
		return $borradores;
	}

	/** Exportador: la cuenta en la página 1 y sus reseñas y preguntas de POR_PAGINA en POR_PAGINA. */
	public static function exportar( string $email, int $pagina = 1 ): array {
		$cuenta = get_user_by( 'email', $email );
		if ( ! $cuenta ) {
			return array( 'data' => array(), 'done' => true );
		}
		$pagina    = max( 1, $pagina );
		$elementos = array();

		if ( 1 === $pagina ) {
			$meta = static fn( string $clave ) => (string) get_user_meta( $cuenta->ID, $clave, true );
			$elementos[] = array(
				'group_id'    => 'zn-cuenta',
				'group_label' => 'Cuenta de la app Zona Náutico',
				'item_id'     => "zn-cuenta-{$cuenta->ID}",
				'data'        => self::sin_vacios(
					array(
						'Nombre'           => trim( $cuenta->first_name . ' ' . $cuenta->last_name ),
						'Correo'           => $cuenta->user_email,
						'Correo nuevo sin confirmar' => $meta( 'zn_email_nuevo' ),
						'Teléfono'         => $meta( 'zn_phone' ),
						'Latitud'          => $meta( 'zn_latitude' ),
						'Longitud'         => $meta( 'zn_longitude' ),
						'Rol en la app'    => (string) ZN_Roles::rol_app( $cuenta ),
						'Correo verificado' => '1' === $meta( 'zn_verificado' ) ? 'Sí' : 'No',
					)
				),
			);
			// Participaciones en sorteos: sorteo, fecha, si ganó y si se entregó (sin la foto del ticket).
			foreach ( ZN_Participaciones::para_exportar( $cuenta->ID ) as $participacion ) {
				$elementos[] = array(
					'group_id'    => 'zn-participaciones',
					'group_label' => 'Participaciones en sorteos (app Zona Náutico)',
					'item_id'     => 'zn-participacion-' . $participacion['id'],
					'data'        => $participacion['datos'],
				);
			}
			// Favoritos (bloque 2): los negocios que ha marcado.
			foreach ( ZN_Favoritos::de( $cuenta->ID ) as $negocio ) {
				$elementos[] = array(
					'group_id'    => 'zn-favoritos',
					'group_label' => 'Negocios favoritos (app Zona Náutico)',
					'item_id'     => "zn-favorito-{$cuenta->ID}-$negocio",
					'data'        => array( array( 'name' => 'Negocio', 'value' => get_the_title( $negocio ) ?: "#$negocio" ) ),
				);
			}
		}

		$ids = get_comments(
			array(
				'type__in' => array( ZN_Comentarios::RESENA, ZN_Comentarios::PREGUNTA ),
				'user_id'  => $cuenta->ID,
				'status'   => 'any',
				'orderby'  => 'comment_ID',
				'order'    => 'ASC',
				'number'   => self::POR_PAGINA,
				'offset'   => ( $pagina - 1 ) * self::POR_PAGINA,
			)
		);
		foreach ( $ids as $comentario ) {
			$id      = (int) $comentario->comment_ID;
			$negocio = get_the_title( (int) $comentario->comment_post_ID );
			$fecha   = ZN_API::hora_madrid( $comentario->comment_date_gmt );
			if ( ZN_Comentarios::RESENA === $comentario->comment_type ) {
				$elementos[] = array(
					'group_id'    => 'zn-resenas',
					'group_label' => 'Reseñas (app Zona Náutico)',
					'item_id'     => "zn-resena-$id",
					'data'        => self::sin_vacios(
						array(
							'Negocio'               => $negocio,
							'Puntuación'            => (string) get_comment_meta( $id, 'score', true ),
							'Título'                => (string) get_comment_meta( $id, 'title', true ),
							'Texto'                 => $comentario->comment_content,
							'Respuesta del negocio' => (string) get_comment_meta( $id, 'response', true ),
							'Fecha'                 => (string) $fecha,
							'Publicada'             => '1' === $comentario->comment_approved ? 'Sí' : 'No',
						)
					),
				);
			} else {
				$elementos[] = array(
					'group_id'    => 'zn-preguntas',
					'group_label' => 'Preguntas hechas (app Zona Náutico)',
					'item_id'     => "zn-pregunta-$id",
					'data'        => self::sin_vacios(
						array(
							'Negocio'   => $negocio,
							'Pregunta'  => $comentario->comment_content,
							'Respuesta' => (string) get_comment_meta( $id, 'answer', true ),
							'Fecha'     => (string) $fecha,
							'Publicada' => '1' === $comentario->comment_approved ? 'Sí' : 'No',
						)
					),
				);
			}
		}

		return array( 'data' => $elementos, 'done' => count( $ids ) < self::POR_PAGINA );
	}

	/**
	 * Borrador: quita POR_PAGINA reseñas o preguntas por llamada (WordPress vuelve a llamar hasta done) y, en la
	 * primera, el teléfono y la ubicación. La cuenta sigue: borrarla es aparte (Usuarios), y entonces se va todo.
	 */
	public static function borrar( string $email, int $pagina = 1 ): array {
		$cuenta = get_user_by( 'email', $email );
		if ( ! $cuenta ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		$quitado = false;
		foreach ( array( 'zn_phone', 'zn_latitude', 'zn_longitude', 'zn_email_nuevo', 'zn_email_nuevo_token', 'zn_email_nuevo_caduca' ) as $clave ) {
			$quitado = delete_user_meta( $cuenta->ID, $clave ) || $quitado;
		}
		$quitado = ZN_Favoritos::borrar_de( $cuenta->ID ) > 0 || $quitado;
		// Participaciones: fuera, salvo un premio aún sin entregar (decisión 7), del que se guardan nombre y correo.
		list( $participaciones, $pendientes ) = ZN_Participaciones::borrar_de( $cuenta->ID );
		$quitado = $participaciones > 0 || $pendientes > 0 || $quitado;
		// Siempre los primeros que queden: los de la llamada anterior ya no existen.
		$ids = self::escritos_por( $cuenta->ID, self::POR_PAGINA );
		foreach ( $ids as $id ) {
			$quitado = wp_delete_comment( $id, true ) || $quitado;
		}
		return array(
			'items_removed'  => $quitado,
			'items_retained' => true,
			'messages'       => array_merge(
				array( 'La cuenta (nombre y correo) sigue en Usuarios: para quitarla del todo, bórrala allí.' ),
				$pendientes ? array( 'Ha ganado un sorteo cuyo premio aún no se ha entregado: se guardan su nombre y su correo hasta la entrega.' ) : array()
			),
			'done'           => count( $ids ) < self::POR_PAGINA,
		);
	}

	/** [nombre => valor] → [{name, value}] sin los vacíos. */
	private static function sin_vacios( array $campos ): array {
		$datos = array();
		foreach ( $campos as $nombre => $valor ) {
			if ( '' !== trim( (string) $valor ) ) {
				$datos[] = array( 'name' => $nombre, 'value' => (string) $valor );
			}
		}
		return $datos;
	}
}

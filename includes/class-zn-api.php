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
				// Las protegidas con contraseña tampoco salen (R11).
				'has_password'        => false,
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

		return self::respuesta_paginada( $peticion, $recurso, array_map( $formatear, $consulta->posts ), $total );
	}

	/**
	 * Listado de comentarios (reseñas y preguntas) con la misma paginación. $argumentos van a WP_Comment_Query;
	 * solo salen los aprobados de negocios visibles (publicados y sin contraseña).
	 */
	public static function listado_comentarios( WP_REST_Request $peticion, array $argumentos, string $recurso, callable $formatear ): WP_REST_Response {
		$por_pagina = min( (int) $peticion['perPage'], self::POR_PAGINA_MAX );
		$argumentos = array_merge( $argumentos, array( 'status' => 'approve', 'post__in' => ZN_Negocios::visibles() ?: array( 0 ) ) );

		$total       = (int) get_comments( array_merge( $argumentos, array( 'count' => true, 'orderby' => 'none' ) ) );
		$comentarios = get_comments(
			array_merge(
				$argumentos,
				array(
					'number'        => $por_pagina,
					'offset'        => ( (int) $peticion['page'] - 1 ) * $por_pagina,
					'no_found_rows' => true,
				)
			)
		);

		return self::respuesta_paginada( $peticion, $recurso, array_map( $formatear, $comentarios ), $total );
	}

	/** { data, links, meta } a partir de los elementos ya formateados de la página y el total. */
	public static function respuesta_paginada( WP_REST_Request $peticion, string $recurso, array $datos, int $total ): WP_REST_Response {
		$por_pagina = min( (int) $peticion['perPage'], self::POR_PAGINA_MAX );
		return new WP_REST_Response(
			array_merge(
				array( 'data' => $datos ),
				self::paginacion( rest_url( ZN_NAMESPACE . '/' . $recurso ), (int) $peticion['page'], $por_pagina, $total, count( $datos ) )
			)
		);
	}

	/** Comentario aprobado de un tipo, en un negocio visible; o null. */
	public static function comentario( int $id, string $tipo ): ?WP_Comment {
		$comentario = get_comment( $id );
		if ( ! $comentario || $tipo !== $comentario->comment_type || '1' !== $comentario->comment_approved ) {
			return null;
		}
		return self::entrada( (int) $comentario->comment_post_ID, ZN_Negocios::TIPO ) ? $comentario : null;
	}

	/**
	 * Usuario como lo da el UserResource del Laravel a quien no ha iniciado sesión (H27): solo _id, name,
	 * role y avatar. Solo cuentas de la app (ver ZN_Roles): una cuenta de wp-admin nunca sale (R1).
	 * Sin cuenta (datos de prueba, $id = 0) sale con _id null y el nombre guardado en el comentario.
	 */
	public static function usuario_publico( int $id, string $nombre = '' ): ?array {
		if ( ! $id ) {
			return '' === $nombre ? null : array( '_id' => null, 'name' => $nombre, 'role' => 'user', 'avatar' => null );
		}
		$usuario = get_userdata( $id );
		$rol     = $usuario ? ZN_Roles::rol_app( $usuario ) : null;
		if ( ! $rol ) {
			return null;
		}
		return array(
			'_id'    => (string) $usuario->ID,
			'name'   => self::nombre_publico( $usuario ),
			'role'   => $rol,
			// Sin Gravatar: delataría el correo (su huella) a cualquiera.
			'avatar' => null,
		);
	}

	/** Nombre y apellidos de la cuenta; nunca el nombre de acceso ni el correo (R10). */
	public static function nombre_publico( WP_User $usuario ): ?string {
		$nombre = trim( $usuario->first_name . ' ' . $usuario->last_name );
		return '' === $nombre ? null : $nombre;
	}

	/** Texto sin etiquetas ni entidades del editor visual, conservando los saltos de línea (R6). Vacío → null. */
	public static function texto_plano( ?string $html ): ?string {
		if ( null === $html ) {
			return null;
		}
		// Un <br> o fin de párrafo seguido de salto de línea es un solo salto.
		$texto = preg_replace( '#(<br\s*/?>|</(p|div|li|h[1-6])>)[ \t]*\r?\n?#i', "\n", $html );
		$texto = html_entity_decode( wp_strip_all_tags( $texto ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$texto = str_replace( array( "\u{00A0}", "\r\n" ), array( ' ', "\n" ), $texto );
		$texto = preg_replace( array( "/[ \t]+\n/", "/\n{3,}/" ), array( "\n", "\n\n" ), $texto );
		$texto = trim( $texto );
		return '' === $texto ? null : $texto;
	}

	/**
	 * Texto que escribe una persona (reseñas y preguntas): sale tal cual, solo con las entidades
	 * decodificadas. Nada de quitar etiquetas: «me encantó <3» o «<12 años» se cortarían (R20).
	 */
	public static function texto_escrito( ?string $texto ): ?string {
		$texto = trim( html_entity_decode( (string) $texto, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return '' === $texto ? null : $texto;
	}

	/** Fecha guardada en UTC → "Y-m-d H:i:s" en hora de Madrid, como el Laravel (R5). */
	public static function hora_madrid( string $utc ): ?string {
		if ( '' === $utc || 0 === strpos( $utc, '0000-00-00' ) ) {
			return null;
		}
		return ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )
			->setTimezone( new DateTimeZone( 'Europe/Madrid' ) )
			->format( 'Y-m-d H:i:s' );
	}

	/** Entrada publicada y sin contraseña (R11) de un tipo, o null. */
	public static function entrada( int $id, string $tipo ): ?WP_Post {
		$entrada = get_post( $id );
		return $entrada && $tipo === $entrada->post_type && 'publish' === $entrada->post_status && '' === $entrada->post_password ? $entrada : null;
	}

	/**
	 * permission_callback de las rutas privadas: hay sesión y es de una cuenta de la app. Sin sesión WordPress
	 * responde 401 y ZN_Errores lo deja en «Unauthenticated.»; con otra cuenta, «This action is unauthorized.».
	 */
	public static function con_sesion_de_la_app(): bool {
		return is_user_logged_in() && null !== ZN_Roles::rol_app( wp_get_current_user() );
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

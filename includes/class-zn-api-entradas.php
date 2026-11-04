<?php
/**
 * Escrituras de novedades y sorteos desde la app, con el token: POST /event, PUT|PATCH /event/{id},
 * DELETE /event/{id} y lo mismo en /draw. Como el EventController y el DrawController del Laravel, con sus fallos
 * arreglados:
 * - POST: valida con ZN_Esquema (obligatorios del Laravel: novedad name y userId; sorteo title, userId, startDate,
 *   endDate y winners ≥ 1; fechas "Y-m-d H:i:s", el fin no antes del inicio) y el dueño con permitir_dueno() (R79):
 *   a su nombre con `_own`, o a nombre de una hija la asociación o federación en novedades; si no, 401 (H15). En
 *   novedades, el dueño y el negocio cumplen R70. Se publica al momento. 201 {data} como el GET.
 * - PUT|PATCH: quien puede `<recurso>:save` sobre lo guardado (ZN_Roles::puede: el dueño, su asociación o federación
 *   en novedades, la administración de la app); si no, 401. Solo cambian los campos que llegan (regla del PATCH:
 *   null borra, "" es 422; un PATCH sin legalUrl ya no lo borra, H22). El dueño solo lo cambia la administración. 200 {data}.
 * - DELETE: `<recurso>:delete` sobre lo guardado. Borrado definitivo, como el Laravel (las participaciones de un
 *   sorteo, cuando existan, irán con él). 200 {status: "success"}.
 * - Crear y modificar cuentan para el límite de escrituras por cuenta (R94; 429). Borrar, no.
 * - Las entradas se guardan sin autor de WordPress (post_author 0): el dueño es el campo user_id, y así borrar la
 *   cuenta no las borra por detrás (R95, R96).
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Entradas {

	/** Ruta de la app => tipo de entrada y nombre del recurso en los permisos del Laravel. */
	public static function recursos(): array {
		return array(
			'event' => array( 'tipo' => ZN_Eventos::TIPO, 'permiso' => 'event' ),
			'draw'  => array( 'tipo' => ZN_Sorteos::TIPO, 'permiso' => 'draw' ),
		);
	}

	public static function registrar_rutas(): void {
		foreach ( array_keys( self::recursos() ) as $ruta ) {
			register_rest_route(
				ZN_NAMESPACE,
				"/$ruta",
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => static fn( WP_REST_Request $peticion ) => self::crear( $ruta, $peticion ),
					'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
				)
			);
			register_rest_route(
				ZN_NAMESPACE,
				"/$ruta/(?P<id>\\d+)",
				array(
					array(
						'methods'             => 'PUT, PATCH',
						'callback'            => static fn( WP_REST_Request $peticion ) => self::modificar( $ruta, $peticion ),
						'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => static fn( WP_REST_Request $peticion ) => self::borrar( $ruta, $peticion ),
						'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
					),
				)
			);
		}
	}

	public static function crear( string $ruta, WP_REST_Request $peticion ): WP_REST_Response {
		$tipo   = self::recursos()[ $ruta ]['tipo'];
		$espera = ZN_API::limite_de_escritura();
		if ( null !== $espera ) {
			return ZN_Limite::demasiados( $espera );
		}
		$validado = ZN_Esquema::permitir_dueno( $tipo, ZN_Esquema::validar( $tipo, ZN_API::cuerpo( $peticion ) ) );
		if ( $validado['errores'] ) {
			return ZN_Errores::no_valido( $validado['errores'] );
		}
		if ( ! empty( $validado['sin_permiso'] ) ) {
			return ZN_Errores::sin_permiso();
		}
		$id = ZN_Esquema::guardar( $tipo, null, $validado['valores'], array( 'post_status' => 'publish', 'post_author' => 0 ) );
		if ( is_wp_error( $id ) ) {
			return new WP_REST_Response( array( 'message' => 'Server Error' ), 500 );
		}
		return new WP_REST_Response( array( 'data' => self::formatear( $ruta, get_post( $id ) ) ), 201 );
	}

	public static function modificar( string $ruta, WP_REST_Request $peticion ): WP_REST_Response {
		$recurso = self::recursos()[ $ruta ];
		$entrada = self::entrada( $recurso['tipo'], (int) $peticion['id'] );
		if ( ! $entrada ) {
			return ZN_API::no_existe( "{$recurso['permiso']}:save" );
		}
		if ( ! ZN_Roles::puede( "{$recurso['permiso']}:save", $entrada ) ) {
			return ZN_Errores::sin_permiso();
		}
		$espera = ZN_API::limite_de_escritura();
		if ( null !== $espera ) {
			return ZN_Limite::demasiados( $espera );
		}
		$validado = ZN_Esquema::permitir_dueno(
			$recurso['tipo'],
			ZN_Esquema::validar( $recurso['tipo'], ZN_API::cuerpo( $peticion ), self::guardado( $recurso['tipo'], $entrada ) ),
			$entrada
		);
		if ( $validado['errores'] ) {
			return ZN_Errores::no_valido( $validado['errores'] );
		}
		if ( $validado['valores'] ) {
			$id = ZN_Esquema::guardar( $recurso['tipo'], $entrada->ID, $validado['valores'] );
			if ( is_wp_error( $id ) ) {
				return new WP_REST_Response( array( 'message' => 'Server Error' ), 500 );
			}
		}
		return new WP_REST_Response( array( 'data' => self::formatear( $ruta, get_post( $entrada->ID ) ) ) );
	}

	public static function borrar( string $ruta, WP_REST_Request $peticion ): WP_REST_Response {
		$recurso = self::recursos()[ $ruta ];
		$entrada = self::entrada( $recurso['tipo'], (int) $peticion['id'] );
		if ( ! $entrada ) {
			return ZN_API::no_existe( "{$recurso['permiso']}:delete" );
		}
		if ( ! ZN_Roles::puede( "{$recurso['permiso']}:delete", $entrada ) ) {
			return ZN_Errores::sin_permiso();
		}
		if ( ! wp_delete_post( $entrada->ID, true ) ) {
			return new WP_REST_Response( array( 'message' => 'Server Error' ), 500 );
		}
		return new WP_REST_Response( array( 'status' => 'success' ) );
	}

	/** Entrada de ese tipo que no esté en la papelera (también un borrador: su dueño puede editarlo); o null. */
	private static function entrada( string $tipo, int $id ): ?WP_Post {
		$entrada = get_post( $id );
		return $entrada && $tipo === $entrada->post_type && ! in_array( $entrada->post_status, array( 'trash', 'auto-draft' ), true ) ? $entrada : null;
	}

	/** Valores guardados (nombre guardado => valor), para las comprobaciones entre campos del PATCH (fechas). */
	private static function guardado( string $tipo, WP_Post $entrada ): array {
		$actual = array();
		foreach ( array_keys( ZN_Esquema::metas( $tipo ) ) as $clave ) {
			$actual[ $clave ] = get_post_meta( $entrada->ID, $clave, true );
		}
		return $actual;
	}

	private static function formatear( string $ruta, WP_Post $entrada ): array {
		return 'event' === $ruta ? ZN_API_Eventos::formatear( $entrada ) : ZN_API_Sorteos::formatear( $entrada );
	}
}

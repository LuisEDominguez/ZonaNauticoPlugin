<?php
/**
 * Sorteos y concursos como entradas personalizadas (tipo `sorteo`):
 * - título → título de la entrada, descripción → contenido;
 * - el resto → campos personalizados con el nombre de la columna de la tabla `draws` del Laravel.
 * controlParticipar y controlVisibilidad no se guardan: se calculan con las fechas (ver ZN_API_Sorteos).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Sorteos {

	const TIPO = 'sorteo';

	/** Campos personalizados (nombres de la tabla `draws` del Laravel) y su tipo: salen de ZN_Esquema (R58). */
	public static function campos(): array {
		return ZN_Esquema::metas( self::TIPO );
	}

	public static function registrar(): void {
		register_post_type(
			self::TIPO,
			array(
				'labels'        => array(
					'name'          => 'Sorteos',
					'singular_name' => 'Sorteo',
					'add_new'       => 'Añadir sorteo',
					'add_new_item'  => 'Añadir sorteo',
					'edit_item'     => 'Editar sorteo',
					'all_items'     => 'Todos los sorteos',
				),
				// Sin página pública ni ruta /wp/v2: la app solo entra por /zonanautico/v1/draw.
				'public'        => false,
				'show_ui'       => true,
				'show_in_rest'  => false,
				'menu_icon'     => 'dashicons-tickets-alt',
				'menu_position' => 27,
				'supports'      => array( 'title', 'editor' ),
				// Permisos propios (no los de las entradas normales): solo quien tenga edit_zn_sorteos y compañía (R3).
				'capability_type' => array( 'zn_sorteo', 'zn_sorteos' ),
				'map_meta_cap'    => true,
			)
		);

		foreach ( self::campos() as $campo => $tipo ) {
			register_post_meta(
				self::TIPO,
				$campo,
				array(
					'type'          => $tipo,
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static fn( $permitido, $clave, $id ) => current_user_can( 'edit_post', (int) $id ),
				)
			);
		}
	}

	/** Ahora en hora de Madrid, con el formato de las fechas guardadas (las compara el Laravel igual). */
	public static function ahora(): string {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/Madrid' ) ) )->format( 'Y-m-d H:i:s' );
	}
}

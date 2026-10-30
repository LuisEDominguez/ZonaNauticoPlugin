<?php
/**
 * Negocios guardados como entradas personalizadas de WordPress (tipo `negocio`):
 * - nombre → título de la entrada, descripción → contenido;
 * - el resto de datos → campos personalizados con el mismo nombre que la columna del Laravel.
 * `_id` en la API es el ID de la entrada.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Negocios {

	const TIPO = 'negocio';

	// Campos extra y su tipo. Los nombres son los de la tabla `businesses` del Laravel.
	const CAMPOS = array(
		'business_category_id' => 'integer',
		'latitude'             => 'number',
		'longitude'            => 'number',
		'address'              => 'string',
		'postcode'             => 'string',
		'state'                => 'string',
		'city'                 => 'string',
		'country'              => 'string',
		'phone'                => 'string',
		'email'                => 'string',
		'web'                  => 'string',
		'schedule'             => 'string',
		'photo_0'              => 'string',
		'photo_1'              => 'string',
		'photo_2'              => 'string',
		'video_0'              => 'string',
		'video_1'              => 'string',
		'video_2'              => 'string',
		'score'                => 'number',
		// Dueño: cuenta de la app con rol Comercio (no el autor de la entrada, R1).
		'user_id'              => 'integer',
	);

	/** IDs de los negocios que la API enseña: publicados y sin contraseña. */
	public static function visibles(): array {
		$ids = get_posts(
			array(
				'post_type'        => self::TIPO,
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		return array_map( 'intval', $ids );
	}

	/** Cuenta de la app dueña del negocio, o 0. */
	public static function dueno( int $negocio ): int {
		return (int) get_post_meta( $negocio, 'user_id', true );
	}

	public static function registrar(): void {
		register_post_type(
			self::TIPO,
			array(
				'labels'        => array(
					'name'          => 'Negocios',
					'singular_name' => 'Negocio',
					'add_new_item'  => 'Añadir negocio',
					'edit_item'     => 'Editar negocio',
				),
				// Sin página pública ni ruta /wp/v2: la app solo entra por /zonanautico/v1/business.
				'public'        => false,
				'show_ui'       => true,
				'show_in_rest'  => false,
				'menu_icon'     => 'dashicons-store',
				'menu_position' => 25,
				'supports'      => array( 'title', 'editor' ),
				// Permisos propios (no los de las entradas normales): solo quien tenga edit_zn_negocios y compañía (R3).
				'capability_type' => array( 'zn_negocio', 'zn_negocios' ),
				'map_meta_cap'    => true,
			)
		);

		foreach ( self::CAMPOS as $campo => $tipo ) {
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
}

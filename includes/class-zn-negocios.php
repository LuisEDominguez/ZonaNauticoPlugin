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
	);

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
					'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
				)
			);
		}
	}
}

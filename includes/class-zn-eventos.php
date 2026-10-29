<?php
/**
 * Eventos, promociones y noticias (Novedades de la app) como entradas personalizadas (tipo `evento`):
 * - nombre → título, descripción → contenido;
 * - el resto → campos personalizados con el nombre de la columna de la tabla `events` del Laravel.
 * Las fechas se guardan como "Y-m-d H:i:s" en hora de Madrid, igual que en el Laravel.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Eventos {

	const TIPO = 'evento';

	// Tabla event_categories del Laravel (3 filas). El nombre va en singular, como lo da la API.
	const CATEGORIAS = array(
		1 => 'Evento',
		2 => 'Promoción',
		3 => 'Noticia',
	);

	const CAMPOS = array(
		'event_category_id' => 'integer',
		'business_id'       => 'integer',
		'latitude'          => 'number',
		'longitude'         => 'number',
		'start_date'        => 'string',
		'end_date'          => 'string',
		'photo_0'           => 'string',
		'photo_1'           => 'string',
		'photo_2'           => 'string',
		'video_0'           => 'string',
		'video_1'           => 'string',
		'video_2'           => 'string',
	);

	public static function registrar(): void {
		register_post_type(
			self::TIPO,
			array(
				'labels'        => array(
					'name'          => 'Novedades',
					'singular_name' => 'Novedad',
					'add_new'       => 'Añadir novedad',
					'add_new_item'  => 'Añadir novedad',
					'edit_item'     => 'Editar novedad',
					'all_items'     => 'Todas las novedades',
				),
				// Sin página pública ni ruta /wp/v2: la app solo entra por /zonanautico/v1/event.
				'public'        => false,
				'show_ui'       => true,
				'show_in_rest'  => false,
				'menu_icon'     => 'dashicons-megaphone',
				'menu_position' => 26,
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

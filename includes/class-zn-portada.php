<?php
/**
 * Portada de la app (bloque 2, parte B), con los nombres del traspaso (el Laravel aún no lo tiene):
 * - Novedades ZN (tabla `news`): tipo `zn_noticia`. title → título, description → contenido (HTML sencillo:
 *   saltos, párrafos, enlaces e imágenes; nada más), date → fecha de la entrada, image y url → campos.
 *   GET /news (paginado como el resto, la más reciente primero) y GET /news/{id}.
 * - Slides (tabla `home_slides`): tipo `zn_slide`. name → título, image y link → campos, sort_order → «Orden»
 *   de la entrada. GET /home-slides: todas las publicadas con foto, por sort_order, sin paginar (son pocas).
 * - Categorías de negocio (business_categories): fijas en ZN_Negocios::CATEGORIAS, en el orden de Adalo.
 *   GET /business-category → {data: [{_id, name, sort_order}]}; en /business, ?includesCategory=1 → category {_id, name}.
 * Slides y Novedades ZN solo las edita la administración en wp-admin (permisos propios, R3; ni la asociación ni
 * la federación los tienen). Las fechas salen en hora de Madrid (R5).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Portada {

	const NOTICIA = 'zn_noticia';
	const SLIDE   = 'zn_slide';

	// Etiquetas que se conservan en la descripción de una Novedad ZN (lo que trae Facebook).
	const HTML = array(
		'br'     => array(),
		'p'      => array(),
		'strong' => array(),
		'em'     => array(),
		'a'      => array( 'href' => true ),
		'img'    => array( 'src' => true, 'alt' => true ),
	);

	public static function iniciar(): void {
		add_action( 'init', array( __CLASS__, 'registrar' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'registrar_rutas' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . self::NOTICIA, array( __CLASS__, 'cajas_noticia' ) );
			add_action( 'add_meta_boxes_' . self::SLIDE, array( __CLASS__, 'cajas_slide' ) );
			add_action( 'save_post_' . self::NOTICIA, array( __CLASS__, 'guardar' ) );
			add_action( 'save_post_' . self::SLIDE, array( __CLASS__, 'guardar' ) );
		}
	}

	public static function registrar(): void {
		$comun = array(
			// Sin página pública ni ruta /wp/v2: la app solo entra por /zonanautico/v1.
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => false,
			'map_meta_cap' => true,
		);
		register_post_type(
			self::NOTICIA,
			$comun + array(
				'labels'          => array(
					'name'          => 'Novedades ZN',
					'singular_name' => 'Novedad ZN',
					'add_new_item'  => 'Añadir novedad ZN',
					'edit_item'     => 'Editar novedad ZN',
				),
				'menu_icon'       => 'dashicons-format-aside',
				'menu_position'   => 28,
				'supports'        => array( 'title', 'editor' ),
				'capability_type' => array( 'zn_noticia', 'zn_noticias' ),
			)
		);
		register_post_type(
			self::SLIDE,
			$comun + array(
				'labels'          => array(
					'name'          => 'Slides de portada',
					'singular_name' => 'Slide',
					'add_new_item'  => 'Añadir slide',
					'edit_item'     => 'Editar slide',
				),
				'menu_icon'       => 'dashicons-format-gallery',
				'menu_position'   => 29,
				// page-attributes: la casilla «Orden» es el sort_order.
				'supports'        => array( 'title', 'page-attributes' ),
				'capability_type' => array( 'zn_slide', 'zn_slides' ),
			)
		);
		foreach ( array( self::NOTICIA => array( 'image', 'url' ), self::SLIDE => array( 'image', 'link' ) ) as $tipo => $campos ) {
			foreach ( $campos as $campo ) {
				register_post_meta(
					$tipo,
					$campo,
					array(
						'type'          => 'string',
						'single'        => true,
						'show_in_rest'  => false,
						'auth_callback' => static fn( $permitido, $clave, $id ) => current_user_can( 'edit_post', (int) $id ),
					)
				);
			}
		}
	}

	public static function registrar_rutas(): void {
		register_rest_route(
			ZN_NAMESPACE,
			'/news',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'listar_noticias' ),
				'permission_callback' => '__return_true',
				'args'                => ZN_API::argumentos_paginacion(),
			)
		);
		register_rest_route(
			ZN_NAMESPACE,
			'/news/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ficha_noticia' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			ZN_NAMESPACE,
			'/home-slides',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'slides' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			ZN_NAMESPACE,
			'/business-category',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'categorias' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function listar_noticias( WP_REST_Request $peticion ): WP_REST_Response {
		return ZN_API::listado(
			$peticion,
			array(
				'post_type' => self::NOTICIA,
				'orderby'   => array( 'date' => 'DESC', 'ID' => 'DESC' ),
			),
			'news',
			array( __CLASS__, 'formatear_noticia' )
		);
	}

	public static function ficha_noticia( WP_REST_Request $peticion ): WP_REST_Response {
		$entrada = ZN_API::entrada( (int) $peticion['id'], self::NOTICIA );
		return $entrada ? new WP_REST_Response( array( 'data' => self::formatear_noticia( $entrada ) ) ) : ZN_API::no_encontrado();
	}

	public static function formatear_noticia( WP_Post $entrada ): array {
		$texto = trim( wp_kses( (string) $entrada->post_content, self::HTML ) );
		return array(
			'_id'         => (string) $entrada->ID,
			'title'       => html_entity_decode( get_the_title( $entrada ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'description' => '' === $texto ? null : $texto,
			'image'       => ZN_API::campo( $entrada->ID, 'image' ),
			'url'         => ZN_API::campo( $entrada->ID, 'url' ),
			'date'        => ZN_API::hora_madrid( $entrada->post_date_gmt ),
		);
	}

	public static function slides(): WP_REST_Response {
		$entradas = get_posts(
			array(
				'post_type'      => self::SLIDE,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => -1,
				'orderby'        => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
				'meta_query'     => array( array( 'key' => 'image', 'value' => '', 'compare' => '!=' ) ),
			)
		);
		return new WP_REST_Response(
			array(
				'data' => array_map(
					static fn( WP_Post $s ) => array(
						'_id'        => (string) $s->ID,
						'name'       => html_entity_decode( get_the_title( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
						'image'      => ZN_API::campo( $s->ID, 'image' ),
						'link'       => ZN_API::campo( $s->ID, 'link' ),
						'sort_order' => (int) $s->menu_order,
					),
					$entradas
				),
			)
		);
	}

	public static function categorias(): WP_REST_Response {
		$datos = array();
		$orden = 0;
		foreach ( ZN_Negocios::CATEGORIAS as $id => $nombre ) {
			$datos[] = array( '_id' => (string) $id, 'name' => $nombre, 'sort_order' => ++$orden );
		}
		return new WP_REST_Response( array( 'data' => $datos ) );
	}

	/** category {_id, name} de un negocio (includesCategory); null si no tiene o no existe. */
	public static function categoria_de( int $negocio ): ?array {
		$id = (int) get_post_meta( $negocio, 'business_category_id', true );
		return isset( ZN_Negocios::CATEGORIAS[ $id ] ) ? array( '_id' => (string) $id, 'name' => ZN_Negocios::CATEGORIAS[ $id ] ) : null;
	}

	/** "Y-m-d H:i:s" en hora de Madrid → la misma hora en UTC (para post_date_gmt). */
	public static function utc_desde_madrid( string $madrid ): string {
		return ( new DateTimeImmutable( $madrid, new DateTimeZone( 'Europe/Madrid' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Carga inicial de Novedades ZN desde el JSON de la app (src/data/provisional/noticias.json: las 60 más
	 * recientes, ya sin ganadores). Cada una se reconoce por su id de origen (zn_origen): repetir no duplica.
	 * Devuelve los ids creados.
	 */
	public static function importar_noticias( array $crudas ): array {
		$hechas = array();
		foreach ( $crudas as $cruda ) {
			$origen = (string) ( $cruda['id'] ?? '' );
			if ( '' === $origen || get_posts( array( 'post_type' => self::NOTICIA, 'post_status' => 'any', 'meta_key' => 'zn_origen', 'meta_value' => $origen, 'fields' => 'ids', 'numberposts' => 1 ) ) ) {
				continue;
			}
			$fecha = ! empty( $cruda['fecha'] ) ? ( new DateTimeImmutable( (string) $cruda['fecha'] ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s' );
			$id    = wp_insert_post(
				array(
					'post_type'     => self::NOTICIA,
					'post_status'   => 'publish',
					'post_title'    => trim( (string) ( $cruda['titulo'] ?? '' ) ) ?: 'Novedad',
					'post_content'  => wp_kses( (string) ( $cruda['html'] ?? '' ), self::HTML ),
					'post_date_gmt' => $fecha,
					'post_author'   => 0,
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, 'zn_origen', $origen );
			foreach ( array( 'imagen' => 'image', 'url' => 'url' ) as $de => $a ) {
				$url = esc_url_raw( (string) ( $cruda[ $de ] ?? '' ), array( 'http', 'https' ) );
				if ( '' !== $url ) {
					update_post_meta( $id, $a, $url );
				}
			}
			$hechas[] = (int) $id;
		}
		return $hechas;
	}

	// Nombres de las slides de la app → título en wp-admin.
	const NOMBRES_SLIDES = array(
		'restauracion' => 'Restauración',
		'alimentacion' => 'Alimentación',
		'comercio'     => 'Comercio',
		'servicios'    => 'Servicios',
		'alojamiento'  => 'Alojamiento',
		'portada'      => 'Portada',
	);

	/**
	 * Carga inicial de las slides desde slides.json de la app y sus fotos ({nombre}.jpg en $carpeta_fotos), que se
	 * suben a la biblioteca de medios. sort_order = el orden del JSON. Repetir no duplica (zn_origen). Devuelve los ids.
	 */
	public static function importar_slides( array $crudas, string $carpeta_fotos ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$hechas = array();
		foreach ( array_values( $crudas ) as $posicion => $cruda ) {
			$nombre = sanitize_key( (string) ( $cruda['nombre'] ?? '' ) );
			$origen = 'slide-' . $nombre;
			$foto   = "$carpeta_fotos/$nombre.jpg";
			if ( '' === $nombre || ! is_readable( $foto ) || get_posts( array( 'post_type' => self::SLIDE, 'post_status' => 'any', 'meta_key' => 'zn_origen', 'meta_value' => $origen, 'fields' => 'ids', 'numberposts' => 1 ) ) ) {
				continue;
			}
			// media_handle_sideload mueve el archivo: se le da una copia.
			$copia = wp_tempnam( "$nombre.jpg" );
			copy( $foto, $copia );
			$adjunto = media_handle_sideload( array( 'name' => "slide-$nombre.jpg", 'tmp_name' => $copia ), 0, self::NOMBRES_SLIDES[ $nombre ] ?? $nombre );
			if ( is_wp_error( $adjunto ) ) {
				@unlink( $copia ); // phpcs:ignore
				continue;
			}
			$id = wp_insert_post( array( 'post_type' => self::SLIDE, 'post_status' => 'publish', 'post_title' => self::NOMBRES_SLIDES[ $nombre ] ?? $nombre, 'menu_order' => $posicion + 1, 'post_author' => 0 ), true );
			if ( is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, 'zn_origen', $origen );
			update_post_meta( $id, 'image', (string) wp_get_attachment_url( $adjunto ) );
			$hechas[] = (int) $id;
		}
		return $hechas;
	}

	// --- wp-admin ---

	public static function cajas_noticia(): void {
		ZN_Admin_Campos::usar_fotos( self::NOTICIA );
		add_meta_box( 'zn-datos-noticia', 'Datos de la novedad ZN', array( __CLASS__, 'caja' ), self::NOTICIA, 'normal', 'high' );
	}

	public static function cajas_slide(): void {
		ZN_Admin_Campos::usar_fotos( self::SLIDE );
		add_meta_box( 'zn-datos-slide', 'Foto y enlace', array( __CLASS__, 'caja' ), self::SLIDE, 'normal', 'high' );
	}

	public static function caja( WP_Post $entrada ): void {
		ZN_Admin_Campos::sello();
		$imagen = ZN_Admin_Campos::valor( $entrada->ID, 'image' );
		$enlace = self::SLIDE === $entrada->post_type ? 'link' : 'url';
		?>
		<div class="zn-foto" style="margin-bottom:16px">
			<img src="<?php echo esc_url( $imagen ); ?>" alt="" style="max-width:320px;max-height:180px;object-fit:cover;background:#f0f0f1;<?php echo $imagen ? '' : 'display:none;'; ?>">
			<input type="hidden" name="zn[image]" value="<?php echo esc_attr( $imagen ); ?>">
			<p>
				<button type="button" class="button zn-elegir-foto">Elegir foto</button>
				<button type="button" class="button-link zn-quitar-foto" <?php echo $imagen ? '' : 'style="display:none"'; ?>>Quitar</button>
			</p>
		</div>
		<p>
			<label><?php echo self::SLIDE === $entrada->post_type ? 'Enlace (opcional)' : 'Enlace a la publicación original (opcional)'; ?>
				<input type="url" class="large-text" name="zn[<?php echo esc_attr( $enlace ); ?>]" placeholder="https://" value="<?php echo esc_attr( ZN_Admin_Campos::valor( $entrada->ID, $enlace ) ); ?>">
			</label>
		</p>
		<?php if ( self::SLIDE === $entrada->post_type ) : ?>
			<p class="description">La app enseña al azar una de las slides publicadas que tengan foto. El orden se cambia en «Atributos › Orden».</p>
		<?php else : ?>
			<p class="description">El título y el texto van arriba. Del texto, la app solo enseña saltos de línea, negritas, enlaces e imágenes.</p>
		<?php endif; ?>
		<?php
	}

	public static function guardar( int $id ): void {
		$datos = ZN_Admin_Campos::datos_enviados( $id );
		if ( null === $datos ) {
			return;
		}
		$campos = self::SLIDE === get_post_type( $id ) ? array( 'image', 'link' ) : array( 'image', 'url' );
		foreach ( $campos as $campo ) {
			$url = esc_url_raw( ZN_Admin_Campos::texto( $datos, $campo ), array( 'http', 'https' ) );
			if ( '' === $url ) {
				delete_post_meta( $id, $campo );
			} else {
				update_post_meta( $id, $campo, $url );
			}
		}
	}
}

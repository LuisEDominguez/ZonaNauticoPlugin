<?php
/**
 * Participaciones en sorteos (decisiones de Luis del 04-11; draw_participations del traspaso, con sus fallos
 * arreglados). Tabla {prefijo}zn_participaciones: una por persona y sorteo (decisión a).
 *
 * Rutas, todas con el token:
 * - POST /draw-participation (multipart): draw o drawId, ticket (la foto) y adult (casilla «soy mayor de 18»,
 *   decisión 6). La cuenta es siempre la del token: el «user» que mande la app se ignora (H21). El sorteo tiene que
 *   estar abierto (entre inicio y fin). Foto JPG, PNG o WebP de hasta 8 MB; se vuelve a guardar en JPG, sin EXIF
 *   (decisión 2) y con 2000 px como mucho. Límite de escrituras (R94). 201 {data}.
 * - GET /draw-participation: las mías. Con ?draw={id}: las de ese sorteo para quien lo organiza y la administración
 *   (R43); a quien participa, solo la suya. Nada es público (en el Laravel sí, H1).
 * - GET /draw-participation/{id}/ticket: la foto, solo a quien participa, a quien organiza y a la administración.
 * - DELETE /draw-participation/{id}: quien participa mientras el sorteo está abierto; la administración siempre.
 * - PATCH /draw-participation/{id} {winner, delivered}: ganador y entrega, a mano (decisiones c y d), quien organiza
 *   o la administración. Al marcar ganador, aviso privado por correo (decisión 4; fuera de producción solo a
 *   @ejemplo.invalid, R57).
 * - GET /draw-participation/export/{drawId}?format=csv: nombre y correo (R45, decisión 5).
 * En /draw/{id}?includesWinners=1, winnerNames con el nombre de pila de quien ganó (R66).
 *
 * Fotos: en zn-privado-tickets/, junto a la carpeta de WordPress y fuera de la web (carpeta()). Sin esa carpeta no
 * se guarda ningún ticket (R131: 503 y aviso en wp-admin); no hay plan B en uploads. Nunca en la biblioteca de medios
 * ni con URL pública: solo por la ruta /ticket.
 * Borrado (tarea diaria; se quita al desactivar, R137): 30 días después de que el sorteo esté cerrado y todos sus
 * premios entregados (decisión 1, R138), o 90 días después del fin si no se entregan (decisión b, R133): se borran
 * todas las fotos y las participaciones de quien no ganó; a quien ganó se le quitan nombre, correo y foto. Las fotos
 * sin fila se barren (R136). RGPD (R65, decisión 7): borrar la cuenta o el sorteo lo borra todo salvo a quien ganó y
 * aún no tiene su premio, de quien se guardan nombre y correo hasta la entrega (como mucho, los 90 días).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Participaciones {

	const VERSION_TABLA = '1';
	const TAREA         = 'zn_limpiar_participaciones';
	const MAX_BYTES     = 8 * MB_IN_BYTES;
	const MAX_LADO      = 2000;
	// R132: tope antes de decodificar (un PNG pequeño puede decir medir 20000 × 20000 y agotar la memoria).
	const MAX_PIXELES   = 40000000;
	const DIAS_TRAS_ENTREGA = 30;
	const DIAS_SIN_ENTREGA  = 90;

	public static function iniciar(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'registrar_rutas' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'servir_archivo' ), 10, 4 );
		add_action( self::TAREA, array( __CLASS__, 'limpiar' ) );
		add_action( 'init', array( __CLASS__, 'programar' ) );
		add_action( 'delete_user', array( __CLASS__, 'al_borrar_cuenta' ) );
		add_action( 'wpmu_delete_user', array( __CLASS__, 'al_borrar_cuenta' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'al_borrar_sorteo' ) );
		add_action( 'admin_notices', array( __CLASS__, 'avisar_en_admin' ) );
		// Parte 3: foto, CSV y marcas desde la ficha del sorteo en wp-admin (la administración).
		add_action( 'admin_post_zn_participantes', array( __CLASS__, 'atender_admin' ) );
	}

	public static function tabla(): string {
		global $wpdb;
		return $wpdb->prefix . 'zn_participaciones';
	}

	/** Crea o pone al día la tabla. La llama ZN_Instalacion. */
	public static function instalar(): void {
		if ( get_option( 'zn_participaciones_tabla' ) === self::VERSION_TABLA ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::tabla() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				draw_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				ticket varchar(100) NOT NULL DEFAULT '',
				adulto_en datetime DEFAULT NULL,
				date datetime NOT NULL,
				ganador tinyint(1) NOT NULL DEFAULT 0,
				entregado_en datetime DEFAULT NULL,
				nombre varchar(255) NOT NULL DEFAULT '',
				correo varchar(100) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY sorteo_cuenta (draw_id,user_id),
				KEY user_id (user_id)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'zn_participaciones_tabla', self::VERSION_TABLA );
	}

	public static function programar(): void {
		if ( ! wp_next_scheduled( self::TAREA ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::TAREA );
		}
	}

	/**
	 * Carpeta de las fotos: junto a la de WordPress (fuera de la web) si se puede escribir ahí; si no, dentro de
	 * uploads con .htaccess que lo niega todo. Siempre con index.php vacío y .htaccess, por si acaso.
	 */
	public static function carpeta(): string {
		$carpeta = self::carpeta_pedida();
		// R134: solo una carpeta zn-privado* segura. R131: y fuera de la web; si no la hay, '' y no se guarda ningún ticket.
		if ( ! self::ruta_valida( $carpeta ) || 0 === strpos( $carpeta . '/', wp_normalize_path( ABSPATH ) ) ) {
			return '';
		}
		if ( ! is_dir( $carpeta ) ) {
			wp_mkdir_p( $carpeta );
		}
		if ( ! is_dir( $carpeta ) || ! wp_is_writable( $carpeta ) ) {
			return '';
		}
		if ( ! is_file( "$carpeta/.htaccess" ) ) {
			file_put_contents( "$carpeta/.htaccess", "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! is_file( "$carpeta/index.php" ) ) {
			file_put_contents( "$carpeta/index.php", "<?php\n// Silencio.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $carpeta;
	}

	/** Junto a la carpeta de WordPress, fuera de la web. Sin plan B dentro de uploads (R131). */
	private static function carpeta_por_defecto(): string {
		return wp_normalize_path( dirname( untrailingslashit( ABSPATH ) ) ) . '/zn-privado-tickets';
	}

	/** R131: aviso para wp-admin si no hay dónde guardar los tickets; '' si todo está bien. */
	public static function aviso_carpeta(): string {
		return '' === self::carpeta()
			? 'Zona Náutico: no se pueden guardar las fotos de los tickets de los sorteos. Hace falta la carpeta «zn-privado-tickets» junto a la de WordPress (fuera de la web) y que se pueda escribir en ella. Mientras tanto, la app no deja participar.'
			: '';
	}

	public static function avisar_en_admin(): void {
		$aviso = current_user_can( 'manage_options' ) ? self::aviso_carpeta() : '';
		if ( '' !== $aviso ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $aviso ) . '</p></div>';
		}
	}

	/** Al desactivar el plugin (R137). */
	public static function desprogramar(): void {
		wp_clear_scheduled_hook( self::TAREA );
	}

	/** La carpeta que pide el filtro zn_carpeta_tickets (por defecto, la de siempre), sin comprobarla. */
	private static function carpeta_pedida(): string {
		return wp_normalize_path( untrailingslashit( (string) apply_filters( 'zn_carpeta_tickets', self::carpeta_por_defecto() ) ) );
	}

	/**
	 * R134: ¿es una carpeta en la que se pueden guardar y borrar tickets? Se llama zn-privado*, no está vacía,
	 * no es un enlace simbólico y no es ni contiene la de WordPress, wp-content o uploads.
	 */
	public static function ruta_valida( string $ruta ): bool {
		$ruta = untrailingslashit( wp_normalize_path( trim( $ruta ) ) );
		if ( '' === $ruta || ! preg_match( '/^zn-privado[a-z0-9_-]*$/', basename( $ruta ) ) || is_link( $ruta ) ) {
			return false;
		}
		$real = is_dir( $ruta ) ? untrailingslashit( wp_normalize_path( (string) realpath( $ruta ) ) ) : $ruta;
		if ( '' === $real || $real !== $ruta ) {
			// Una carpeta que existe y no es lo que dice ser (enlace o junction por el camino): no.
			return false;
		}
		foreach ( array( ABSPATH, WP_CONTENT_DIR, wp_get_upload_dir()['basedir'] ) as $protegida ) {
			$protegida = untrailingslashit( wp_normalize_path( (string) $protegida ) );
			if ( $real === $protegida || 0 === strpos( $protegida . '/', $real . '/' ) ) {
				return false;
			}
		}
		return true;
	}

	/** Ruta del archivo de la foto de una fila; '' si no tiene (o no hay carpeta). */
	public static function ruta_ticket( ?array $fila ): string {
		$nombre  = (string) ( $fila['ticket'] ?? '' );
		$carpeta = preg_match( '/^[a-z0-9]{32}\.jpg$/', $nombre ) ? self::carpeta() : '';
		return '' === $carpeta ? '' : "$carpeta/$nombre";
	}

	public static function registrar_rutas(): void {
		register_rest_route(
			ZN_NAMESPACE,
			'/draw-participation',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'listar' ),
					'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
					'args'                => array( 'draw' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'crear' ),
					'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
				),
			)
		);
		register_rest_route(
			ZN_NAMESPACE,
			'/draw-participation/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'borrar' ),
					'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'marcar' ),
					'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
				),
			)
		);
		register_rest_route(
			ZN_NAMESPACE,
			'/draw-participation/(?P<id>\d+)/ticket',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ticket' ),
				'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
			)
		);
		register_rest_route(
			ZN_NAMESPACE,
			'/draw-participation/export/(?P<drawId>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'exportar' ),
				'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
				'args'                => array( 'format' => array( 'type' => 'string', 'default' => 'csv' ) ),
			)
		);
	}

	// --- Lectura de filas ---

	private static function fila( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $fila ?: null;
	}

	private static function sorteo( int $id ): ?WP_Post {
		$sorteo = get_post( $id );
		return $sorteo && ZN_Sorteos::TIPO === $sorteo->post_type ? $sorteo : null;
	}

	/** ¿Organiza este sorteo la cuenta actual, o es la administración de la app? (R43, como ZN_Roles::puede). */
	private static function organiza( ?WP_Post $sorteo ): bool {
		return $sorteo && ZN_Roles::puede( 'draw_participation:read', $sorteo );
	}

	private static function abierto( WP_Post $sorteo ): bool {
		$inicio = ZN_API::campo( $sorteo->ID, 'start_date' );
		$fin    = ZN_API::campo( $sorteo->ID, 'end_date' );
		$ahora  = ZN_Sorteos::ahora();
		return 'publish' === $sorteo->post_status && $inicio && $fin && $inicio <= $ahora && $ahora <= $fin;
	}

	public static function formatear( array $fila ): array {
		$sorteo = self::sorteo( (int) $fila['draw_id'] );
		return array(
			'_id'       => (string) $fila['id'],
			'date'      => ZN_API::hora_madrid( (string) $fila['date'] ),
			'user'      => ZN_API::usuario_publico( (int) $fila['user_id'] ),
			'draw'      => $sorteo ? array( '_id' => (string) $sorteo->ID, 'title' => get_the_title( $sorteo ) ) : null,
			// Nunca la dirección del archivo: la ruta protegida que lo sirve con permiso.
			'ticket'    => '' === (string) $fila['ticket'] ? null : rest_url( ZN_NAMESPACE . '/draw-participation/' . (int) $fila['id'] . '/ticket' ),
			'winner'    => '1' === (string) $fila['ganador'],
			'delivered' => null !== $fila['entregado_en'],
		);
	}

	// --- Rutas ---

	public static function listar( WP_REST_Request $peticion ): WP_REST_Response {
		global $wpdb;
		$yo = get_current_user_id();
		if ( empty( $peticion['draw'] ) ) {
			$filas = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE user_id = %d ORDER BY id DESC', $yo ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$sorteo = self::sorteo( (int) $peticion['draw'] );
			if ( self::organiza( $sorteo ) ) {
				$filas = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE draw_id = %d ORDER BY id ASC', $sorteo->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			} else {
				$filas = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE draw_id = %d AND user_id = %d', (int) $peticion['draw'], $yo ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( ! $filas && $sorteo && ( ZN_Roles::puede( 'draw_participation:export' ) || ZN_Roles::puede( 'draw_participation:export_own' ) ) ) {
					// Quien podría organizar pero no organiza este: no es suyo.
					return ZN_Errores::sin_permiso();
				}
			}
		}
		$respuesta = new WP_REST_Response( array( 'data' => array_map( array( __CLASS__, 'formatear' ), $filas ) ) );
		$respuesta->header( 'Cache-Control', 'no-store, private' );
		return $respuesta;
	}

	public static function crear( WP_REST_Request $peticion ): WP_REST_Response {
		$espera = ZN_API::limite_de_escritura();
		if ( null !== $espera ) {
			return ZN_Limite::demasiados( $espera );
		}
		// R131: sin carpeta privada fuera de la web no se guarda ningún ticket.
		if ( '' === self::carpeta() ) {
			return new WP_REST_Response( array( 'message' => 'Tickets cannot be saved right now: the private folder for tickets is missing.' ), 503 );
		}
		$cuerpo  = $peticion->get_body_params() ?: ZN_API::cuerpo( $peticion );
		$valor   = $cuerpo['draw'] ?? $cuerpo['drawId'] ?? null;
		$errores = array();
		if ( null === $valor || '' === $valor ) {
			$errores['draw'] = ZN_Esquema::mensaje( 'required', 'draw' );
		} elseif ( ! ( is_int( $valor ) || ( is_string( $valor ) && ctype_digit( $valor ) ) ) ) {
			$errores['draw'] = ZN_Esquema::mensaje( 'integer', 'draw' );
		}
		if ( ! in_array( $cuerpo['adult'] ?? null, array( true, 1, '1', 'true', 'on' ), true ) ) {
			$errores['adult'] = 'The adult field must be accepted.';
		}
		$archivos = $peticion->get_file_params();
		$foto     = $archivos['ticket'] ?? $archivos['attachment'] ?? null;
		if ( ! is_array( $foto ) || UPLOAD_ERR_OK !== (int) ( $foto['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $foto['tmp_name'] ) || ! is_file( $foto['tmp_name'] ) ) {
			$errores['ticket'] = ZN_Esquema::mensaje( 'required', 'ticket' );
		}
		if ( $errores ) {
			return ZN_Errores::no_valido( $errores );
		}
		// R132: el sorteo, el permiso y si ya participa se miran antes de tocar la imagen.
		$sorteo = self::sorteo( (int) $valor );
		if ( ! $sorteo || ! ZN_API::entrada( $sorteo->ID, ZN_Sorteos::TIPO ) ) {
			return ZN_API::no_encontrado();
		}
		if ( ! self::abierto( $sorteo ) ) {
			return ZN_Errores::no_valido( array( 'draw' => 'The draw is not open.' ) );
		}
		$yo = get_current_user_id();
		if ( ! ZN_Roles::puede_crear( 'draw_participation:save', $yo ) ) {
			return ZN_Errores::sin_permiso();
		}
		// R140: quien organiza el sorteo no participa en él.
		if ( (int) ZN_API::campo( $sorteo->ID, 'user_id' ) === $yo ) {
			return ZN_Errores::no_valido( array( 'draw' => 'You cannot take part in a draw you organise.' ) );
		}
		global $wpdb;
		$existe = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::tabla() . ' WHERE draw_id = %d AND user_id = %d', $sorteo->ID, $yo ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$repetida = ZN_Errores::no_valido( array( 'draw' => 'You are already taking part in this draw.' ) );
		if ( $existe ) {
			return $repetida;
		}
		// Luego la imagen: primero tamaño, tipo y píxeles con la cabecera (sin decodificar); solo entonces se abre.
		$fallo = self::comprobar_imagen( $foto['tmp_name'] );
		if ( null !== $fallo ) {
			return ZN_Errores::no_valido( array( 'ticket' => $fallo ) );
		}
		$imagen = self::cargar_imagen( $foto['tmp_name'] );
		if ( ! $imagen ) {
			return ZN_Errores::no_valido( array( 'ticket' => 'The ticket field must be an image (jpg, png or webp).' ) );
		}
		$nombre = self::guardar_imagen( $imagen );
		if ( '' === $nombre ) {
			return new WP_REST_Response( array( 'message' => 'Server Error' ), 500 );
		}
		$ahora = current_time( 'mysql', true );
		// La clave única cuenta + sorteo también para dos envíos a la vez (un doble toque).
		$hecho = $wpdb->insert( self::tabla(), array( 'draw_id' => $sorteo->ID, 'user_id' => $yo, 'ticket' => $nombre, 'adulto_en' => $ahora, 'date' => $ahora ) );
		if ( ! $hecho ) {
			@unlink( self::carpeta() . "/$nombre" ); // phpcs:ignore
			return $repetida;
		}
		return new WP_REST_Response( array( 'data' => self::formatear( self::fila( (int) $wpdb->insert_id ) ) ), 201 );
	}

	public static function ticket( WP_REST_Request $peticion ): WP_REST_Response {
		$fila = self::fila( (int) $peticion['id'] );
		if ( ! $fila || ( (int) $fila['user_id'] !== get_current_user_id() && ! self::organiza( self::sorteo( (int) $fila['draw_id'] ) ) ) ) {
			return ZN_Errores::sin_permiso();
		}
		$ruta = self::ruta_ticket( $fila );
		if ( '' === $ruta || ! is_file( $ruta ) ) {
			return ZN_API::no_encontrado();
		}
		$respuesta = new WP_REST_Response( array( '__zn_archivo' => $ruta ) );
		$respuesta->header( 'Content-Type', 'image/jpeg' );
		$respuesta->header( 'Cache-Control', 'no-store, private' );
		$respuesta->header( 'X-Content-Type-Options', 'nosniff' );
		return $respuesta;
	}

	public static function borrar( WP_REST_Request $peticion ): WP_REST_Response {
		$fila   = self::fila( (int) $peticion['id'] );
		$sorteo = $fila ? self::sorteo( (int) $fila['draw_id'] ) : null;
		$admin  = 'administrator' === ZN_Roles::rol_app( wp_get_current_user() );
		$suya   = $fila && (int) $fila['user_id'] === get_current_user_id() && ZN_Roles::puede( 'draw_participation:delete_own' );
		if ( ! $fila || ! ( $admin || ( $suya && $sorteo && self::abierto( $sorteo ) ) ) ) {
			return ZN_Errores::sin_permiso();
		}
		self::borrar_fila( $fila );
		return new WP_REST_Response( array( 'status' => 'success' ) );
	}

	public static function marcar( WP_REST_Request $peticion ): WP_REST_Response {
		$fila = self::fila( (int) $peticion['id'] );
		if ( ! $fila || ! self::organiza( self::sorteo( (int) $fila['draw_id'] ) ) ) {
			return ZN_Errores::sin_permiso();
		}
		$cuerpo = ZN_API::cuerpo( $peticion );
		$entrada = array();
		foreach ( array( 'winner' => 'ganador', 'delivered' => 'entregado' ) as $api => $clave ) {
			if ( array_key_exists( $api, $cuerpo ) ) {
				$entrada[ $clave ] = in_array( $cuerpo[ $api ], array( true, 1, '1', 'true' ), true );
			}
		}
		$hecho = self::marcar_fila( $fila, $entrada );
		if ( 429 === $hecho['estado'] ) {
			return ZN_Limite::demasiados( (int) $hecho['espera'] );
		}
		if ( 422 === $hecho['estado'] ) {
			return ZN_Errores::no_valido( array( 'delivered' => 'Only a winner can be marked as delivered.' ) );
		}
		return new WP_REST_Response( array( 'data' => self::formatear( self::fila( (int) $fila['id'] ) ) ) );
	}

	/**
	 * Ganador y entrega de una fila (la API, el panel y wp-admin; quien llama ya ha mirado el permiso).
	 * $entrada: ganador y/o entregado (bool). R139: cuenta en el límite de escrituras. Al marcar ganador, aviso
	 * privado (decisión 4). Devuelve estado (200, 422 si se entrega a quien no ha ganado, 429) y espera.
	 */
	public static function marcar_fila( array $fila, array $entrada ): array {
		$espera = ZN_API::limite_de_escritura();
		if ( null !== $espera ) {
			return array( 'estado' => 429, 'espera' => $espera );
		}
		$cambios = array();
		if ( array_key_exists( 'ganador', $entrada ) ) {
			$cambios['ganador'] = $entrada['ganador'] ? 1 : 0;
			if ( ! $entrada['ganador'] ) {
				$cambios += array( 'entregado_en' => null, 'nombre' => '', 'correo' => '' );
			} elseif ( '1' !== (string) $fila['ganador'] ) {
				$cuenta  = get_userdata( (int) $fila['user_id'] );
				$cambios += array( 'nombre' => $cuenta ? (string) ZN_API::nombre_publico( $cuenta ) : '', 'correo' => $cuenta ? $cuenta->user_email : '' );
			}
		}
		if ( array_key_exists( 'entregado', $entrada ) ) {
			if ( $entrada['entregado'] && 1 !== ( $cambios['ganador'] ?? (int) $fila['ganador'] ) ) {
				return array( 'estado' => 422, 'espera' => 0 );
			}
			$cambios['entregado_en'] = $entrada['entregado'] ? current_time( 'mysql', true ) : null;
			// Entregado a una cuenta que ya no existe: ya no hace falta guardar sus datos (decisión 7).
			if ( $entrada['entregado'] && ! get_userdata( (int) $fila['user_id'] ) ) {
				$cambios += array( 'nombre' => '', 'correo' => '' );
			}
		}
		global $wpdb;
		if ( $cambios ) {
			$wpdb->update( self::tabla(), $cambios, array( 'id' => (int) $fila['id'] ) );
		}
		if ( 1 === ( $cambios['ganador'] ?? null ) && '1' !== (string) $fila['ganador'] ) {
			self::avisar_ganador( $fila );
		}
		return array( 'estado' => 200, 'espera' => 0 );
	}

	public static function exportar( WP_REST_Request $peticion ): WP_REST_Response {
		$sorteo = self::sorteo( (int) $peticion['drawId'] );
		if ( ! $sorteo || ! ( ZN_Roles::puede( 'draw_participation:export', $sorteo ) ) ) {
			return ZN_Errores::sin_permiso();
		}
		if ( 'csv' !== $peticion['format'] ) {
			return ZN_Errores::no_valido( array( 'format' => 'The selected format is invalid.' ) );
		}
		$respuesta = new WP_REST_Response( array( '__zn_texto' => self::csv( $sorteo ) ) );
		$respuesta->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$respuesta->header( 'Content-Disposition', 'attachment; filename="participantes-' . $sorteo->ID . '.csv"' );
		$respuesta->header( 'Cache-Control', 'no-store, private' );
		return $respuesta;
	}

	/** CSV de un sorteo (R45, decisión 5): nombre y correo, UTF-8 con BOM y «;». Quien llama mira el permiso. */
	public static function csv( WP_Post $sorteo ): string {
		$lineas = array( 'Nombre;Correo' );
		foreach ( self::de_sorteo( $sorteo->ID ) as $fila ) {
			$cuenta   = get_userdata( (int) $fila['user_id'] );
			$nombre   = $cuenta ? (string) ZN_API::nombre_publico( $cuenta ) : (string) $fila['nombre'];
			$correo   = $cuenta ? $cuenta->user_email : (string) $fila['correo'];
			$lineas[] = self::celda( $nombre ) . ';' . self::celda( $correo );
		}
		return "\xEF\xBB\xBF" . implode( "\r\n", $lineas ) . "\r\n";
	}

	private static function de_sorteo( int $sorteo ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE draw_id = %d ORDER BY id ASC', $sorteo ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// --- Gestión desde wp-admin y «Mi zona» (parte 3) ---

	/** ¿Puede gestionar sus participantes? Quien organiza (R43, sin herencia) o la administración de WordPress. */
	public static function puede_gestionar( WP_Post $sorteo, int $usuario ): bool {
		return ZN_Sorteos::TIPO === $sorteo->post_type
			&& ( ZN_Roles::puede( 'draw_participation:read', $sorteo, $usuario ) || user_can( $usuario, 'edit_others_zn_sorteos' ) );
	}

	/** Ruta de la foto de una participación para esa cuenta (quien participa o quien gestiona); '' si no. */
	public static function ticket_para( int $id, int $usuario ): string {
		$fila   = self::fila( $id );
		$sorteo = $fila ? self::sorteo( (int) $fila['draw_id'] ) : null;
		if ( ! $fila || ! ( (int) $fila['user_id'] === $usuario || ( $sorteo && self::puede_gestionar( $sorteo, $usuario ) ) ) ) {
			return '';
		}
		$ruta = self::ruta_ticket( $fila );
		return '' !== $ruta && is_file( $ruta ) ? $ruta : '';
	}

	/** R148: la foto, solo si la participación es del sorteo de la dirección (igual que las marcas); '' si no. */
	public static function ticket_de_sorteo( WP_Post $sorteo, int $id, int $usuario ): string {
		$fila = self::fila( $id );
		return $fila && (int) $fila['draw_id'] === $sorteo->ID ? self::ticket_para( $id, $usuario ) : '';
	}

	/**
	 * R147: acción del sello de lo que se pide, una por acción y por fila (la miniatura no vale para marcar ni para
	 * otra fila): zn_part_{sorteo}_ticket_{fila}, zn_part_{sorteo}_{hacer}_{fila} o zn_part_{sorteo}_csv. Mismo orden
	 * que atender(). '' si no se pide nada válido.
	 */
	private static function accion_sello( WP_Post $sorteo, array $get ): string {
		$prefijo = 'zn_part_' . $sorteo->ID . '_';
		if ( ! empty( $get['ticket'] ) ) {
			return $prefijo . 'ticket_' . absint( $get['ticket'] );
		}
		if ( ! empty( $get['csv'] ) ) {
			return $prefijo . 'csv';
		}
		$hacer = sanitize_key( (string) ( $get['hacer'] ?? '' ) );
		$fila  = absint( $get['fila'] ?? 0 );
		return $fila && in_array( $hacer, array( 'ganador', 'no_ganador', 'entregado', 'no_entregado' ), true ) ? $prefijo . $hacer . '_' . $fila : '';
	}

	/** ¿Trae $get el sello de exactamente lo que pide? */
	public static function sello_valido( WP_Post $sorteo, array $get ): bool {
		$accion = self::accion_sello( $sorteo, $get );
		return '' !== $accion && isset( $get['_wpnonce'] ) && false !== wp_verify_nonce( sanitize_key( (string) $get['_wpnonce'] ), $accion );
	}

	/** Las tres direcciones de html_lista() sobre $base, cada una con su propio sello (R147). */
	public static function urls_con_sello( WP_Post $sorteo, string $base ): array {
		$con = static fn( array $mas ) => wp_nonce_url( add_query_arg( $mas, $base ), self::accion_sello( $sorteo, $mas ) );
		return array(
			'ticket' => static fn( int $id ) => $con( array( 'ticket' => $id ) ),
			'accion' => static fn( int $id, string $hacer ) => $con( array( 'fila' => $id, 'hacer' => $hacer ) ),
			'csv'    => $con( array( 'csv' => 1 ) ),
		);
	}

	/**
	 * Marca desde el panel o wp-admin. $hacer: ganador, no_ganador, entregado o no_entregado. La participación tiene
	 * que ser de ese sorteo. Devuelve '' si se ha hecho; si no, el aviso para enseñar.
	 */
	public static function marcar_desde_gestion( WP_Post $sorteo, int $id, string $hacer, int $usuario ): string {
		$fila = self::fila( $id );
		if ( ! self::puede_gestionar( $sorteo, $usuario ) || ! $fila || (int) $fila['draw_id'] !== $sorteo->ID ) {
			return 'No puedes cambiar esta participación.';
		}
		$entrada = array(
			'ganador'      => array( 'ganador' => true ),
			'no_ganador'   => array( 'ganador' => false ),
			'entregado'    => array( 'entregado' => true ),
			'no_entregado' => array( 'entregado' => false ),
		)[ $hacer ] ?? null;
		if ( null === $entrada ) {
			return 'No se ha hecho nada.';
		}
		$hecho = self::marcar_fila( $fila, $entrada );
		if ( 429 === $hecho['estado'] ) {
			return 'Demasiados cambios seguidos: espera unos minutos y vuelve a probar.';
		}
		return 422 === $hecho['estado'] ? 'Solo se puede marcar la entrega de quien ha ganado.' : '';
	}

	/**
	 * Lista de participantes de un sorteo (HTML escapado) para quien gestiona: nombre de pila, fecha, miniatura del
	 * ticket (por $urls['ticket'], nunca la dirección del archivo), estado, enlaces con sello para marcar y el CSV.
	 * $urls: ticket( id ), accion( id, hacer ) y csv (dirección).
	 */
	public static function html_lista( WP_Post $sorteo, array $urls ): string {
		if ( ! self::puede_gestionar( $sorteo, get_current_user_id() ) ) {
			return '<p>No puedes ver los participantes de este sorteo.</p>';
		}
		$filas = self::de_sorteo( $sorteo->ID );
		ob_start();
		?>
		<p><?php echo esc_html( sprintf( '%d participantes.', count( $filas ) ) ); ?> <?php if ( $filas ) : ?><a class="button" href="<?php echo esc_url( $urls['csv'] ); ?>">Descargar CSV (nombre y correo)</a><?php endif; ?></p>
		<?php if ( $filas ) : ?>
			<table class="widefat striped">
				<thead><tr><th scope="col">Nombre</th><th scope="col">Fecha</th><th scope="col">Ticket</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
				<tbody>
					<?php foreach ( $filas as $fila ) : ?>
						<?php
						$id        = (int) $fila['id'];
						$ganador   = '1' === (string) $fila['ganador'];
						$entregado = null !== $fila['entregado_en'];
						$nombre    = ZN_API::usuario_publico( (int) $fila['user_id'] )['name'] ?? ( ZN_API::nombre_de_pila( (string) $fila['nombre'] ) ?? '—' );
						$enlaces   = $ganador
							? array( 'no_ganador' => 'Quitar ganador', $entregado ? 'no_entregado' : 'entregado' => $entregado ? 'Quitar entrega' : 'Marcar entregado' )
							: array( 'ganador' => 'Marcar ganador' );
						?>
						<tr>
							<td><?php echo esc_html( $nombre ); ?></td>
							<td><?php echo esc_html( (string) ZN_API::hora_madrid( (string) $fila['date'] ) ); ?></td>
							<td><?php if ( '' !== (string) $fila['ticket'] ) : ?><a href="<?php echo esc_url( $urls['ticket']( $id ) ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $urls['ticket']( $id ) ); ?>" alt="Ticket de <?php echo esc_attr( $nombre ); ?>" width="80" height="60" style="object-fit:cover" loading="lazy"></a><?php else : ?>—<?php endif; ?></td>
							<td><?php echo esc_html( $ganador ? ( $entregado ? 'Ganador · entregado' : 'Ganador' ) : 'Participa' ); ?></td>
							<td>
								<?php foreach ( $enlaces as $hacer => $texto ) : ?>
									<a href="<?php echo esc_url( $urls['accion']( $id, $hacer ) ); ?>"><?php echo esc_html( $texto ); ?></a><br>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/** Direcciones de wp-admin (admin-post.php, con sello por acción y fila, R147) para html_lista(). */
	public static function urls_admin( int $sorteo ): array {
		$entrada = get_post( $sorteo );
		return $entrada ? self::urls_con_sello( $entrada, add_query_arg( array( 'action' => 'zn_participantes', 'sorteo' => $sorteo ), admin_url( 'admin-post.php' ) ) ) : array();
	}

	/**
	 * Atiende foto, CSV o marca (GET con sello). La usan admin-post.php (wp-admin) y el panel. $volver: adónde ir
	 * tras marcar. Siempre termina (exit).
	 */
	public static function atender( WP_Post $sorteo, array $get, string $volver, string $clave_avisos ): void {
		$yo = get_current_user_id();
		if ( ! self::puede_gestionar( $sorteo, $yo ) ) {
			wp_die( 'No puedes ver los participantes de este sorteo.', 403 );
		}
		if ( ! empty( $get['ticket'] ) ) {
			$ruta = self::ticket_de_sorteo( $sorteo, absint( $get['ticket'] ), $yo );
			if ( '' === $ruta ) {
				wp_die( 'No encontrado.', 404 );
			}
			nocache_headers();
			header( 'Content-Type: image/jpeg' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Length: ' . filesize( $ruta ) );
			readfile( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			exit;
		}
		if ( ! empty( $get['csv'] ) ) {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="participantes-' . $sorteo->ID . '.csv"' );
			echo self::csv( $sorteo ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}
		$aviso = self::marcar_desde_gestion( $sorteo, absint( $get['fila'] ?? 0 ), sanitize_key( (string) ( $get['hacer'] ?? '' ) ), $yo );
		if ( '' !== $aviso ) {
			set_transient( $clave_avisos . $yo, array( $aviso ), 60 );
		}
		wp_safe_redirect( $volver );
		exit;
	}

	/** admin-post.php?action=zn_participantes (wp-admin): comprueba el sello y atiende. */
	public static function atender_admin(): void {
		$sorteo = self::sorteo( absint( $_GET['sorteo'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $sorteo ) {
			wp_die( 'No encontrado.', 404 );
		}
		if ( ! self::sello_valido( $sorteo, wp_unslash( $_GET ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- sello_valido() lo comprueba.
			wp_die( 'El enlace ha caducado. Vuelve a la ficha del sorteo y prueba otra vez.', 403 );
		}
		self::atender( $sorteo, wp_unslash( $_GET ), (string) get_edit_post_link( $sorteo->ID, 'url' ), 'zn_avisos_' );
	}

	/** Celda de CSV entre comillas; sin fórmulas (un nombre que empiece por = + - @ no se ejecuta en Excel). */
	private static function celda( string $texto ): string {
		if ( preg_match( '/^[=+\-@\t\r]/', $texto ) ) {
			$texto = "'" . $texto;
		}
		return '"' . str_replace( '"', '""', $texto ) . '"';
	}

	/** ¿Es la respuesta un archivo o texto para servir tal cual? (ticket y export). */
	public static function es_archivo( $datos ): bool {
		return is_array( $datos ) && ( isset( $datos['__zn_archivo'] ) || isset( $datos['__zn_texto'] ) );
	}

	/** Filtro rest_pre_serve_request: la foto y el CSV salen tal cual, no como JSON. */
	public static function servir_archivo( $servido, $respuesta, $peticion, $servidor ) {
		if ( $servido || ! $respuesta instanceof WP_HTTP_Response ) {
			return $servido;
		}
		$datos = $respuesta->get_data();
		// R135: con ?_envelope, WordPress mete la respuesta en {body, status, headers}: nunca se enseña la ruta del archivo.
		if ( is_array( $datos ) && isset( $datos['body'] ) && self::es_archivo( $datos['body'] ) ) {
			echo wp_json_encode( array( 'message' => 'The _envelope parameter is not supported for files.' ) );
			return true;
		}
		if ( 200 !== $respuesta->get_status() || ! self::es_archivo( $datos ) ) {
			return $servido;
		}
		if ( isset( $datos['__zn_archivo'] ) ) {
			header( 'Content-Length: ' . filesize( $datos['__zn_archivo'] ) );
			readfile( $datos['__zn_archivo'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		} else {
			echo $datos['__zn_texto']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return true;
	}

	// --- Fotos ---

	/**
	 * R132: lo que se puede saber sin decodificar (solo la cabecera, con getimagesize): tamaño del archivo, que sea
	 * JPG, PNG o WebP de verdad y que no pase de MAX_PIXELES. null si vale; si no, el mensaje del error.
	 */
	private static function comprobar_imagen( string $ruta ): ?string {
		if ( (int) filesize( $ruta ) > self::MAX_BYTES ) {
			return 'The ticket field must not be greater than 8192 kilobytes.';
		}
		$info = @getimagesize( $ruta ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) || ! function_exists( 'imagecreatefromstring' ) ) {
			return 'The ticket field must be an image (jpg, png or webp).';
		}
		if ( (int) $info[0] * (int) $info[1] > self::MAX_PIXELES ) {
			return 'The ticket field has too many pixels (40 megapixels at most).';
		}
		return null;
	}

	/** Imagen GD de una foto ya comprobada (comprobar_imagen), a MAX_LADO como mucho y girada según su EXIF. */
	private static function cargar_imagen( string $ruta ) {
		$info = @getimagesize( $ruta ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info ) {
			return null;
		}
		// Una foto de 40 MP decodificada ocupa unos 160 MB: se sube el límite de memoria como hace WordPress con las imágenes.
		wp_raise_memory_limit( 'image' );
		$imagen = @imagecreatefromstring( (string) file_get_contents( $ruta ) ); // phpcs:ignore
		if ( ! $imagen ) {
			return null;
		}
		// R144: primero se escala a MAX_LADO (girar la foto entera haría otra copia del mismo tamaño) y se suelta la grande.
		$ancho = imagesx( $imagen );
		$alto  = imagesy( $imagen );
		if ( max( $ancho, $alto ) > self::MAX_LADO ) {
			$escala   = self::MAX_LADO / max( $ancho, $alto );
			$pequena  = imagescale( $imagen, (int) round( $ancho * $escala ), (int) round( $alto * $escala ) );
			if ( $pequena ) {
				imagedestroy( $imagen );
				$imagen = $pequena;
			}
		}
		// Las fotos del móvil llevan el giro en el EXIF: se aplica antes de tirarlo.
		$giro = IMAGETYPE_JPEG === $info[2] && function_exists( 'exif_read_data' ) ? (int) ( @exif_read_data( $ruta )['Orientation'] ?? 1 ) : 1; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$grados = array( 3 => 180, 6 => -90, 8 => 90 )[ $giro ] ?? 0;
		$girada = $grados ? imagerotate( $imagen, $grados, 0 ) : false;
		if ( $girada ) {
			imagedestroy( $imagen );
			$imagen = $girada;
		}
		return $imagen;
	}

	/** Guarda la imagen en JPG nuevo (GD no copia ningún metadato: decisión 2) con nombre al azar. */
	private static function guardar_imagen( $imagen ): string {
		$carpeta = self::carpeta();
		$nombre  = strtolower( wp_generate_password( 32, false, false ) ) . '.jpg';
		return '' !== $carpeta && imagejpeg( $imagen, "$carpeta/$nombre", 85 ) ? $nombre : '';
	}

	/**
	 * Borra una fila y su foto. R136: si la foto no se puede borrar, la fila se queda (la tarea diaria lo reintenta)
	 * para no dejar un archivo sin dueño. Devuelve si se ha borrado.
	 */
	public static function borrar_fila( array $fila ): bool {
		global $wpdb;
		if ( ! self::quitar_foto( $fila ) ) {
			return false;
		}
		$wpdb->delete( self::tabla(), array( 'id' => (int) $fila['id'] ), array( '%d' ) );
		return true;
	}

	/** Quita la foto de una fila; true si ya no está (o no tenía). */
	private static function quitar_foto( array $fila ): bool {
		$ruta = self::ruta_ticket( $fila );
		if ( '' === $ruta || ! is_file( $ruta ) ) {
			return true;
		}
		return @unlink( $ruta ); // phpcs:ignore
	}

	/** Ganador sin premio entregado: se queda la fila con nombre y correo, sin foto (decisión 7). */
	private static function dejar_solo_datos_de_entrega( array $fila ): void {
		global $wpdb;
		if ( self::quitar_foto( $fila ) ) {
			$wpdb->update( self::tabla(), array( 'ticket' => '' ), array( 'id' => (int) $fila['id'] ) );
		}
	}

	/** R136: fotos de la carpeta que ya no tienen fila (de más de un día, para no pisar una que se esté guardando). */
	private static function barrer_huerfanas( int $ahora ): void {
		global $wpdb;
		$carpeta = self::carpeta();
		if ( '' === $carpeta ) {
			return;
		}
		$usadas = array_flip( $wpdb->get_col( 'SELECT ticket FROM ' . self::tabla() . " WHERE ticket <> ''" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) scandir( $carpeta ) as $nombre ) {
			$ruta = "$carpeta/$nombre";
			if ( preg_match( '/^[a-z0-9]{32}\.jpg$/', (string) $nombre ) && ! isset( $usadas[ $nombre ] ) && is_file( $ruta ) && ! is_link( $ruta ) && filemtime( $ruta ) < $ahora - DAY_IN_SECONDS ) {
				@unlink( $ruta ); // phpcs:ignore
			}
		}
	}

	private static function pendiente_de_entrega( array $fila ): bool {
		return '1' === (string) $fila['ganador'] && null === $fila['entregado_en'];
	}

	private static function avisar_ganador( array $fila ): void {
		$cuenta = get_userdata( (int) $fila['user_id'] );
		$sorteo = self::sorteo( (int) $fila['draw_id'] );
		if ( ! $cuenta || ! $sorteo ) {
			return;
		}
		$titulo = wp_strip_all_tags( get_the_title( $sorteo ) );
		wp_mail(
			$cuenta->user_email,
			'Zona Náutico: ¡has ganado el sorteo!',
			"Hola:\n\nHas ganado el sorteo «{$titulo}» de la app Zona Náutico. Quien lo organiza se pondrá en contacto contigo para entregarte el premio.\n"
		);
	}

	// --- Borrado automático (decisiones 1 y b) ---

	/** Tarea diaria. $ahora (marca de tiempo) solo para las pruebas. */
	public static function limpiar( ?int $ahora = null ): void {
		global $wpdb;
		$ahora   = $ahora ?? time();
		$sorteos = array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT draw_id FROM ' . self::tabla() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $sorteos as $sorteo_id ) {
			$filas     = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE draw_id = %d', $sorteo_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ganadores = array_filter( $filas, static fn( $f ) => '1' === (string) $f['ganador'] );
			$entregas  = array_filter( array_column( $ganadores, 'entregado_en' ) );
			$todos     = $ganadores && count( $entregas ) === count( $ganadores );
			$fin       = self::sorteo( $sorteo_id ) ? ZN_API::campo( $sorteo_id, 'end_date' ) : null;
			$fin_ts    = $fin ? ( new DateTimeImmutable( $fin, new DateTimeZone( 'Europe/Madrid' ) ) )->getTimestamp() : strtotime( max( array_column( $filas, 'date' ) ) . ' UTC' );
			// Decisión 1 y R138: 30 días desde que el sorteo está cerrado y entregado (lo que pase más tarde).
			// Decisión b y R133: si no se entrega, a los 90 días del fin, también los datos de quien ganó.
			$limite = $todos
				? max( strtotime( max( $entregas ) . ' UTC' ), $fin_ts ) + self::DIAS_TRAS_ENTREGA * DAY_IN_SECONDS
				: $fin_ts + self::DIAS_SIN_ENTREGA * DAY_IN_SECONDS;
			if ( $ahora < $limite ) {
				continue;
			}
			foreach ( $filas as $fila ) {
				if ( '1' === (string) $fila['ganador'] ) {
					// R141: queda la fila con su user_id (que ganó); mientras exista la cuenta, winnerNames sigue dando su
					// nombre de pila (decisión 4). Se quitan la foto, el nombre y el correo guardados.
					if ( self::quitar_foto( $fila ) ) {
						$wpdb->update( self::tabla(), array( 'ticket' => '', 'nombre' => '', 'correo' => '' ), array( 'id' => (int) $fila['id'] ) );
					}
				} else {
					self::borrar_fila( $fila );
				}
			}
		}
		self::barrer_huerfanas( time() );
	}

	// --- RGPD (R65, decisión 7) ---

	private static function de( int $usuario ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE user_id = %d', $usuario ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Borra lo de una cuenta salvo un premio pendiente; devuelve cuántas se borran y cuántas se guardan. */
	public static function borrar_de( int $usuario ): array {
		$borradas = 0;
		$guardadas = 0;
		foreach ( self::de( $usuario ) as $fila ) {
			if ( self::pendiente_de_entrega( $fila ) ) {
				self::dejar_solo_datos_de_entrega( $fila );
				++$guardadas;
			} else {
				self::borrar_fila( $fila );
				++$borradas;
			}
		}
		return array( $borradas, $guardadas );
	}

	public static function al_borrar_cuenta( $id ): void {
		self::borrar_de( (int) $id );
	}

	public static function al_borrar_sorteo( $id ): void {
		if ( ZN_Sorteos::TIPO !== get_post_type( (int) $id ) ) {
			return;
		}
		global $wpdb;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE draw_id = %d', (int) $id ), ARRAY_A ) as $fila ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( self::pendiente_de_entrega( $fila ) ) {
				self::dejar_solo_datos_de_entrega( $fila );
			} else {
				self::borrar_fila( $fila );
			}
		}
	}

	/** Para el exportador de datos personales: sus participaciones, sin la foto. */
	public static function para_exportar( int $usuario ): array {
		return array_map(
			static function ( array $fila ): array {
				$sorteo = self::sorteo( (int) $fila['draw_id'] );
				return array(
					'id'    => (int) $fila['id'],
					'datos' => array(
						array( 'name' => 'Sorteo', 'value' => $sorteo ? get_the_title( $sorteo ) : '#' . (int) $fila['draw_id'] ),
						array( 'name' => 'Fecha', 'value' => (string) ZN_API::hora_madrid( (string) $fila['date'] ) ),
						array( 'name' => 'Ha ganado', 'value' => '1' === (string) $fila['ganador'] ? 'Sí' : 'No' ),
						array( 'name' => 'Premio entregado', 'value' => null !== $fila['entregado_en'] ? 'Sí' : 'No' ),
					),
				);
			},
			self::de( $usuario )
		);
	}

	/** Nombres de pila de quien ha ganado un sorteo (includesWinners, decisión 4). */
	public static function ganadores( int $sorteo ): array {
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . self::tabla() . ' WHERE draw_id = %d AND ganador = 1 ORDER BY id ASC', $sorteo ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_values( array_filter( array_map( static fn( $id ) => ZN_API::usuario_publico( $id )['name'] ?? null, $ids ) ) );
	}

	/**
	 * Para uninstall.php: borra la carpeta de las fotos. Solo si es una carpeta válida (R134) y solo lo que pone el
	 * plugin (fotos con su nombre al azar, .htaccess e index.php); si queda algo más, la carpeta no se quita.
	 * Devuelve false si no se ha borrado nada por no ser válida.
	 */
	public static function borrar_carpeta(): bool {
		$carpeta = self::carpeta_pedida();
		if ( ! self::ruta_valida( $carpeta ) || ! is_dir( $carpeta ) ) {
			return false;
		}
		foreach ( (array) scandir( $carpeta ) as $nombre ) {
			if ( preg_match( '/^([a-z0-9]{32}\.jpg|\.htaccess|index\.php)$/', (string) $nombre ) && is_file( "$carpeta/$nombre" ) && ! is_link( "$carpeta/$nombre" ) ) {
				unlink( "$carpeta/$nombre" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		@rmdir( $carpeta ); // phpcs:ignore
		return true;
	}
}

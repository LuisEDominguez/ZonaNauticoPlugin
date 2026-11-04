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
 * Fotos: fuera de la carpeta pública de WordPress si se puede (carpeta()); si no, en uploads/zn-privado con
 * .htaccess que lo niega todo. Nunca en la biblioteca de medios ni con URL pública: solo por la ruta /ticket.
 * Borrado (tarea diaria): 30 días después de entregar todos los premios del sorteo (decisión 1), o 90 días después
 * del fin si no se entregan (decisión b): se borran todas las fotos y las participaciones de quien no ganó; a quien
 * ganó se le quitan nombre, correo y foto. RGPD (R65, decisión 7): borrar la cuenta o el sorteo lo borra todo salvo
 * a quien ganó y aún no tiene su premio, de quien se guardan nombre y correo hasta la entrega.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Participaciones {

	const VERSION_TABLA = '1';
	const TAREA         = 'zn_limpiar_participaciones';
	const MAX_BYTES     = 8 * MB_IN_BYTES;
	const MAX_LADO      = 2000;
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
		$fuera   = wp_normalize_path( dirname( untrailingslashit( ABSPATH ) ) ) . '/zn-privado-tickets';
		$dentro  = wp_normalize_path( wp_get_upload_dir()['basedir'] ) . '/zn-privado';
		$carpeta = (string) apply_filters( 'zn_carpeta_tickets', ( is_dir( $fuera ) || wp_is_writable( dirname( $fuera ) ) ) ? $fuera : $dentro );
		if ( ! is_dir( $carpeta ) ) {
			wp_mkdir_p( $carpeta );
		}
		if ( ! is_file( "$carpeta/.htaccess" ) ) {
			file_put_contents( "$carpeta/.htaccess", "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! is_file( "$carpeta/index.php" ) ) {
			file_put_contents( "$carpeta/index.php", "<?php\n// Silencio.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $carpeta;
	}

	/** Ruta del archivo de la foto de una fila; '' si no tiene. */
	public static function ruta_ticket( ?array $fila ): string {
		$nombre = (string) ( $fila['ticket'] ?? '' );
		return preg_match( '/^[a-z0-9]{32}\.jpg$/', $nombre ) ? self::carpeta() . '/' . $nombre : '';
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
		$imagen   = null;
		if ( ! is_array( $foto ) || UPLOAD_ERR_OK !== (int) ( $foto['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $foto['tmp_name'] ) || ! is_file( $foto['tmp_name'] ) ) {
			$errores['ticket'] = ZN_Esquema::mensaje( 'required', 'ticket' );
		} elseif ( (int) filesize( $foto['tmp_name'] ) > self::MAX_BYTES ) {
			$errores['ticket'] = 'The ticket field must not be greater than 8192 kilobytes.';
		} else {
			$imagen = self::cargar_imagen( $foto['tmp_name'] );
			if ( ! $imagen ) {
				$errores['ticket'] = 'The ticket field must be an image (jpg, png or webp).';
			}
		}
		if ( $errores ) {
			return ZN_Errores::no_valido( $errores );
		}
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
		global $wpdb;
		$existe = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::tabla() . ' WHERE draw_id = %d AND user_id = %d', $sorteo->ID, $yo ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$repetida = ZN_Errores::no_valido( array( 'draw' => 'You are already taking part in this draw.' ) );
		if ( $existe ) {
			return $repetida;
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
		$cuerpo  = ZN_API::cuerpo( $peticion );
		$cambios = array();
		if ( array_key_exists( 'winner', $cuerpo ) ) {
			$ganador = in_array( $cuerpo['winner'], array( true, 1, '1', 'true' ), true );
			$cambios['ganador'] = $ganador ? 1 : 0;
			if ( ! $ganador ) {
				$cambios += array( 'entregado_en' => null, 'nombre' => '', 'correo' => '' );
			} elseif ( '1' !== (string) $fila['ganador'] ) {
				$cuenta  = get_userdata( (int) $fila['user_id'] );
				$cambios += array( 'nombre' => $cuenta ? (string) ZN_API::nombre_publico( $cuenta ) : '', 'correo' => $cuenta ? $cuenta->user_email : '' );
			}
		}
		if ( array_key_exists( 'delivered', $cuerpo ) ) {
			$entregado = in_array( $cuerpo['delivered'], array( true, 1, '1', 'true' ), true );
			if ( $entregado && 1 !== ( $cambios['ganador'] ?? (int) $fila['ganador'] ) ) {
				return ZN_Errores::no_valido( array( 'delivered' => 'Only a winner can be marked as delivered.' ) );
			}
			$cambios['entregado_en'] = $entregado ? current_time( 'mysql', true ) : null;
			// Entregado a una cuenta que ya no existe: ya no hace falta guardar sus datos (decisión 7).
			if ( $entregado && ! get_userdata( (int) $fila['user_id'] ) ) {
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
		return new WP_REST_Response( array( 'data' => self::formatear( self::fila( (int) $fila['id'] ) ) ) );
	}

	public static function exportar( WP_REST_Request $peticion ): WP_REST_Response {
		$sorteo = self::sorteo( (int) $peticion['drawId'] );
		if ( ! $sorteo || ! ( ZN_Roles::puede( 'draw_participation:export', $sorteo ) ) ) {
			return ZN_Errores::sin_permiso();
		}
		if ( 'csv' !== $peticion['format'] ) {
			return ZN_Errores::no_valido( array( 'format' => 'The selected format is invalid.' ) );
		}
		global $wpdb;
		$filas = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE draw_id = %d ORDER BY id ASC', $sorteo->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$lineas = array( 'Nombre;Correo' );
		foreach ( $filas as $fila ) {
			$cuenta   = get_userdata( (int) $fila['user_id'] );
			$nombre   = $cuenta ? (string) ZN_API::nombre_publico( $cuenta ) : (string) $fila['nombre'];
			$correo   = $cuenta ? $cuenta->user_email : (string) $fila['correo'];
			$lineas[] = self::celda( $nombre ) . ';' . self::celda( $correo );
		}
		$respuesta = new WP_REST_Response( array( '__zn_texto' => "\xEF\xBB\xBF" . implode( "\r\n", $lineas ) . "\r\n" ) );
		$respuesta->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$respuesta->header( 'Content-Disposition', 'attachment; filename="participantes-' . $sorteo->ID . '.csv"' );
		$respuesta->header( 'Cache-Control', 'no-store, private' );
		return $respuesta;
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
		if ( $servido || ! $respuesta instanceof WP_HTTP_Response || 200 !== $respuesta->get_status() || ! self::es_archivo( $respuesta->get_data() ) ) {
			return $servido;
		}
		$datos = $respuesta->get_data();
		if ( isset( $datos['__zn_archivo'] ) ) {
			header( 'Content-Length: ' . filesize( $datos['__zn_archivo'] ) );
			readfile( $datos['__zn_archivo'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		} else {
			echo $datos['__zn_texto']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return true;
	}

	// --- Fotos ---

	/** Imagen GD de un JPG, PNG o WebP de verdad (se mira el contenido, no el nombre), ya girada según su EXIF. */
	private static function cargar_imagen( string $ruta ) {
		$info = @getimagesize( $ruta ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) || ! function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}
		$imagen = @imagecreatefromstring( (string) file_get_contents( $ruta ) ); // phpcs:ignore
		if ( ! $imagen ) {
			return null;
		}
		// Las fotos del móvil llevan el giro en el EXIF: se aplica antes de tirarlo.
		$giro = IMAGETYPE_JPEG === $info[2] && function_exists( 'exif_read_data' ) ? (int) ( @exif_read_data( $ruta )['Orientation'] ?? 1 ) : 1; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$grados = array( 3 => 180, 6 => -90, 8 => 90 )[ $giro ] ?? 0;
		if ( $grados ) {
			$imagen = imagerotate( $imagen, $grados, 0 ) ?: $imagen;
		}
		$ancho = imagesx( $imagen );
		$alto  = imagesy( $imagen );
		if ( max( $ancho, $alto ) > self::MAX_LADO ) {
			$escala = self::MAX_LADO / max( $ancho, $alto );
			$imagen = imagescale( $imagen, (int) round( $ancho * $escala ), (int) round( $alto * $escala ) ) ?: $imagen;
		}
		return $imagen;
	}

	/** Guarda la imagen en JPG nuevo (GD no copia ningún metadato: decisión 2) con nombre al azar. */
	private static function guardar_imagen( $imagen ): string {
		$nombre = strtolower( wp_generate_password( 32, false, false ) ) . '.jpg';
		return imagejpeg( $imagen, self::carpeta() . "/$nombre", 85 ) ? $nombre : '';
	}

	/** Borra una fila y su foto. */
	public static function borrar_fila( array $fila ): void {
		global $wpdb;
		self::quitar_foto( $fila );
		$wpdb->delete( self::tabla(), array( 'id' => (int) $fila['id'] ), array( '%d' ) );
	}

	private static function quitar_foto( array $fila ): void {
		$ruta = self::ruta_ticket( $fila );
		if ( '' !== $ruta && is_file( $ruta ) ) {
			unlink( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/** Ganador sin premio entregado: se queda la fila con nombre y correo, sin foto (decisión 7). */
	private static function dejar_solo_datos_de_entrega( array $fila ): void {
		global $wpdb;
		self::quitar_foto( $fila );
		$wpdb->update( self::tabla(), array( 'ticket' => '' ), array( 'id' => (int) $fila['id'] ) );
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
			if ( $todos ) {
				$limite = strtotime( max( $entregas ) . ' UTC' ) + self::DIAS_TRAS_ENTREGA * DAY_IN_SECONDS;
			} else {
				$fin    = self::sorteo( $sorteo_id ) ? ZN_API::campo( $sorteo_id, 'end_date' ) : null;
				$base   = $fin ? ( new DateTimeImmutable( $fin, new DateTimeZone( 'Europe/Madrid' ) ) )->getTimestamp() : strtotime( max( array_column( $filas, 'date' ) ) . ' UTC' );
				$limite = $base + self::DIAS_SIN_ENTREGA * DAY_IN_SECONDS;
			}
			if ( $ahora < $limite ) {
				continue;
			}
			foreach ( $filas as $fila ) {
				if ( '1' === (string) $fila['ganador'] ) {
					// Solo queda que ganó (para el historial del sorteo); nada personal.
					self::quitar_foto( $fila );
					$wpdb->update( self::tabla(), array( 'ticket' => '', 'nombre' => '', 'correo' => '' ), array( 'id' => (int) $fila['id'] ) );
				} else {
					self::borrar_fila( $fila );
				}
			}
		}
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

	/** Para uninstall.php: la carpeta de las fotos. */
	public static function borrar_carpeta(): void {
		$carpeta = self::carpeta();
		foreach ( (array) glob( "$carpeta/{,.}*", GLOB_BRACE ) as $archivo ) {
			if ( is_file( $archivo ) ) {
				unlink( $archivo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		@rmdir( $carpeta ); // phpcs:ignore
	}
}

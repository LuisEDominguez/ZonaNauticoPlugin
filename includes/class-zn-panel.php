<?php
/**
 * Panel de wp-admin para asociación y federación (R40), parte 1: entrar y SOLO VER lo suyo.
 *
 * - Entran por wp-login.php con la misma cuenta de la app (correo o nombre de acceso y su contraseña), si
 *   tienen un único rol (asociación o federación, R37) y el correo verificado. Con el mismo límite de intentos
 *   que POST /login (ZN_Puertas::sin_panel_para_la_app). Comercios, usuarios y la administración de la app, no.
 * - Solo ven una página, «Mi zona»: el resto de wp-admin (escritorio, perfil, admin-post y admin-ajax salvo el
 *   latido) los devuelve a ella. Si la cuenta deja de poder entrar (otro rol, sin verificar), se cierra su sesión.
 * - «Lo suyo» = él y las cuentas que cuelgan directamente de él y puede leer (user:read_<rol>, un nivel, como
 *   los permisos de la app) y, para la federación, también los comercios de sus asociaciones (solo ver, sin su
 *   correo):
 *   esas cuentas, y los negocios, novedades y sorteos de los que alguno es dueño.
 * - Parte 2a (Luis, 02-11): editan negocios, novedades y sorteos con los MISMOS permisos que la app
 *   (ZN_Roles::puede: lo suyo y, donde la app hereda, lo de sus hijas; un nivel). Formulario propio en «Mi zona»
 *   (admin.php?page=zn-panel&editar=<id>, enviado con `zn_hacer`, nunca `action`, R97), con sello (nonce), el
 *   esquema común (ZN_Esquema::comprobar, avisos en español) y el límite de escrituras de la app (R94). Sin dueño,
 *   fotos, vídeos, coordenadas ni negocio de la novedad. Estado: solo pasar a borrador; publicar, solo la
 *   administración (R99, Luis). Borrar = a la papelera, y las novedades de un negocio borrado, a borrador (R100, Luis).
 * - Parte 2b (Luis, 02-11): cuentas. La asociación da de alta, renombra y da de baja SUS comercios; la federación,
 *   SUS asociaciones (ROL_HIJO), con los permisos de la app (user:register-<rol>, user:save_<rol>,
 *   user:delete_<rol>, colgando de él). Alta: nombre y correo, sin contraseña (al azar, nadie la conoce) y sin
 *   verificar; le llega un correo para ponerla con «Olvidé la contraseña» en la app (el código verifica el correo).
 *   Baja = desactivar (zn_desactivada): no entra en la app ni en el panel, se cierran sus sesiones y sus negocios,
 *   novedades y sorteos pasan a borrador; no se borra nada. La administración la reactiva desde Usuarios.
 *   Ni usuarios normales, ni correos, ni contraseñas.
 * - Su propio perfil de WordPress no se edita (edit_user denegado a todas las cuentas de la app). La cookie del
 *   panel no vale en /zonanautico/v1 (ZN_Tokens).
 * - Entrar en la app no cierra su sesión del panel (R98, decisión de Luis); a las demás cuentas, sí (R39).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Panel {

	const PERMISO = 'zn_panel_ver';
	const PAGINA  = 'zn-panel';
	// Máximo de filas por tabla (las ramas son pequeñas; si se pasa, se avisa).
	const MAXIMO = 200;

	const ESTADOS = array( 'publish', 'future', 'draft', 'pending', 'private' );

	// Tipo de entrada => recurso de los permisos de la app.
	const PERMISOS = array(
		'negocio' => 'business',
		'evento'  => 'event',
		'sorteo'  => 'draw',
	);

	// Campos que se editan en el panel (nombre guardado => casilla), en este orden.
	const CAMPOS = array(
		'negocio' => array(
			'post_title'           => 'texto',
			'business_category_id' => 'opciones',
			'post_content'         => 'parrafos',
			'address'              => 'texto',
			'postcode'             => 'texto',
			'city'                 => 'texto',
			'state'                => 'texto',
			'country'              => 'texto',
			'phone'                => 'texto',
			'email'                => 'texto',
			'web'                  => 'url',
			'schedule'             => 'parrafos',
		),
		'evento'  => array(
			'post_title'        => 'texto',
			'event_category_id' => 'opciones',
			'post_content'      => 'parrafos',
			'start_date'        => 'fecha',
			'end_date'          => 'fecha',
		),
		'sorteo'  => array(
			'post_title'   => 'texto',
			'post_content' => 'parrafos',
			'start_date'   => 'fecha',
			'end_date'     => 'fecha',
			'winners'      => 'numero',
			'legal_url'    => 'url',
		),
	);

	// Rol de la app de quien gestiona => rol de WordPress de las cuentas que da de alta, renombra y da de baja (2b).
	const ROL_HIJO = array(
		'association' => 'zn_comercio',
		'federation'  => 'zn_asociacion',
	);

	// Estados que se pueden elegir en el panel.
	const ESTADOS_EDITABLES = array(
		'publish' => 'Publicado',
		'draft'   => 'Borrador',
	);

	public static function iniciar(): void {
		add_filter( 'map_meta_cap', array( __CLASS__, 'sin_editar_cuentas' ), 10, 3 );
		add_filter( 'login_redirect', array( __CLASS__, 'al_entrar' ), 10, 3 );
		add_action( 'wp_login', array( __CLASS__, 'olvidar_intentos' ), 10, 2 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'sin_barra_publica' ) );
		add_action( 'admin_init', array( __CLASS__, 'vigilar' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 999 );
		// Después de todo lo de WordPress (la cuenta, «Hola, …», se añade con prioridad 9991).
		add_action( 'admin_bar_menu', array( __CLASS__, 'barra' ), PHP_INT_MAX );
		// Reactivar una cuenta dada de baja: solo la administración, desde Usuarios (2b).
		add_filter( 'user_row_actions', array( __CLASS__, 'accion_reactivar' ), 10, 2 );
		add_action( 'admin_post_zn_reactivar', array( __CLASS__, 'al_reactivar' ) );
	}

	/** ¿Puede entrar al panel? Asociación o federación (un único rol) con el correo verificado. */
	public static function puede_entrar( WP_User $cuenta ): bool {
		return in_array( ZN_Roles::rol_app( $cuenta ), array( 'association', 'federation' ), true )
			&& '1' === (string) get_user_meta( $cuenta->ID, 'zn_verificado', true )
			&& ! self::desactivada( $cuenta->ID );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGINA );
	}

	/**
	 * Lo único de wp-admin que puede abrir una cuenta del panel: su página y el latido de admin-ajax.
	 * La página se mira en la dirección, como la enruta admin.php, y sin ninguna `action` (R97).
	 */
	public static function pagina_permitida( string $pagina, array $get, array $post = array() ): bool {
		if ( 'admin.php' === $pagina ) {
			return self::PAGINA === ( $get['page'] ?? null ) && ! isset( $get['action'] ) && ! isset( $post['action'] );
		}
		if ( 'admin-ajax.php' === $pagina ) {
			return 'heartbeat' === ( $post['action'] ?? $get['action'] ?? null );
		}
		return false;
	}

	/**
	 * Cuentas que cuelgan directamente de él y puede leer (user:read_<rol>). La federación ve además los
	 * comercios de sus asociaciones (decisión de Luis, 02-11: ver dos niveles; editar, en la parte 2, uno solo).
	 */
	public static function cuentas( WP_User $yo ): array {
		if ( ! self::puede_entrar( $yo ) ) {
			return array();
		}
		$hijas = array_values( array_filter( self::hijas( array( $yo->ID ), array_keys( ZN_Roles::ROLES_APP ) ), static fn( WP_User $hija ) => ZN_Roles::puede( 'user:read', $hija, $yo->ID ) ) );
		if ( 'federation' !== ZN_Roles::rol_app( $yo ) || ! $hijas ) {
			return $hijas;
		}
		$asociaciones = array_filter( $hijas, static fn( WP_User $hija ) => 'association' === ZN_Roles::rol_app( $hija ) );
		$nietas       = $asociaciones ? self::hijas( array_map( static fn( $a ) => (int) $a->ID, $asociaciones ), array( 'zn_comercio' ) ) : array();
		return array_slice( array_merge( $hijas, array_values( array_filter( $nietas, static fn( WP_User $c ) => 'commerce' === ZN_Roles::rol_app( $c ) ) ) ), 0, self::MAXIMO );
	}

	/** Cuentas con alguno de esos roles que cuelgan de alguna de esas cuentas. */
	private static function hijas( array $padres, array $roles ): array {
		return get_users(
			array(
				'role__in'     => $roles,
				'meta_key'     => 'zn_parent_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'   => array_map( 'strval', $padres ), // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'IN',
				'orderby'      => 'ID',
				'number'       => self::MAXIMO,
			)
		);
	}

	/** Negocios, novedades o sorteos (no en la papelera) de él o de sus cuentas. */
	public static function entradas( WP_User $yo, string $tipo ): array {
		if ( ! self::puede_entrar( $yo ) ) {
			return array();
		}
		$duenos = array_merge( array( $yo->ID ), array_map( static fn( $c ) => (int) $c->ID, self::cuentas( $yo ) ) );
		return get_posts(
			array(
				'post_type'        => $tipo,
				'post_status'      => self::ESTADOS,
				'meta_query'       => array( array( 'key' => 'user_id', 'value' => array_map( 'strval', $duenos ), 'compare' => 'IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'          => 'title',
				'order'            => 'ASC',
				'posts_per_page'   => self::MAXIMO,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
	}

	/** Ninguna cuenta de la app edita cuentas en wp-admin, ni la suya (perfil, /wp/v2/users/me). */
	public static function sin_editar_cuentas( array $caps, string $cap, int $usuario ): array {
		if ( 'edit_user' !== $cap || ! $usuario ) {
			return $caps;
		}
		$cuenta = get_userdata( $usuario );
		return $cuenta && ZN_Roles::tiene_rol_de_la_app( $cuenta ) ? array( 'do_not_allow' ) : $caps;
	}

	/** @param WP_User|WP_Error $usuario */
	public static function al_entrar( $destino, $pedido, $usuario ) {
		return $usuario instanceof WP_User && self::puede_entrar( $usuario ) ? self::url() : $destino;
	}

	/** Al entrar bien por wp-login.php se olvidan sus intentos, como en POST /login. */
	public static function olvidar_intentos( $acceso, $usuario ): void {
		if ( $usuario instanceof WP_User && ZN_Roles::tiene_rol_de_la_app( $usuario ) ) {
			ZN_Limite::olvidar( 'login', $usuario->user_email );
		}
	}

	public static function sin_barra_publica( $mostrar ) {
		return is_user_logged_in() && ZN_Roles::tiene_rol_de_la_app( wp_get_current_user() ) ? false : $mostrar;
	}

	/** Cada petición de wp-admin de una cuenta de la app: fuera si ya no puede entrar; si no, solo su página. */
	public static function vigilar(): void {
		$cuenta = wp_get_current_user();
		if ( ! $cuenta->exists() || ! ZN_Roles::tiene_rol_de_la_app( $cuenta ) ) {
			return;
		}
		if ( ! self::puede_entrar( $cuenta ) ) {
			wp_logout();
			if ( wp_doing_ajax() ) {
				wp_die( '-1', 403 );
			}
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		global $pagenow;
		if ( self::pagina_permitida( (string) $pagenow, wp_unslash( $_GET ), wp_unslash( $_POST ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( wp_doing_ajax() ) {
			wp_die( '-1', 403 );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Entrada de su rama que puede guardar ('save') o borrar ('delete') según la app, o null. Solo negocios,
	 * novedades y sorteos, ni en la papelera ni a medio crear.
	 */
	public static function editable( WP_User $yo, int $id, string $accion = 'save' ): ?WP_Post {
		$entrada = $id > 0 ? get_post( $id ) : null;
		if ( ! $entrada || ! self::puede_entrar( $yo ) || ! isset( self::PERMISOS[ $entrada->post_type ] ) || in_array( $entrada->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return null;
		}
		// Lo de una cuenta dada de baja se queda como está (2b).
		if ( self::desactivada( (int) get_post_meta( $entrada->ID, 'user_id', true ) ) ) {
			return null;
		}
		return ZN_Roles::puede( self::PERMISOS[ $entrada->post_type ] . ':' . $accion, $entrada, $yo->ID ) ? $entrada : null;
	}

	/** ¿Cuenta dada de baja desde el panel? */
	public static function desactivada( int $id ): bool {
		return $id > 0 && '1' === (string) get_user_meta( $id, 'zn_desactivada', true );
	}

	/**
	 * Cuenta que gestiona ($accion 'save' o 'delete'): de su ROL_HIJO (y solo ese rol), colgando de él, activa y
	 * con el permiso de la app (ZN_Roles::puede( 'user:<acción>' ) → user:<acción>_<rol>); o null.
	 */
	public static function gestionable( WP_User $yo, int $id, string $accion = 'save' ): ?WP_User {
		$cuenta = $id > 0 ? get_userdata( $id ) : false;
		$rol    = self::ROL_HIJO[ (string) ZN_Roles::rol_app( $yo ) ] ?? null;
		if ( ! $cuenta || ! $rol || ! self::puede_entrar( $yo ) || array( $rol ) !== array_values( (array) $cuenta->roles ) || ZN_Roles::padre( $cuenta->ID ) !== $yo->ID || self::desactivada( $cuenta->ID ) ) {
			return null;
		}
		return ZN_Roles::puede( 'user:' . $accion, $cuenta, $yo->ID ) ? $cuenta : null;
	}

	/** ¿Puede dar de alta cuentas de su ROL_HIJO? (user:register-commerce o user:register-association). */
	public static function puede_dar_de_alta( WP_User $yo ): bool {
		$rol = self::ROL_HIJO[ (string) ZN_Roles::rol_app( $yo ) ] ?? null;
		return $rol && self::puede_entrar( $yo ) && ZN_Roles::puede( 'user:register-' . ZN_Roles::ROLES_APP[ $rol ], null, $yo->ID );
	}

	/**
	 * Un campo del esquema de cuenta (name → first_name, R53; email) comprobado; aviso en español o null.
	 *
	 * @param mixed $valor
	 */
	private static function comprobar_cuenta( string $app, $valor, array &$valores ): ?string {
		$campo     = ZN_Esquema::campos( 'cuenta' )[ $app ];
		$valor     = is_string( $valor ) ? trim( $valor ) : '';
		$resultado = '' === $valor ? array( 'errores' => true, 'codigo' => 'required' ) : ZN_Esquema::comprobar( $campo, $valor, $app );
		if ( isset( $resultado['errores'] ) ) {
			return ZN_Esquema::aviso( $campo, $resultado );
		}
		$valores += $resultado['valores'];
		return null;
	}

	/**
	 * Alta de una cuenta de su ROL_HIJO que cuelga de él, con nombre y correo. Contraseña al azar que nadie conoce y
	 * sin verificar: le llega un correo para ponerla desde la app («Olvidé la contraseña»). Cuenta para el límite de
	 * escrituras de la app. Devuelve ['ok', 'avisos', 'id'].
	 */
	public static function dar_de_alta( WP_User $yo, array $datos ): array {
		if ( ! self::puede_dar_de_alta( $yo ) ) {
			return array( 'ok' => false, 'avisos' => array( 'No puedes dar de alta cuentas.' ), 'id' => 0 );
		}
		if ( null !== ZN_Limite::intentar( 'escritura', $yo->user_email ) ) {
			return array( 'ok' => false, 'avisos' => array( 'Has hecho demasiados cambios seguidos. Espera un rato y vuelve a intentarlo.' ), 'id' => 0 );
		}
		$valores = array();
		$avisos  = array_filter( array( self::comprobar_cuenta( 'name', $datos['name'] ?? null, $valores ), self::comprobar_cuenta( 'email', $datos['email'] ?? null, $valores ) ) );
		if ( ! $avisos && email_exists( $valores['user_email'] ) ) {
			$avisos[] = 'Ya hay una cuenta con ese correo; no se ha creado.';
		}
		if ( $avisos ) {
			return array( 'ok' => false, 'avisos' => array_values( $avisos ), 'id' => 0 );
		}
		$rol   = self::ROL_HIJO[ ZN_Roles::rol_app( $yo ) ];
		$login = 'zn' . bin2hex( random_bytes( 8 ) );
		$id    = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $valores['user_email'],
				'user_pass'    => wp_generate_password( 32, true, true ),
				'role'         => $rol,
				'first_name'   => $valores['first_name'],
				'last_name'    => '',
				'display_name' => $login,
				'nickname'     => $login,
			)
		);
		if ( is_wp_error( $id ) ) {
			return array( 'ok' => false, 'avisos' => array( 'No se ha podido crear la cuenta. Vuelve a intentarlo.' ), 'id' => 0 );
		}
		update_user_meta( $id, 'zn_parent_id', $yo->ID );
		wp_mail(
			$valores['user_email'],
			'Zona Náutico: se ha creado tu cuenta',
			sprintf(
				"Hola:\n\n%s ha creado tu cuenta %s en la app Zona Náutico con este correo.\n\nPara entrar, abre la app, ve a «Iniciar sesión» y pulsa «Olvidé la contraseña». Te llegará un código para elegir tu contraseña.\n\n%s\n",
				self::nombre_para_correo( $yo ),
				'zn_comercio' === $rol ? 'de comercio' : 'de asociación',
				self::linea_contacto()
			)
		);
		return array( 'ok' => true, 'avisos' => array(), 'id' => (int) $id );
	}

	/**
	 * Línea final del correo de alta (R103): a quién avisar si no conoce a quien le ha dado de alta. El correo de
	 * contacto va en wp-config.php: define( 'ZN_CORREO_CONTACTO', '…' ); (pendiente de que Luis diga cuál). Sin él,
	 * o si no es un correo, una línea sin dirección.
	 */
	public static function linea_contacto( ?string $correo = null ): string {
		$correo = $correo ?? ( defined( 'ZN_CORREO_CONTACTO' ) ? (string) ZN_CORREO_CONTACTO : '' );
		return is_email( $correo )
			? "Si no conoces a quien te ha dado de alta o no esperabas este correo, no entres y escribe a $correo."
			: 'Si no conoces a quien te ha dado de alta o no esperabas este correo, no entres y avisa a Zona Náutico.';
	}

	/** Nombre de quien da de alta, para el correo (R103): una línea, sin etiquetas ni nada que parezca un enlace. */
	private static function nombre_para_correo( WP_User $yo ): string {
		$nombre = wp_strip_all_tags( (string) ( ZN_API::nombre_publico( $yo ) ?? '' ), true );
		$nombre = preg_replace( '#(?:[a-z][a-z0-9+.-]*://|www\.)\S*#i', '', $nombre );
		$nombre = preg_replace( '#\b[\w-]+(?:\.[\w-]+)*\.[a-z]{2,}\b\S*#i', '', (string) $nombre );
		$nombre = trim( (string) preg_replace( '/\s+/u', ' ', (string) $nombre ) );
		return '' === $nombre ? 'Tu asociación' : mb_substr( $nombre, 0, 100 );
	}

	/** Cambia el nombre de una cuenta que gestiona (first_name, R53). Cuenta para el límite de escrituras. */
	public static function renombrar( WP_User $yo, int $id, string $nombre ): array {
		$cuenta = self::gestionable( $yo, $id );
		if ( ! $cuenta ) {
			return array( 'ok' => false, 'avisos' => array( 'No puedes editar esta cuenta.' ) );
		}
		if ( null !== ZN_Limite::intentar( 'escritura', $yo->user_email ) ) {
			return array( 'ok' => false, 'avisos' => array( 'Has hecho demasiados cambios seguidos. Espera un rato y vuelve a intentarlo.' ) );
		}
		$valores = array();
		$aviso   = self::comprobar_cuenta( 'name', $nombre, $valores );
		if ( $aviso ) {
			return array( 'ok' => false, 'avisos' => array( $aviso ) );
		}
		update_user_meta( $cuenta->ID, 'first_name', $valores['first_name'] );
		update_user_meta( $cuenta->ID, 'last_name', '' );
		return array( 'ok' => true, 'avisos' => array() );
	}

	/**
	 * Baja = desactivar (Luis, 2b): no entra en la app ni en el panel, se cierran sus sesiones (tokens y wp-admin) y
	 * sus negocios, novedades y sorteos pasan a borrador. No se borra nada.
	 */
	public static function desactivar( WP_User $yo, int $id ): bool {
		$cuenta = self::gestionable( $yo, $id, 'delete' );
		if ( ! $cuenta ) {
			return false;
		}
		update_user_meta( $cuenta->ID, 'zn_desactivada', '1' );
		ZN_Tokens::anular_todos( $cuenta->ID );
		WP_Session_Tokens::get_instance( $cuenta->ID )->destroy_all();
		$suyas = get_posts(
			array(
				'post_type'        => array_keys( self::PERMISOS ),
				'post_status'      => array( 'publish', 'future', 'pending', 'private' ),
				'meta_key'         => 'user_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => (string) $cuenta->ID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		foreach ( $suyas as $entrada ) {
			wp_update_post( array( 'ID' => (int) $entrada, 'post_status' => 'draft' ) );
		}
		return true;
	}

	/** Quita la baja (lo hace la administración). Sus cosas siguen en borrador: las publica la administración. */
	public static function reactivar( int $id ): bool {
		return self::desactivada( $id ) && delete_user_meta( $id, 'zn_desactivada' );
	}

	/** Enlace «Reactivar» en Usuarios, solo para quien puede editar usuarios y solo en cuentas dadas de baja. */
	public static function accion_reactivar( array $acciones, WP_User $cuenta ): array {
		if ( current_user_can( 'edit_users' ) && self::desactivada( $cuenta->ID ) ) {
			$url                      = wp_nonce_url( admin_url( 'admin-post.php?action=zn_reactivar&user=' . $cuenta->ID ), 'zn_reactivar_' . $cuenta->ID );
			$acciones['zn_reactivar'] = '<a href="' . esc_url( $url ) . '">Reactivar (dada de baja en el panel)</a>';
		}
		return $acciones;
	}

	public static function al_reactivar(): void {
		$id = absint( $_GET['user'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'zn_reactivar_' . $id );
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( 'No tienes permiso.', 403 );
		}
		self::reactivar( $id );
		wp_safe_redirect( admin_url( 'users.php' ) );
		exit;
	}

	/**
	 * Guarda lo enviado en el formulario (nombre guardado => texto; 'estado' => publish|draft). Como las fichas de
	 * wp-admin: lo que vale se guarda y lo que no, se avisa y se queda como estaba.
	 * Devuelve ['ok' => sin avisos, 'avisos' => textos en español].
	 */
	public static function guardar_edicion( WP_User $yo, int $id, array $datos ): array {
		$entrada = self::editable( $yo, $id );
		if ( ! $entrada ) {
			return array( 'ok' => false, 'avisos' => array( 'No puedes editar esto.' ) );
		}
		// El mismo límite de escrituras por cuenta que la app (R94), antes de mirar los datos.
		if ( null !== ZN_Limite::intentar( 'escritura', $yo->user_email ) ) {
			return array( 'ok' => false, 'avisos' => array( 'Has hecho demasiados cambios seguidos. Espera un rato y vuelve a intentarlo.' ) );
		}
		$tipo    = $entrada->post_type;
		$valores = array();
		$avisos  = array();
		foreach ( self::CAMPOS[ $tipo ] as $guardado => $casilla ) {
			if ( ! isset( $datos[ $guardado ] ) || ! is_string( $datos[ $guardado ] ) ) {
				continue;
			}
			$campo = ZN_Esquema::por_guardado( $tipo, $guardado );
			$valor = trim( $datos[ $guardado ] );
			if ( '' === $valor ) {
				if ( empty( $campo['obligatorio'] ) ) {
					$valores[ $guardado ] = null;
				} else {
					$avisos[] = ZN_Esquema::aviso( $campo, array( 'codigo' => 'required' ) );
				}
				continue;
			}
			if ( 'fecha' === $casilla ) {
				// Casilla de calendario: "2026-11-02T10:30".
				$fecha = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $valor );
				$valor = $fecha && $fecha->format( 'Y-m-d\TH:i' ) === $valor ? $fecha->format( 'Y-m-d H:i:s' ) : $valor;
			} elseif ( 'url' === $casilla && ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $valor ) ) {
				$valor = 'https://' . $valor;
			}
			$resultado = ZN_Esquema::comprobar( $campo, $valor, $campo['app'] );
			if ( isset( $resultado['errores'] ) ) {
				$avisos[] = ZN_Esquema::aviso( $campo, $resultado );
				continue;
			}
			$valores = array_merge( $valores, $resultado['valores'] );
		}

		// El fin no puede ir antes del inicio, con lo nuevo y lo guardado: la misma comprobación que el PATCH de la
		// API (ZN_Esquema::validar, R101). Si falla, no cambia ninguna de las dos fechas.
		$fechas = array_intersect_key( $valores, array( 'start_date' => 1, 'end_date' => 1 ) );
		if ( $fechas ) {
			$app      = array( 'start_date' => 'startDate', 'end_date' => 'endDate' );
			$guardado = array(
				'start_date' => (string) get_post_meta( $entrada->ID, 'start_date', true ),
				'end_date'   => (string) get_post_meta( $entrada->ID, 'end_date', true ),
			);
			$enviado = array();
			foreach ( $fechas as $clave => $fecha ) {
				$enviado[ $app[ $clave ] ] = $fecha;
			}
			$errores = ZN_Esquema::validar( $tipo, $enviado, $guardado )['errores'];
			if ( isset( $errores['startDate'] ) || isset( $errores['endDate'] ) ) {
				unset( $valores['start_date'], $valores['end_date'] );
				$avisos[] = 'La fecha de fin es anterior a la de inicio; no se han cambiado las fechas.';
			}
		}

		$extra = array();
		if ( isset( $datos['estado'] ) && '' !== $datos['estado'] ) {
			if ( ! is_string( $datos['estado'] ) || ! isset( self::ESTADOS_EDITABLES[ $datos['estado'] ] ) ) {
				$avisos[] = 'El estado solo puede ser «Publicado» o «Borrador»; no se ha cambiado.';
			} elseif ( 'publish' === $datos['estado'] && 'publish' !== $entrada->post_status ) {
				$avisos[] = 'Publicar lo hace la administración; sigue sin publicar.';
			} elseif ( $datos['estado'] !== $entrada->post_status ) {
				$extra['post_status'] = $datos['estado'];
			}
		}

		if ( $valores || $extra ) {
			$guardado = ZN_Esquema::guardar( $tipo, $entrada->ID, $valores, $extra );
			if ( is_wp_error( $guardado ) ) {
				$avisos[] = 'No se ha podido guardar. Vuelve a intentarlo.';
			}
		}
		return array( 'ok' => ! $avisos, 'avisos' => $avisos );
	}

	/**
	 * Si la app le deja borrarlo, a la papelera (se recupera desde wp-admin; R100, Luis). Si es un negocio, sus
	 * novedades publicadas pasan a borrador, para que no se queden apuntando a un negocio que ya no sale; sus reseñas
	 * y preguntas se quedan con él.
	 */
	public static function borrar( WP_User $yo, int $id ): bool {
		$entrada = self::editable( $yo, $id, 'delete' );
		if ( ! $entrada || ! wp_trash_post( $entrada->ID ) ) {
			return false;
		}
		if ( ZN_Negocios::TIPO === $entrada->post_type ) {
			$novedades = get_posts(
				array(
					'post_type'        => ZN_Eventos::TIPO,
					'post_status'      => array( 'publish', 'future', 'pending', 'private' ),
					'meta_key'         => 'business_id', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'       => (string) $entrada->ID, // phpcs:ignore WordPress.DB.SlowDBQuery
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
			foreach ( $novedades as $novedad ) {
				wp_update_post( array( 'ID' => (int) $novedad, 'post_status' => 'draft' ) );
			}
		}
		return true;
	}

	public static function url_editar( int $id ): string {
		return add_query_arg( 'editar', $id, self::url() );
	}

	public static function url_cuenta( $id ): string {
		return add_query_arg( 'cuenta', $id, self::url() );
	}

	// --- Participantes de sus sorteos (sorteos, parte 3) ---

	/** Página de participantes de un sorteo (sin «action»: R97). */
	public static function url_participantes( int $sorteo ): string {
		return add_query_arg( 'participantes', $sorteo, self::url() );
	}

	/** Direcciones del panel para ZN_Participaciones::html_lista(), con sello por acción y fila (R147). */
	public static function urls_participantes( int $sorteo ): array {
		$entrada = get_post( $sorteo );
		return $entrada ? ZN_Participaciones::urls_con_sello( $entrada, self::url_participantes( $sorteo ) ) : array();
	}

	/** Lista de participantes de un sorteo que organiza (R43: solo los suyos, sin herencia por el padre). */
	public static function html_participantes( WP_User $yo, int $sorteo ): string {
		$entrada = get_post( $sorteo );
		if ( ! self::puede_entrar( $yo ) || ! $entrada || ! ZN_Participaciones::puede_gestionar( $entrada, $yo->ID ) ) {
			return '<p>No puedes ver los participantes de este sorteo.</p>';
		}
		return '<h2>' . esc_html( 'Participantes: ' . get_the_title( $entrada ) ) . '</h2>'
			. ZN_Participaciones::html_lista( $entrada, self::urls_participantes( $sorteo ) );
	}

	/** Marcar ganador o entrega desde el panel; '' si se ha hecho, o el aviso. */
	public static function marcar_participante( WP_User $yo, int $sorteo, int $fila, string $hacer ): string {
		$entrada = get_post( $sorteo );
		if ( ! self::puede_entrar( $yo ) || ! $entrada ) {
			return 'No puedes cambiar esta participación.';
		}
		return ZN_Participaciones::marcar_desde_gestion( $entrada, $fila, $hacer, $yo->ID );
	}

	/** Foto, CSV o marca pedidos desde la página de participantes (GET con sello); termina con exit. */
	private static function procesar_participantes(): void {
		$sorteo = absint( $_GET['participantes'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_GET['ticket'] ) && ! isset( $_GET['csv'] ) && ! isset( $_GET['hacer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$entrada = get_post( $sorteo );
		if ( ! $entrada || ! ZN_Participaciones::sello_valido( $entrada, wp_unslash( $_GET ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- sello_valido() lo comprueba.
			wp_die( 'El enlace ha caducado. Vuelve a la página de participantes y prueba otra vez.', 403 );
		}
		if ( ! self::puede_entrar( wp_get_current_user() ) ) {
			wp_die( 'No puedes ver los participantes de este sorteo.', 403 );
		}
		ZN_Participaciones::atender( $entrada, wp_unslash( $_GET ), self::url_participantes( $sorteo ), 'zn_panel_avisos_' );
	}

	/** Al enviar el formulario (antes de pintar nada): comprueba el sello, guarda o borra y vuelve con GET. */
	public static function procesar(): void {
		if ( isset( $_GET['participantes'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::procesar_participantes();
			return;
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['zn_hacer'] ) ) { // phpcs:ignore WordPress.Security
			return;
		}
		if ( isset( $_GET['cuenta'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::procesar_cuenta();
			return;
		}
		if ( ! isset( $_GET['editar'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id = absint( $_GET['editar'] ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'zn_panel_' . $id );
		$yo    = wp_get_current_user();
		$hacer = sanitize_key( wp_unslash( $_POST['zn_hacer'] ) );
		if ( 'borrar' === $hacer ) {
			$avisos  = self::borrar( $yo, $id ) ? array() : array( 'No se ha podido borrar.' );
			$destino = $avisos ? self::url_editar( $id ) : add_query_arg( 'borrado', 1, self::url() );
		} else {
			$datos   = isset( $_POST['zn_panel'] ) && is_array( $_POST['zn_panel'] ) ? wp_unslash( $_POST['zn_panel'] ) : array();
			$avisos  = self::guardar_edicion( $yo, $id, $datos )['avisos'];
			$destino = add_query_arg( 'guardado', 1, self::url_editar( $id ) );
		}
		if ( $avisos ) {
			set_transient( 'zn_panel_avisos_' . $yo->ID, $avisos, 60 );
		}
		wp_safe_redirect( $destino );
		exit;
	}

	/** Alta (cuenta=nueva), renombrar o dar de baja (cuenta=<id>), con su sello; vuelve con GET. */
	private static function procesar_cuenta(): void {
		$cual = sanitize_key( wp_unslash( $_GET['cuenta'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$id   = 'nueva' === $cual ? 0 : absint( $cual );
		check_admin_referer( 'zn_panel_cuenta_' . ( $id ? $id : 'nueva' ) );
		$yo    = wp_get_current_user();
		$hacer = sanitize_key( wp_unslash( $_POST['zn_hacer'] ) );
		$datos = isset( $_POST['zn_cuenta'] ) && is_array( $_POST['zn_cuenta'] ) ? wp_unslash( $_POST['zn_cuenta'] ) : array();
		if ( ! $id && 'alta' === $hacer ) {
			$r       = self::dar_de_alta( $yo, $datos );
			$avisos  = $r['avisos'];
			$destino = $r['ok'] ? add_query_arg( 'alta', 1, self::url() ) : self::url_cuenta( 'nueva' );
		} elseif ( $id && 'baja' === $hacer ) {
			$avisos  = self::desactivar( $yo, $id ) ? array() : array( 'No se ha podido dar de baja.' );
			$destino = $avisos ? self::url_cuenta( $id ) : add_query_arg( 'baja', 1, self::url() );
		} elseif ( $id && 'guardar' === $hacer ) {
			$avisos  = self::renombrar( $yo, $id, is_string( $datos['name'] ?? null ) ? $datos['name'] : '' )['avisos'];
			$destino = add_query_arg( 'guardado', 1, self::url_cuenta( $id ) );
		} else {
			$avisos  = array( 'No se ha hecho nada.' );
			$destino = self::url();
		}
		if ( $avisos ) {
			set_transient( 'zn_panel_avisos_' . $yo->ID, $avisos, 60 );
		}
		wp_safe_redirect( $destino );
		exit;
	}

	public static function menu(): void {
		$pagina = add_menu_page( 'Mi zona', 'Mi zona', self::PERMISO, self::PAGINA, array( __CLASS__, 'pintar' ), 'dashicons-groups', 2 );
		if ( $pagina ) {
			add_action( "load-$pagina", array( __CLASS__, 'procesar' ) );
		}
		if ( ZN_Roles::tiene_rol_de_la_app( wp_get_current_user() ) ) {
			remove_menu_page( 'index.php' );
			remove_menu_page( 'profile.php' );
		}
	}

	/** Barra de arriba: sin enlaces al perfil, al escritorio ni a crear nada; solo «Salir». */
	public static function barra( WP_Admin_Bar $barra ): void {
		if ( ! ZN_Roles::tiene_rol_de_la_app( wp_get_current_user() ) ) {
			return;
		}
		foreach ( array( 'wp-logo', 'site-name', 'updates', 'comments', 'new-content', 'search', 'user-info', 'edit-profile' ) as $nodo ) {
			$barra->remove_node( $nodo );
		}
		$barra->add_node( array( 'id' => 'my-account', 'href' => false ) );
	}

	public static function pintar(): void {
		$yo = wp_get_current_user();
		if ( ! $yo->exists() || ! self::puede_entrar( $yo ) ) {
			return;
		}
		$cuentas = self::cuentas( $yo );
		// Nombre y apellidos (R53); display_name puede ser el nombre interno al azar de /register.
		$nombre  = static fn( WP_User $c ) => ZN_API::nombre_publico( $c ) ?? '';
		$nombres = array( $yo->ID => 'Tú' );
		foreach ( $cuentas as $c ) {
			$nombres[ $c->ID ] = $nombre( $c );
		}
		$dueno  = static fn( WP_Post $e ) => $nombres[ (int) get_post_meta( $e->ID, 'user_id', true ) ] ?? '';
		$estado = static fn( WP_Post $e ) => get_post_status_object( $e->post_status )->label ?? $e->post_status;
		$editar = static fn( array $entradas ) => array_map( static fn( WP_Post $e ) => self::editable( $yo, $e->ID ) ? self::url_editar( $e->ID ) : '', $entradas );
		?>
		<div class="wrap">
			<h1>Mi zona</h1>
			<?php self::mensajes( $yo ); ?>
			<?php if ( isset( $_GET['editar'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<?php self::pintar_edicion( $yo, absint( $_GET['editar'] ) ); // phpcs:ignore WordPress.Security.NonceVerification ?>
				</div>
				<?php return; ?>
			<?php endif; ?>
			<?php if ( isset( $_GET['cuenta'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<?php self::pintar_cuenta( $yo, sanitize_key( wp_unslash( $_GET['cuenta'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification ?>
				</div>
				<?php return; ?>
			<?php endif; ?>
			<?php if ( isset( $_GET['participantes'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<p><a href="<?php echo esc_url( self::url() ); ?>">← Volver a Mi zona</a></p>
				<?php echo self::html_participantes( $yo, absint( $_GET['participantes'] ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.EscapeOutput.OutputNotEscaped -- html_lista() lo escapa todo. ?>
				</div>
				<?php return; ?>
			<?php endif; ?>
			<p><?php echo esc_html( sprintf( '%s (%s). Aquí ves lo tuyo y lo de las cuentas que dependen de ti. Puedes editar lo mismo que en la app; lo demás, solo verlo.', $nombre( $yo ), ZN_Roles::NOMBRES[ $yo->roles[0] ] ?? '' ) ); ?></p>
			<?php if ( self::puede_dar_de_alta( $yo ) ) : ?>
				<p><a class="button" href="<?php echo esc_url( self::url_cuenta( 'nueva' ) ); ?>"><?php echo esc_html( 'zn_comercio' === self::ROL_HIJO[ ZN_Roles::rol_app( $yo ) ] ? 'Dar de alta un comercio' : 'Dar de alta una asociación' ); ?></a></p>
			<?php endif; ?>
			<?php
			$situacion = static function ( WP_User $c ): string {
				if ( self::desactivada( $c->ID ) ) {
					return 'Desactivada';
				}
				return '1' === (string) get_user_meta( $c->ID, 'zn_verificado', true ) ? 'Activa' : 'Sin verificar';
			};
			self::tabla(
				'Cuentas que dependen de ti',
				array( 'Nombre', 'Correo', 'Tipo', 'Depende de', 'Estado' ),
				array_map(
					// El correo, solo si los permisos de la app le dejan leer esa cuenta: la federación no ve el de los
					// comercios de sus asociaciones (decisión de Luis, ronda 24); ahí sale «—».
					static fn( WP_User $c ) => array( $nombre( $c ), ZN_Roles::puede( 'user:read', $c, $yo->ID ) ? $c->user_email : '', ZN_Roles::NOMBRES[ $c->roles[0] ] ?? '', $nombres[ ZN_Roles::padre( $c->ID ) ] ?? '', $situacion( $c ) ),
					$cuentas
				),
				array_map( static fn( WP_User $c ) => self::gestionable( $yo, $c->ID ) ? self::url_cuenta( $c->ID ) : '', $cuentas )
			);
			$negocios = self::entradas( $yo, ZN_Negocios::TIPO );
			self::tabla(
				'Negocios',
				array( 'Nombre', 'Dueño', 'Ciudad', 'Estado' ),
				array_map( static fn( WP_Post $e ) => array( $e->post_title, $dueno( $e ), (string) get_post_meta( $e->ID, 'city', true ), $estado( $e ) ), $negocios ),
				$editar( $negocios )
			);
			$novedades = self::entradas( $yo, ZN_Eventos::TIPO );
			self::tabla(
				'Novedades',
				array( 'Nombre', 'Dueño', 'Inicio', 'Fin', 'Estado' ),
				array_map( static fn( WP_Post $e ) => array( $e->post_title, $dueno( $e ), (string) get_post_meta( $e->ID, 'start_date', true ), (string) get_post_meta( $e->ID, 'end_date', true ), $estado( $e ) ), $novedades ),
				$editar( $novedades )
			);
			$sorteos = self::entradas( $yo, ZN_Sorteos::TIPO );
			self::tabla(
				'Sorteos y concursos',
				array( 'Título', 'Organiza', 'Inicio', 'Fin', 'Estado' ),
				array_map( static fn( WP_Post $e ) => array( $e->post_title, $dueno( $e ), (string) get_post_meta( $e->ID, 'start_date', true ), (string) get_post_meta( $e->ID, 'end_date', true ), $estado( $e ) ), $sorteos ),
				$editar( $sorteos )
			);
			// Participantes: solo de los sorteos que organiza él (R43), no los de sus cuentas.
			$propios = array_filter( $sorteos, static fn( WP_Post $e ) => ZN_Participaciones::puede_gestionar( $e, $yo->ID ) );
			if ( $propios ) {
				echo '<p>Participantes de tus sorteos: ';
				echo implode( ' · ', array_map( static fn( WP_Post $e ) => '<a href="' . esc_url( self::url_participantes( $e->ID ) ) . '">' . esc_html( $e->post_title ) . '</a>', $propios ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</p>';
			}
			?>
		</div>
		<?php
	}

	/** Avisos de la última vez que se guardó o borró (los deja procesar() antes de volver con GET). */
	private static function mensajes( WP_User $yo ): void {
		$avisos = get_transient( 'zn_panel_avisos_' . $yo->ID );
		delete_transient( 'zn_panel_avisos_' . $yo->ID );
		if ( is_array( $avisos ) && $avisos ) {
			echo '<div class="notice notice-warning"><p>' . implode( '<br>', array_map( 'esc_html', $avisos ) ) . '</p></div>';
		} elseif ( isset( $_GET['guardado'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>Cambios guardados.</p></div>';
		}
		if ( isset( $_GET['borrado'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>Borrado.</p></div>';
		}
		if ( isset( $_GET['alta'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>Cuenta creada. Le ha llegado un correo para poner su contraseña desde la app.</p></div>';
		}
		if ( isset( $_GET['baja'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>Cuenta dada de baja. Para reactivarla, pídeselo a la administración.</p></div>';
		}
	}

	/** Alta (cuenta=nueva) o una cuenta que gestiona: nombre y «Dar de baja». El correo no se cambia aquí. */
	private static function pintar_cuenta( WP_User $yo, string $cual ): void {
		$nueva  = 'nueva' === $cual;
		$cuenta = $nueva ? null : self::gestionable( $yo, absint( $cual ) );
		if ( $nueva ? ! self::puede_dar_de_alta( $yo ) : ! $cuenta ) {
			echo '<p>No puedes editar esta cuenta.</p><p><a href="' . esc_url( self::url() ) . '">Volver a Mi zona</a></p>';
			return;
		}
		$id     = $nueva ? 'nueva' : (string) $cuenta->ID;
		$tipo   = 'zn_comercio' === self::ROL_HIJO[ ZN_Roles::rol_app( $yo ) ] ? 'comercio' : 'asociación';
		$baja   = $cuenta && self::gestionable( $yo, $cuenta->ID, 'delete' );
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">← Volver a Mi zona</a></p>
		<h2><?php echo esc_html( $nueva ? "Dar de alta: $tipo" : 'Cuenta: ' . ( ZN_API::nombre_publico( $cuenta ) ?? '' ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( self::url_cuenta( $id ) ); ?>">
			<?php wp_nonce_field( 'zn_panel_cuenta_' . $id ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="zn-cuenta-nombre">Nombre *</label></th>
					<td><input type="text" class="regular-text" id="zn-cuenta-nombre" name="zn_cuenta[name]" maxlength="255" value="<?php echo esc_attr( $cuenta ? (string) ( ZN_API::nombre_publico( $cuenta ) ?? '' ) : '' ); ?>"></td>
				</tr>
				<tr>
					<th><?php echo $nueva ? '<label for="zn-cuenta-correo">Correo *</label>' : 'Correo'; ?></th>
					<td>
						<?php if ( $nueva ) : ?>
							<input type="email" class="regular-text" id="zn-cuenta-correo" name="zn_cuenta[email]" maxlength="100" value="">
							<p class="description">Le llegará un correo para poner su contraseña desde la app («Olvidé la contraseña»). Nadie más la conoce.</p>
						<?php else : ?>
							<?php echo esc_html( $cuenta->user_email ); ?>
							<p class="description">El correo solo lo cambia su dueño desde la app.</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" class="button button-primary" name="zn_hacer" value="<?php echo $nueva ? 'alta' : 'guardar'; ?>"><?php echo $nueva ? 'Dar de alta' : 'Guardar cambios'; ?></button>
				<?php if ( $baja ) : ?>
					<button type="submit" class="button button-link-delete" name="zn_hacer" value="baja" onclick="return confirm('<?php echo esc_js( '¿Dar de baja esta cuenta? No podrá entrar en la app y sus negocios, novedades y sorteos pasarán a borrador. Solo la administración puede reactivarla.' . ( 'asociación' === $tipo ? ' Sus comercios se quedarán sin nadie que los gestione en el panel hasta que la administración los pase a otra asociación.' : '' ) ); ?>');">Dar de baja</button>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/** Formulario de una entrada que puede guardar; si no puede, solo un aviso. */
	private static function pintar_edicion( WP_User $yo, int $id ): void {
		$entrada = self::editable( $yo, $id );
		if ( ! $entrada ) {
			echo '<p>No puedes editar esto.</p><p><a href="' . esc_url( self::url() ) . '">Volver a Mi zona</a></p>';
			return;
		}
		$tipo      = $entrada->post_type;
		$opciones  = array(
			'business_category_id' => ZN_Negocios::CATEGORIAS,
			'event_category_id'    => ZN_Eventos::CATEGORIAS,
		);
		$valor     = static function ( string $guardado ) use ( $entrada ): string {
			if ( in_array( $guardado, array( 'post_title', 'post_content' ), true ) ) {
				return (string) get_post_field( $guardado, $entrada->ID, 'raw' );
			}
			return (string) get_post_meta( $entrada->ID, $guardado, true );
		};
		$puede_borrar = (bool) self::editable( $yo, $id, 'delete' );
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">← Volver a Mi zona</a></p>
		<form method="post" action="<?php echo esc_url( self::url_editar( $entrada->ID ) ); ?>">
			<?php wp_nonce_field( 'zn_panel_' . $entrada->ID ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( self::CAMPOS[ $tipo ] as $guardado => $casilla ) : ?>
					<?php
					$campo  = ZN_Esquema::por_guardado( $tipo, $guardado );
					$nombre = 'zn_panel[' . $guardado . ']';
					$html   = 'zn-panel-' . $guardado;
					$maximo = isset( $campo['max'] ) ? (int) $campo['max'] : 0;
					?>
					<tr>
						<th><label for="<?php echo esc_attr( $html ); ?>"><?php echo esc_html( ucfirst( (string) preg_replace( '/^(El|La|Las|Los) /u', '', (string) $campo['etiqueta'] ) ) ); ?><?php echo empty( $campo['obligatorio'] ) ? '' : ' *'; ?></label></th>
						<td>
							<?php if ( 'parrafos' === $casilla ) : ?>
								<textarea id="<?php echo esc_attr( $html ); ?>" name="<?php echo esc_attr( $nombre ); ?>" rows="5" class="large-text"><?php echo esc_textarea( $valor( $guardado ) ); ?></textarea>
							<?php elseif ( 'opciones' === $casilla ) : ?>
								<select id="<?php echo esc_attr( $html ); ?>" name="<?php echo esc_attr( $nombre ); ?>">
									<option value="">—</option>
									<?php foreach ( $opciones[ $guardado ] as $clave => $texto ) : ?>
										<option value="<?php echo esc_attr( (string) $clave ); ?>" <?php selected( $valor( $guardado ), (string) $clave ); ?>><?php echo esc_html( $texto ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( 'fecha' === $casilla ) : ?>
								<input type="datetime-local" id="<?php echo esc_attr( $html ); ?>" name="<?php echo esc_attr( $nombre ); ?>" value="<?php echo esc_attr( $valor( $guardado ) ? str_replace( ' ', 'T', substr( $valor( $guardado ), 0, 16 ) ) : '' ); ?>">
							<?php elseif ( 'numero' === $casilla ) : ?>
								<input type="number" min="1" step="1" id="<?php echo esc_attr( $html ); ?>" name="<?php echo esc_attr( $nombre ); ?>" value="<?php echo esc_attr( $valor( $guardado ) ); ?>">
							<?php else : ?>
								<input type="text" class="regular-text" id="<?php echo esc_attr( $html ); ?>" name="<?php echo esc_attr( $nombre ); ?>"<?php echo $maximo ? ' maxlength="' . esc_attr( (string) $maximo ) . '"' : ''; ?> value="<?php echo esc_attr( $valor( $guardado ) ); ?>">
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th><label for="zn-panel-estado">Estado</label></th>
					<td>
						<select id="zn-panel-estado" name="zn_panel[estado]">
							<?php if ( ! isset( self::ESTADOS_EDITABLES[ $entrada->post_status ] ) ) : ?>
								<option value="" selected><?php echo esc_html( get_post_status_object( $entrada->post_status )->label ?? $entrada->post_status ); ?> (sin cambiar)</option>
							<?php endif; ?>
							<?php foreach ( self::ESTADOS_EDITABLES as $clave => $texto ) : ?>
								<?php if ( 'publish' !== $clave || 'publish' === $entrada->post_status ) : // Publicar, solo la administración (R99). ?>
									<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $entrada->post_status, $clave ); ?>><?php echo esc_html( $texto ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
						<p class="description">En borrador no sale en la app. Para volver a publicarlo hay que pedírselo a la administración.</p>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" class="button button-primary" name="zn_hacer" value="guardar">Guardar cambios</button>
				<?php if ( $puede_borrar ) : ?>
					<button type="submit" class="button button-link-delete" name="zn_hacer" value="borrar" onclick="return confirm('¿Borrar? Irá a la papelera; solo la administración puede recuperarlo.');">Borrar</button>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/** Tabla con todo el texto escapado; si se da $enlaces (misma posición que las filas), una columna «Editar». */
	private static function tabla( string $titulo, array $columnas, array $filas, array $enlaces = array() ): void {
		?>
		<h2><?php echo esc_html( $titulo ); ?> (<?php echo (int) count( $filas ); ?>)</h2>
		<?php if ( ! $filas ) : ?>
			<p>No hay ninguno.</p>
			<?php return; ?>
		<?php endif; ?>
		<?php if ( count( $filas ) >= self::MAXIMO ) : ?>
			<p class="description"><?php echo esc_html( sprintf( 'Se muestran los %d primeros.', self::MAXIMO ) ); ?></p>
		<?php endif; ?>
		<table class="widefat striped">
			<thead><tr>
				<?php foreach ( $columnas as $columna ) : ?>
					<th scope="col"><?php echo esc_html( $columna ); ?></th>
				<?php endforeach; ?>
				<?php if ( $enlaces ) : ?>
					<th scope="col"><span class="screen-reader-text">Editar</span></th>
				<?php endif; ?>
			</tr></thead>
			<tbody>
				<?php foreach ( $filas as $i => $fila ) : ?>
					<tr>
						<?php foreach ( $fila as $celda ) : ?>
							<td><?php echo esc_html( '' === (string) $celda ? '—' : (string) $celda ); ?></td>
						<?php endforeach; ?>
						<?php if ( $enlaces ) : ?>
							<td><?php echo empty( $enlaces[ $i ] ) ? '' : '<a href="' . esc_url( $enlaces[ $i ] ) . '">Editar</a>'; ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

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
 *   los permisos de la app): esas cuentas, y los negocios, novedades y sorteos de los que alguno es dueño.
 * - Nada se edita: ni enlaces ni formularios; tampoco su propio perfil de WordPress (edit_user denegado a todas
 *   las cuentas de la app). Los cambios, desde la app. La cookie del panel no vale en /zonanautico/v1 (ZN_Tokens).
 * - Entrar en la app no cierra su sesión del panel (R98, decisión de Luis); a las demás cuentas, sí (R39).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Panel {

	const PERMISO = 'zn_panel_ver';
	const PAGINA  = 'zn-panel';
	// Máximo de filas por tabla (las ramas son pequeñas; si se pasa, se avisa).
	const MAXIMO = 200;

	const ESTADOS = array( 'publish', 'future', 'draft', 'pending', 'private' );

	public static function iniciar(): void {
		add_filter( 'map_meta_cap', array( __CLASS__, 'sin_editar_cuentas' ), 10, 3 );
		add_filter( 'login_redirect', array( __CLASS__, 'al_entrar' ), 10, 3 );
		add_action( 'wp_login', array( __CLASS__, 'olvidar_intentos' ), 10, 2 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'sin_barra_publica' ) );
		add_action( 'admin_init', array( __CLASS__, 'vigilar' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 999 );
		// Después de todo lo de WordPress (la cuenta, «Hola, …», se añade con prioridad 9991).
		add_action( 'admin_bar_menu', array( __CLASS__, 'barra' ), PHP_INT_MAX );
	}

	/** ¿Puede entrar al panel? Asociación o federación (un único rol) con el correo verificado. */
	public static function puede_entrar( WP_User $cuenta ): bool {
		return in_array( ZN_Roles::rol_app( $cuenta ), array( 'association', 'federation' ), true )
			&& '1' === (string) get_user_meta( $cuenta->ID, 'zn_verificado', true );
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

	/** Cuentas que cuelgan directamente de él y puede leer (user:read_<rol>). */
	public static function cuentas( WP_User $yo ): array {
		if ( ! self::puede_entrar( $yo ) ) {
			return array();
		}
		$hijas = get_users(
			array(
				'role__in'   => array_keys( ZN_Roles::ROLES_APP ),
				'meta_key'   => 'zn_parent_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => (string) $yo->ID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'    => 'ID',
				'number'     => self::MAXIMO,
			)
		);
		return array_values( array_filter( $hijas, static fn( WP_User $hija ) => ZN_Roles::puede( 'user:read', $hija, $yo->ID ) ) );
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

	public static function menu(): void {
		add_menu_page( 'Mi zona', 'Mi zona', self::PERMISO, self::PAGINA, array( __CLASS__, 'pintar' ), 'dashicons-groups', 2 );
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
		?>
		<div class="wrap">
			<h1>Mi zona</h1>
			<p><?php echo esc_html( sprintf( '%s (%s). Aquí ves lo tuyo y lo de las cuentas que dependen de ti. Solo lectura: los cambios se hacen desde la app.', $nombre( $yo ), ZN_Roles::NOMBRES[ $yo->roles[0] ] ?? '' ) ); ?></p>
			<?php
			self::tabla(
				'Cuentas que dependen de ti',
				array( 'Nombre', 'Correo', 'Tipo', 'Correo verificado' ),
				array_map(
					static fn( WP_User $c ) => array( $nombre( $c ), $c->user_email, ZN_Roles::NOMBRES[ $c->roles[0] ] ?? '', '1' === (string) get_user_meta( $c->ID, 'zn_verificado', true ) ? 'Sí' : 'No' ),
					$cuentas
				)
			);
			self::tabla(
				'Negocios',
				array( 'Nombre', 'Dueño', 'Ciudad', 'Estado' ),
				array_map( static fn( WP_Post $e ) => array( $e->post_title, $dueno( $e ), (string) get_post_meta( $e->ID, 'city', true ), $estado( $e ) ), self::entradas( $yo, ZN_Negocios::TIPO ) )
			);
			self::tabla(
				'Novedades',
				array( 'Nombre', 'Dueño', 'Inicio', 'Fin', 'Estado' ),
				array_map( static fn( WP_Post $e ) => array( $e->post_title, $dueno( $e ), (string) get_post_meta( $e->ID, 'start_date', true ), (string) get_post_meta( $e->ID, 'end_date', true ), $estado( $e ) ), self::entradas( $yo, ZN_Eventos::TIPO ) )
			);
			self::tabla(
				'Sorteos y concursos',
				array( 'Título', 'Organiza', 'Inicio', 'Fin', 'Estado' ),
				array_map( static fn( WP_Post $e ) => array( $e->post_title, $dueno( $e ), (string) get_post_meta( $e->ID, 'start_date', true ), (string) get_post_meta( $e->ID, 'end_date', true ), $estado( $e ) ), self::entradas( $yo, ZN_Sorteos::TIPO ) )
			);
			?>
		</div>
		<?php
	}

	/** Tabla de solo lectura; todo el texto escapado. */
	private static function tabla( string $titulo, array $columnas, array $filas ): void {
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
			</tr></thead>
			<tbody>
				<?php foreach ( $filas as $fila ) : ?>
					<tr>
						<?php foreach ( $fila as $celda ) : ?>
							<td><?php echo esc_html( '' === (string) $celda ? '—' : (string) $celda ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

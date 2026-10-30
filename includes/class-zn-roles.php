<?php
/**
 * Roles de la app y permisos (R22).
 *
 * - Cuentas de la app: un rol de WordPress por cada rol del Laravel (ROLES_APP). Sus permisos son los
 *   del Laravel tal cual (Anexo A del traspaso), como capacidades `zn:<permiso>` (p. ej. `zn:business:save_own`).
 *   No tienen ningún permiso de WordPress (ni `read`): no pueden entrar a wp-admin ni por wp-login.php.
 * - «Lo suyo» (permisos `_own`) se decide con el dueño guardado en el elemento (`user_id`), nunca con
 *   el autor de la entrada: es suyo si el dueño es él o si el dueño cuelga de él (`parent_id`), como en
 *   el Laravel (federación → asociación → comercio, un nivel). Ver ZN_Roles::mapear().
 * - Negocios, novedades y sorteos tienen permisos de wp-admin propios (R3) que solo tiene el administrador.
 *
 * Se aplica una vez por versión del plugin (también sirve si el plugin ya estaba activado).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Roles {

	// Rol de WordPress => rol de la app (el `role` del UserResource del Laravel).
	const ROLES_APP = array(
		'zn_administrador' => 'administrator',
		'zn_federacion'    => 'federation',
		'zn_asociacion'    => 'association',
		'zn_comercio'      => 'commerce',
		'zn_usuario'       => 'user',
	);

	const NOMBRES = array(
		'zn_administrador' => 'Administración (app)',
		'zn_federacion'    => 'Federación (app)',
		'zn_asociacion'    => 'Asociación (app)',
		'zn_comercio'      => 'Comercio (app)',
		'zn_usuario'       => 'Usuario (app)',
	);

	// Permisos de cada rol, copiados del Anexo A del traspaso (tabla `roles` del Laravel).
	const PERMISOS = array(
		'administrator' => 'business:delete, business:delete_own, business:read, business:save, business:save_own, draw:delete, draw:delete_own, draw:read, draw:save, draw:save_own, draw_participation:delete, draw_participation:delete_own, draw_participation:export, draw_participation:export_own, draw_participation:read, draw_participation:save, draw_participation:save_own, event:delete, event:delete_own, event:read, event:save, event:save_own, faq:delete, faq:delete_own, faq:read, faq:save, faq:save_own, review:delete, review:delete_own, review:read, review:reply, review:reply_own, review:save, review:save_own, user:delete, user:delete_association, user:delete_commerce, user:delete_user, user:read, user:read_association, user:read_commerce, user:read_user, user:register-admin, user:register-association, user:register-commerce, user:register-federation, user:save, user:save_association, user:save_commerce, user:save_own, user:save_user',
		'federation'    => 'business:read, draw:delete_own, draw:read, draw:save_own, draw_participation:delete_own, draw_participation:export, draw_participation:read, draw_participation:save_own, event:delete_own, event:read, event:save_own, faq:read, faq:save, review:delete_own, review:read, review:save_own, user:delete_association, user:read_association, user:register-association, user:save_association, user:save_own',
		'association'   => 'business:delete_own, business:read, business:save_own, draw:delete_own, draw:read, draw:save_own, draw_participation:delete_own, draw_participation:export, draw_participation:read, draw_participation:save_own, event:delete_own, event:read, event:save_own, faq:delete_own, faq:read, faq:save, faq:save_own, review:delete_own, review:read, review:save_own, user:delete_commerce, user:read_commerce, user:read_user, user:register-commerce, user:save_commerce, user:save_own, user:save_user',
		'commerce'      => 'business:delete_own, business:read, business:save_own, draw:delete_own, draw:read, draw:save_own, draw_participation:delete_own, draw_participation:export_own, draw_participation:read, draw_participation:save_own, event:delete_own, event:read, event:save_own, faq:delete_own, faq:read, faq:save, faq:save_own, review:delete_own, review:read, review:reply_own, review:save_own, user:save_own',
		'user'          => 'business:read, draw:read, draw_participation:delete_own, draw_participation:read, draw_participation:save_own, event:read, faq:read, faq:save, review:delete_own, review:read, review:save_own, user:delete_self, user:save_own',
	);

	public static function iniciar(): void {
		// Después de registrar los tipos (init 10), que es cuando se conocen sus permisos.
		add_action( 'init', array( __CLASS__, 'actualizar' ), 20 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'mapear' ), 10, 4 );
	}

	public static function actualizar(): void {
		if ( get_option( 'zn_roles_version' ) === ZN_VERSION ) {
			return;
		}

		// Los roles de la app se rehacen enteros para que sus permisos sean siempre los de PERMISOS.
		foreach ( self::ROLES_APP as $rol_wp => $rol_app ) {
			remove_role( $rol_wp );
			add_role( $rol_wp, self::NOMBRES[ $rol_wp ], array_fill_keys( self::permisos( $rol_app ), true ) );
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( ZN_Negocios::TIPO, ZN_Eventos::TIPO, ZN_Sorteos::TIPO ) as $tipo ) {
				foreach ( self::permisos_del_tipo( $tipo ) as $permiso ) {
					$admin->add_cap( $permiso );
				}
			}
		}

		update_option( 'zn_roles_version', ZN_VERSION );
	}

	/** Capacidades `zn:…` de un rol de la app. */
	public static function permisos( string $rol_app ): array {
		return array_map( static fn( $p ) => 'zn:' . trim( $p ), explode( ',', self::PERMISOS[ $rol_app ] ) );
	}

	/**
	 * Comprobación de «puede hacer esto con este elemento» para las rutas de la app:
	 *   current_user_can( 'zn_puede', 'business:save', $dueno_del_negocio )
	 * Vale si tiene el permiso general (`zn:business:save`) o el `_own` y el elemento es suyo.
	 *
	 * @param string[] $caps
	 */
	public static function mapear( array $caps, string $cap, int $usuario, array $args ): array {
		if ( 'zn_puede' !== $cap ) {
			return $caps;
		}
		$permiso = (string) ( $args[0] ?? '' );
		$dueno   = (int) ( $args[1] ?? 0 );
		$cuenta  = get_userdata( $usuario );
		if ( ! $cuenta || '' === $permiso ) {
			return array( 'do_not_allow' );
		}
		if ( ! empty( $cuenta->allcaps[ "zn:$permiso" ] ) ) {
			return array( "zn:$permiso" );
		}
		if ( ! empty( $cuenta->allcaps[ "zn:{$permiso}_own" ] ) && self::es_suyo( $usuario, $dueno ) ) {
			return array( "zn:{$permiso}_own" );
		}
		return array( 'do_not_allow' );
	}

	/** El dueño es él, o el dueño cuelga de él (`parent_id`). */
	public static function es_suyo( int $usuario, int $dueno ): bool {
		return $dueno > 0 && ( $dueno === $usuario || (int) get_user_meta( $dueno, 'parent_id', true ) === $usuario );
	}

	/** Permisos «primitivos» de un tipo (edit_zn_negocios, publish_zn_negocios…), sin los que se calculan. */
	public static function permisos_del_tipo( string $tipo ): array {
		$permisos = (array) get_post_type_object( $tipo )->cap;
		unset( $permisos['edit_post'], $permisos['read_post'], $permisos['delete_post'], $permisos['read'] );
		return array_values( array_unique( $permisos ) );
	}

	/** Rol de la app de una cuenta, o null si no es una cuenta de la app (p. ej. un administrador de WordPress). */
	public static function rol_app( WP_User $usuario ): ?string {
		foreach ( self::ROLES_APP as $rol_wp => $rol_app ) {
			if ( in_array( $rol_wp, (array) $usuario->roles, true ) ) {
				return $rol_app;
			}
		}
		return null;
	}

	/** Cuentas de la app con un rol dado, para elegir dueño en wp-admin. */
	public static function cuentas( string $rol_wp ): array {
		return get_users( array( 'role' => $rol_wp, 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
	}
}

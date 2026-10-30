<?php
/**
 * Roles de la app y permisos de wp-admin.
 *
 * - Cuentas de la app: rol de WordPress propio por cada rol del Laravel. De momento solo «Comercio»
 *   (`zn_comercio` → `commerce`), sin ningún permiso: no pueden entrar a wp-admin. El resto llega con
 *   la fase de usuarios.
 * - Negocios, novedades y sorteos tienen permisos propios (R3) que solo tiene el administrador.
 *
 * Se aplica una vez por versión del plugin (también sirve si el plugin ya estaba activado).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Roles {

	// Rol de WordPress => rol de la app (el `role` del UserResource del Laravel).
	const ROLES_APP = array(
		'zn_comercio' => 'commerce',
	);

	public static function iniciar(): void {
		// Después de registrar los tipos (init 10), que es cuando se conocen sus permisos.
		add_action( 'init', array( __CLASS__, 'actualizar' ), 20 );
	}

	public static function actualizar(): void {
		if ( get_option( 'zn_roles_version' ) === ZN_VERSION ) {
			return;
		}

		if ( ! get_role( 'zn_comercio' ) ) {
			add_role( 'zn_comercio', 'Comercio (app)', array() );
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

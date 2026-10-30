<?php
/**
 * Roles de la app y permisos (R22).
 *
 * - Cuentas de la app: un rol de WordPress por cada rol del Laravel (ROLES_APP). Sus permisos son los
 *   del Laravel tal cual (Anexo A del traspaso), como capacidades `zn:<permiso>` (p. ej. `zn:business:save_own`).
 *   No tienen ningún permiso de WordPress (ni `read`): no pueden entrar a wp-admin ni por wp-login.php.
 * - Una cuenta de la app tiene exactamente un rol y ninguno más (R37); si no, no cuenta como cuenta de la
 *   app y no tiene ningún permiso `zn:`.
 * - «¿Puede hacer esto con este elemento?» se pregunta con ZN_Roles::puede(), que saca ella misma el dueño
 *   del elemento guardado (nunca de lo que envía la app, H15) y aplica las reglas del Laravel (R36): el
 *   permiso general, o el `_own` si es suyo; heredando por el padre (`zn_parent_id`) solo asociación y
 *   federación y solo en negocios, novedades y preguntas; en cuentas, «lo suyo» es solo uno mismo.
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
	}

	public static function actualizar(): void {
		if ( get_option( 'zn_roles_version' ) === ZN_VERSION ) {
			return;
		}

		// Los permisos de cada rol de la app quedan exactamente como en PERMISOS, sin borrar el rol: borrarlo
		// devolvería a «subscriber» el rol por defecto de WordPress si era este (R35).
		foreach ( self::ROLES_APP as $rol_wp => $rol_app ) {
			$rol   = get_role( $rol_wp ) ?? add_role( $rol_wp, self::NOMBRES[ $rol_wp ], array() );
			$deben = self::permisos( $rol_app );
			foreach ( array_diff( array_keys( $rol->capabilities ), $deben ) as $sobra ) {
				$rol->remove_cap( $sobra );
			}
			foreach ( array_diff( $deben, array_keys( array_filter( $rol->capabilities ) ) ) as $falta ) {
				$rol->add_cap( $falta );
			}
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

	// Recursos en los que el Laravel deja a la cuenta padre actuar sobre lo de sus hijas (business, event y faq;
	// en review y draw_participation, no: p. ej. un padre no puede responder reseñas del comercio hijo).
	const HEREDAN = array( 'business', 'event', 'faq' );

	// Roles que pueden heredar por el padre.
	const PADRES = array( 'association', 'federation' );

	/**
	 * ¿Puede la cuenta $usuario (por defecto, la actual) hacer $permiso (p. ej. 'business:save', sin `_own`)
	 * con este elemento? El dueño se saca del propio elemento:
	 * - WP_Post (negocio, novedad, sorteo): su campo `user_id`;
	 * - WP_Comment: reseña → quien la escribió (o, para `review:reply`, el dueño del negocio);
	 *   pregunta → el comercio destinatario (`user_id` del comentario);
	 * - WP_User: la propia cuenta (reglas de cuentas, ver puede_con_cuenta());
	 * - null: solo vale el permiso general (crear algo sin dueño todavía).
	 *
	 * @param WP_Post|WP_Comment|WP_User|null $elemento
	 */
	public static function puede( string $permiso, $elemento = null, ?int $usuario = null ): bool {
		$cuenta = get_userdata( $usuario ?? get_current_user_id() );
		if ( ! $cuenta || ! self::rol_app( $cuenta ) || ! preg_match( '/^([a-z_]+):([a-z_-]+)$/', $permiso, $partes ) ) {
			return false;
		}
		if ( self::tiene( $cuenta, $permiso ) ) {
			return true;
		}
		if ( $elemento instanceof WP_User ) {
			return self::puede_con_cuenta( $cuenta, $partes[2], $elemento );
		}
		$dueno = self::dueno_de( $permiso, $elemento );
		if ( ! $dueno || ! self::tiene( $cuenta, "{$permiso}_own" ) ) {
			return false;
		}
		if ( $dueno === $cuenta->ID ) {
			return true;
		}
		return in_array( $partes[1], self::HEREDAN, true )
			&& in_array( self::rol_app( $cuenta ), self::PADRES, true )
			&& self::padre( $dueno ) === $cuenta->ID;
	}

	/**
	 * Permisos sobre cuentas (user:save, user:delete, user:read), como el UserController del Laravel:
	 * - uno mismo: user:save_own, user:delete_self (leerse a sí mismo, siempre);
	 * - otra cuenta: user:<acción>_<rol de esa cuenta> (association, commerce, user) y solo si cuelga de él.
	 *   Más estricto que el Laravel, que también deja tocar cuentas de ese rol que no cuelgan de nadie.
	 */
	private static function puede_con_cuenta( WP_User $cuenta, string $accion, WP_User $destino ): bool {
		if ( $destino->ID === $cuenta->ID ) {
			$propio = array( 'save' => 'user:save_own', 'delete' => 'user:delete_self', 'read' => null );
			return array_key_exists( $accion, $propio ) && ( null === $propio[ $accion ] || self::tiene( $cuenta, $propio[ $accion ] ) );
		}
		$rol = self::rol_app( $destino );
		return $rol
			&& in_array( $accion, array( 'save', 'delete', 'read' ), true )
			&& self::tiene( $cuenta, "user:{$accion}_{$rol}" )
			&& self::padre( $destino->ID ) === $cuenta->ID;
	}

	/** Dueño guardado del elemento para ese permiso; 0 si no tiene. */
	private static function dueno_de( string $permiso, $elemento ): int {
		if ( $elemento instanceof WP_Post ) {
			return (int) get_post_meta( $elemento->ID, 'user_id', true );
		}
		if ( $elemento instanceof WP_Comment ) {
			if ( ZN_Comentarios::PREGUNTA === $elemento->comment_type ) {
				return (int) get_comment_meta( (int) $elemento->comment_ID, 'user_id', true );
			}
			if ( 'review:reply' === $permiso ) {
				return ZN_Negocios::dueno( (int) $elemento->comment_post_ID );
			}
			return (int) $elemento->user_id;
		}
		return 0;
	}

	/** ¿Tiene la cuenta este permiso del Laravel? Solo por su único rol de la app, nunca sumando roles (R37). */
	private static function tiene( WP_User $cuenta, string $permiso ): bool {
		$rol = self::rol_app( $cuenta );
		return $rol && in_array( "zn:$permiso", self::permisos( $rol ), true );
	}

	/** Cuenta de la que cuelga (federación → asociación → comercio); 0 si ninguna. */
	public static function padre( int $usuario ): int {
		return (int) get_user_meta( $usuario, 'zn_parent_id', true );
	}

	/** Permisos «primitivos» de un tipo (edit_zn_negocios, publish_zn_negocios…), sin los que se calculan. */
	public static function permisos_del_tipo( string $tipo ): array {
		$permisos = (array) get_post_type_object( $tipo )->cap;
		unset( $permisos['edit_post'], $permisos['read_post'], $permisos['delete_post'], $permisos['read'] );
		return array_values( array_unique( $permisos ) );
	}

	/**
	 * Rol de la app de una cuenta, o null si no es una cuenta de la app (p. ej. un administrador de WordPress).
	 * Solo cuenta si tiene exactamente un rol y es de la app (R37).
	 */
	public static function rol_app( WP_User $usuario ): ?string {
		$roles = array_values( (array) $usuario->roles );
		return 1 === count( $roles ) ? ( self::ROLES_APP[ $roles[0] ] ?? null ) : null;
	}

	/** ¿Tiene algún rol de la app, aunque sea mezclado con otros? (para cerrarle wp-admin igualmente). */
	public static function tiene_rol_de_la_app( WP_User $usuario ): bool {
		return (bool) array_intersect( (array) $usuario->roles, array_keys( self::ROLES_APP ) );
	}

	/** Cuentas de la app con un rol dado, para elegir dueño en wp-admin. */
	public static function cuentas( string $rol_wp ): array {
		return get_users( array( 'role' => $rol_wp, 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
	}
}

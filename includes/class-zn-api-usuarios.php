<?php
/**
 * GET /user/{id} (privado, con el token de la app). La app lo pide al arrancar para comprobar que la
 * sesión guardada sigue valiendo (Zona-Nautico/src/auth/sesion.ts) y para cargar el perfil.
 *
 * Responde como el UserResource del Laravel con token: { data: { _id, id, name, email, verified, role,
 * parent, avatar, latitude, longitude, phone } }, con `id` numérico (src/api/user.ts).
 * - Sin sesión o con un token que no vale: 401 «Unauthenticated.» (la app cierra la sesión).
 * - Con sesión pero sin permiso para ver esa cuenta: 401 «This action is unauthorized.».
 * - Cuenta que no existe o que no es de la app: 404.
 * Quién ve a quién: ZN_Roles::puede( 'user:read', $cuenta ) (uno mismo, la administración de la app o
 * la cuenta padre con user:read_<rol>).
 */

defined( 'ABSPATH' ) || exit;

class ZN_API_Usuarios {

	public static function registrar_rutas(): void {
		register_rest_route(
			ZN_NAMESPACE,
			'/user/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ficha' ),
				// En la entrada, no solo dentro (R51): sin sesión de una cuenta de la app no se ejecuta nada.
				'permission_callback' => array( 'ZN_API', 'con_sesion_de_la_app' ),
			)
		);
	}

	public static function ficha( WP_REST_Request $peticion ): WP_REST_Response {
		if ( ! is_user_logged_in() || ! ZN_Roles::rol_app( wp_get_current_user() ) ) {
			return ZN_Errores::no_autenticado();
		}
		$cuenta = get_userdata( (int) $peticion['id'] );
		if ( ! $cuenta || ! ZN_Roles::rol_app( $cuenta ) ) {
			return ZN_API::no_encontrado();
		}
		if ( ! ZN_Roles::puede( 'user:read', $cuenta ) ) {
			return ZN_Errores::sin_permiso();
		}
		return new WP_REST_Response( array( 'data' => self::formatear( $cuenta ) ) );
	}

	/** Cuenta de la app → objeto con los campos del UserResource del Laravel (con token). */
	public static function formatear( WP_User $cuenta, bool $con_padre = true ): array {
		$campo = static function ( string $clave ) use ( $cuenta ): ?string {
			$valor = get_user_meta( $cuenta->ID, $clave, true );
			return '' === $valor ? null : (string) $valor;
		};
		$datos = array(
			'_id'      => (string) $cuenta->ID,
			'id'       => (int) $cuenta->ID,
			'name'     => ZN_API::nombre_publico( $cuenta ) ?? '',
			'email'    => $cuenta->user_email,
			// Se rellenará con la verificación de correo (fase de login).
			'verified' => '1' === $campo( 'zn_verificado' ),
			'role'     => ZN_Roles::rol_app( $cuenta ),
		);
		if ( $con_padre ) {
			$padre           = ZN_Roles::padre( $cuenta->ID ) ? get_userdata( ZN_Roles::padre( $cuenta->ID ) ) : null;
			$datos['parent'] = $padre && ZN_Roles::rol_app( $padre )
				? array_intersect_key( self::formatear( $padre, false ), array_flip( array( '_id', 'id', 'name', 'email', 'role' ) ) )
				: null;
		}
		$datos['avatar']    = null;
		$datos['latitude']  = ZN_API::numero( $campo( 'latitude' ) );
		$datos['longitude'] = ZN_API::numero( $campo( 'longitude' ) );
		$datos['phone']     = $campo( 'phone' );
		return $datos;
	}
}

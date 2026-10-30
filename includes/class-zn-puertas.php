<?php
/**
 * Cierra las puertas estándar de WordPress que la app no usa y que dejan ver cuentas o probar
 * contraseñas (R2, R33): XML-RPC, contraseñas de aplicación, las páginas de autor, el mapa del sitio,
 * oEmbed, los feeds y las listas públicas de usuarios y de medios.
 * Este WordPress es solo para la app: todo lo público va por /zonanautico/v1.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Puertas {

	public static function iniciar(): void {
		// XML-RPC entero fuera (también system.listMethods y pingbacks, no solo los métodos con contraseña).
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			add_action( 'init', array( __CLASS__, 'rechazar_xmlrpc' ), 0 );
		}

		// ?author=1 y /author/<nombre>/ delatan el nombre de acceso: a la portada.
		add_action( 'template_redirect', array( __CLASS__, 'sin_paginas_de_autor' ), 0 );

		// Mapa del sitio, oEmbed y feeds también delatan autores (wp-sitemap-users, author_name, dc:creator).
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		add_filter( 'embed_oembed_discover', '__return_false' );
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		foreach ( array( 'do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom', 'do_feed_rss2_comments', 'do_feed_atom_comments' ) as $feed ) {
			add_action( $feed, array( __CLASS__, 'sin_feeds' ), 0 );
		}

		// Contraseñas de aplicación fuera: su comprobación responde distinto si la cuenta no existe o si la
		// contraseña es mala, y delataría qué cuentas hay (R33). La app entrará con su propio token.
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		// Las cuentas de la app solo entran por la app: ni wp-login.php ni su «¿Has olvidado tu contraseña?» (R22).
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'sin_panel_para_la_app' ) );
		add_filter( 'allow_password_reset', array( __CLASS__, 'sin_recuperar_en_el_panel' ), 10, 2 );
		// Y el formulario de wp-login.php no dice si una cuenta existe: el mismo aviso para todo.
		add_filter( 'login_errors', array( __CLASS__, 'aviso_generico' ) );

		add_filter( 'rest_endpoints', array( __CLASS__, 'quitar_rutas' ) );
	}

	const AVISO_ACCESO = '<strong>Error:</strong> los datos de acceso no son correctos.';

	/** @param WP_User|WP_Error $usuario */
	public static function sin_panel_para_la_app( $usuario ) {
		if ( $usuario instanceof WP_User && ZN_Roles::tiene_rol_de_la_app( $usuario ) ) {
			return new WP_Error( 'zn_solo_app', self::AVISO_ACCESO );
		}
		return $usuario;
	}

	public static function sin_recuperar_en_el_panel( $permitido, $id ) {
		$usuario = get_userdata( (int) $id );
		return $usuario && ZN_Roles::tiene_rol_de_la_app( $usuario ) ? false : $permitido;
	}

	public static function aviso_generico( $aviso ) {
		return '' === trim( (string) $aviso ) ? $aviso : self::AVISO_ACCESO;
	}

	public static function sin_feeds(): void {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}

	public static function rechazar_xmlrpc(): void {
		status_header( 403 );
		exit;
	}

	public static function sin_paginas_de_autor(): void {
		// Incluye la vista «embed» de cualquier entrada (?embed=true), que también enseña el autor.
		if ( is_author() || is_embed() || isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/** /wp/v2/users y /wp/v2/media solo para quien ya puede ver usuarios o subir archivos en wp-admin. */
	public static function quitar_rutas( array $rutas ): array {
		$cerradas = array(
			'/wp/v2/users' => 'list_users',
			'/wp/v2/media' => 'upload_files',
		);
		foreach ( $cerradas as $prefijo => $permiso ) {
			if ( current_user_can( $permiso ) ) {
				continue;
			}
			foreach ( array_keys( $rutas ) as $ruta ) {
				if ( 0 === strpos( $ruta, $prefijo ) ) {
					unset( $rutas[ $ruta ] );
				}
			}
		}
		return $rutas;
	}
}

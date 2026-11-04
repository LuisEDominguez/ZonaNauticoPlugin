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
		// Salvo asociación y federación, que entran a su panel (R40, ver ZN_Panel); recuperar, también por la app.
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'sin_panel_para_la_app' ) );
		add_filter( 'allow_password_reset', array( __CLASS__, 'sin_recuperar_en_el_panel' ), 10, 2 );
		// Y el formulario de wp-login.php no dice si una cuenta existe: el mismo aviso para todo.
		add_filter( 'login_errors', array( __CLASS__, 'aviso_generico' ) );

		// Nadie se registra por wp-login.php (solo por la app), y el rol por defecto nunca es de la app (R60).
		add_filter( 'pre_option_users_can_register', static fn() => '0' );
		add_filter( 'option_default_role', static fn( $rol ) => isset( ZN_Roles::ROLES_APP[ $rol ] ) ? 'subscriber' : $rol );
		add_filter( 'pre_update_option_default_role', static fn( $nuevo, $antes ) => isset( ZN_Roles::ROLES_APP[ $nuevo ] ) ? $antes : $nuevo, 10, 2 );

		// Correos (R57): ni «tu contraseña ha cambiado» ni «tu correo ha cambiado» a las cuentas de la app
		// (lo avisará la app), y fuera de producción ningún correo sale a una persona real.
		add_filter( 'send_password_change_email', array( __CLASS__, 'sin_aviso_de_wordpress' ), 10, 2 );
		add_filter( 'send_email_change_email', array( __CLASS__, 'sin_aviso_de_wordpress' ), 10, 2 );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'correo_solo_de_prueba' ), 10, 2 );
		// Tampoco avisos automáticos de WordPress que la app no usa: «nuevo usuario» al administrador y
		// comentarios nuevos o pendientes (las reseñas y preguntas son comentarios). R57-bis.
		add_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );
		add_filter( 'pre_option_comments_notify', static fn() => '0' );
		add_filter( 'pre_option_moderation_notify', static fn() => '0' );

		add_filter( 'rest_endpoints', array( __CLASS__, 'quitar_rutas' ) );
	}

	const AVISO_ACCESO = '<strong>Error:</strong> los datos de acceso no son correctos.';

	/**
	 * Va antes de comprobar la contraseña. Una cuenta de la app solo pasa si puede entrar al panel (R40), y con
	 * el mismo límite de intentos que POST /login (cuentan juntos); si no, el aviso de siempre.
	 *
	 * @param WP_User|WP_Error $usuario
	 */
	public static function sin_panel_para_la_app( $usuario ) {
		if ( ! $usuario instanceof WP_User || ! ZN_Roles::tiene_rol_de_la_app( $usuario ) ) {
			return $usuario;
		}
		if ( ! ZN_Panel::puede_entrar( $usuario ) || null !== ZN_Limite::intentar( 'login', $usuario->user_email ) ) {
			return new WP_Error( 'zn_solo_app', self::AVISO_ACCESO );
		}
		return $usuario;
	}

	public static function sin_recuperar_en_el_panel( $permitido, $id ) {
		$usuario = get_userdata( (int) $id );
		return $usuario && ZN_Roles::tiene_rol_de_la_app( $usuario ) ? false : $permitido;
	}

	/** @param bool $enviar @param array $usuario Datos de la cuenta antes del cambio. */
	public static function sin_aviso_de_wordpress( $enviar, $usuario ) {
		$cuenta = get_userdata( (int) ( $usuario['ID'] ?? 0 ) );
		return $cuenta && ZN_Roles::tiene_rol_de_la_app( $cuenta ) ? false : $enviar;
	}

	/**
	 * Interruptor de correo (R57, R57-bis). Por defecto NO sale ningún correo a personas reales: solo a
	 * direcciones inventadas (@ejemplo.invalid, que en Local recoge Mailpit). Para enviar correos de verdad
	 * hace falta, en el servidor de producción, `define( 'ZN_CORREO_REAL', true );` en wp-config.php y que el
	 * entorno sea `production`. Si se olvida, falla en seguro: no sale nada.
	 * Se miran el destinatario y también las copias (Cc y Bcc de las cabeceras).
	 *
	 * @param null|bool $resultado
	 */
	public static function correo_solo_de_prueba( $resultado, array $correo ) {
		if ( null !== $resultado ) {
			return $resultado;
		}
		if ( self::correo_real_activado( defined( 'ZN_CORREO_REAL' ), defined( 'ZN_CORREO_REAL' ) ? ZN_CORREO_REAL : null, wp_get_environment_type() ) ) {
			return $resultado;
		}
		foreach ( self::destinatarios( $correo ) as $direccion ) {
			if ( ! preg_match( '/@ejemplo\.invalid$/', $direccion ) ) {
				return false;
			}
		}
		return $resultado;
	}

	/**
	 * La regla del interruptor, aparte para poder probarla también para producción (en Local el entorno no se
	 * puede cambiar): solo con la constante definida, a true (ni «1» ni 1) y con el entorno `production`.
	 *
	 * @param mixed $valor
	 */
	public static function correo_real_activado( bool $definida, $valor, string $entorno ): bool {
		return $definida && true === $valor && 'production' === $entorno;
	}

	/** Todas las direcciones de un correo de wp_mail: to, Cc y Bcc, en minúsculas y sin «Nombre <…>». */
	public static function destinatarios( array $correo ): array {
		$lista = (array) ( is_array( $correo['to'] ?? null ) ? $correo['to'] : explode( ',', (string) ( $correo['to'] ?? '' ) ) );
		$cabeceras = $correo['headers'] ?? array();
		$cabeceras = is_array( $cabeceras ) ? $cabeceras : explode( "\n", str_replace( "\r\n", "\n", (string) $cabeceras ) );
		foreach ( $cabeceras as $cabecera ) {
			if ( preg_match( '/^\s*(cc|bcc)\s*:(.*)$/i', (string) $cabecera, $m ) ) {
				$lista = array_merge( $lista, explode( ',', $m[2] ) );
			}
		}
		$direcciones = array();
		foreach ( $lista as $item ) {
			$item = strtolower( trim( (string) $item ) );
			if ( preg_match( '/<([^>]+)>/', $item, $m ) ) {
				$item = trim( $m[1] );
			}
			if ( '' !== $item ) {
				$direcciones[] = $item;
			}
		}
		return $direcciones;
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

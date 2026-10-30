<?php
/**
 * Cierra las puertas estándar de WordPress que la app no usa y que dejan ver cuentas o probar
 * contraseñas (R2): XML-RPC, las páginas de autor y las listas públicas de usuarios y de medios.
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

		add_filter( 'rest_endpoints', array( __CLASS__, 'quitar_rutas' ) );
	}

	public static function rechazar_xmlrpc(): void {
		status_header( 403 );
		exit;
	}

	public static function sin_paginas_de_autor(): void {
		if ( is_author() || isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

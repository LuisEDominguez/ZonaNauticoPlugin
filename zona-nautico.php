<?php
/**
 * Plugin Name: Zona Náutico
 * Description: API de la app Zona Náutico. Imita el contrato de la API Laravel actual ({data, links, meta}) para no rehacer la app.
 * Version:     0.4.0
 * Author:      Visual Publinet
 * Text Domain: zona-nautico
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

const ZN_VERSION   = '0.4.0';
const ZN_NAMESPACE = 'zonanautico/v1';

require_once __DIR__ . '/includes/class-zn-negocios.php';
require_once __DIR__ . '/includes/class-zn-api-negocios.php';

add_action( 'init', array( 'ZN_Negocios', 'registrar' ) );
add_action( 'rest_api_init', array( 'ZN_API_Negocios', 'registrar_rutas' ) );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-zn-admin-negocios.php';
	ZN_Admin_Negocios::iniciar();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-zn-cli.php';
	WP_CLI::add_command( 'zona-nautico', 'ZN_CLI' );
}

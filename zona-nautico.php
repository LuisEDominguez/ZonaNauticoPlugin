<?php
/**
 * Plugin Name: Zona Náutico
 * Description: API de la app Zona Náutico. Imita el contrato de la API Laravel actual ({data, links, meta}) para no rehacer la app.
 * Version:     0.2.0
 * Author:      Visual Publinet
 * Text Domain: zona-nautico
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

const ZN_VERSION   = '0.2.0';
const ZN_NAMESPACE = 'zonanautico/v1';

require_once __DIR__ . '/includes/class-zn-instalador.php';
require_once __DIR__ . '/includes/class-zn-api-negocios.php';

register_activation_hook( __FILE__, array( 'ZN_Instalador', 'activar' ) );

// Si se actualiza el plugin sin desactivarlo, la tabla se pone al día igualmente.
add_action( 'plugins_loaded', array( 'ZN_Instalador', 'actualizar_si_hace_falta' ) );
add_action( 'rest_api_init', array( 'ZN_API_Negocios', 'registrar_rutas' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-zn-cli.php';
	WP_CLI::add_command( 'zona-nautico', 'ZN_CLI' );
}

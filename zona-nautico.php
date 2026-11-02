<?php
/**
 * Plugin Name: Zona Náutico
 * Description: API de la app Zona Náutico. Imita el contrato de la API Laravel actual ({data, links, meta}) para no rehacer la app.
 * Version:     0.16.0
 * Author:      Visual Publinet
 * Text Domain: zona-nautico
 * Requires at least: 6.8
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

const ZN_VERSION   = '0.16.0';
const ZN_NAMESPACE = 'zonanautico/v1';

require_once __DIR__ . '/includes/class-zn-api.php';
require_once __DIR__ . '/includes/class-zn-errores.php';
require_once __DIR__ . '/includes/class-zn-esquema.php';
require_once __DIR__ . '/includes/class-zn-limite.php';
require_once __DIR__ . '/includes/class-zn-puertas.php';
require_once __DIR__ . '/includes/class-zn-roles.php';
require_once __DIR__ . '/includes/class-zn-tokens.php';
require_once __DIR__ . '/includes/class-zn-negocios.php';
require_once __DIR__ . '/includes/class-zn-eventos.php';
require_once __DIR__ . '/includes/class-zn-sorteos.php';
require_once __DIR__ . '/includes/class-zn-comentarios.php';
require_once __DIR__ . '/includes/class-zn-privacidad.php';
require_once __DIR__ . '/includes/class-zn-api-negocios.php';
require_once __DIR__ . '/includes/class-zn-api-eventos.php';
require_once __DIR__ . '/includes/class-zn-api-sorteos.php';
require_once __DIR__ . '/includes/class-zn-api-resenas.php';
require_once __DIR__ . '/includes/class-zn-api-preguntas.php';
require_once __DIR__ . '/includes/class-zn-api-usuarios.php';
require_once __DIR__ . '/includes/class-zn-api-sesion.php';
require_once __DIR__ . '/includes/class-zn-api-cuenta.php';

add_action( 'init', array( 'ZN_Negocios', 'registrar' ) );
add_action( 'init', array( 'ZN_Eventos', 'registrar' ) );
add_action( 'init', array( 'ZN_Sorteos', 'registrar' ) );
ZN_Comentarios::iniciar();
ZN_Privacidad::iniciar();
ZN_Puertas::iniciar();
ZN_Errores::iniciar();
ZN_Roles::iniciar();
ZN_Tokens::iniciar();
ZN_Limite::iniciar();
add_action( 'rest_api_init', array( 'ZN_API_Negocios', 'registrar_rutas' ) );
add_action( 'rest_api_init', array( 'ZN_API_Eventos', 'registrar_rutas' ) );
add_action( 'rest_api_init', array( 'ZN_API_Sorteos', 'registrar_rutas' ) );
add_action( 'rest_api_init', array( 'ZN_API_Resenas', 'registrar_rutas' ) );
add_action( 'rest_api_init', array( 'ZN_API_Preguntas', 'registrar_rutas' ) );
add_action( 'rest_api_init', array( 'ZN_API_Usuarios', 'registrar_rutas' ) );
add_action( 'rest_api_init', array( 'ZN_API_Sesion', 'registrar_rutas' ) );
ZN_API_Cuenta::iniciar();

// Las tablas de tokens y de intentos se crean al activar el plugin (y se comprueban en cada carga por si se
// actualiza sin reactivar).
register_activation_hook(
	__FILE__,
	static function () {
		ZN_Tokens::instalar();
		ZN_Limite::instalar();
	}
);

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-zn-admin-campos.php';
	require_once __DIR__ . '/includes/class-zn-admin-negocios.php';
	require_once __DIR__ . '/includes/class-zn-admin-eventos.php';
	require_once __DIR__ . '/includes/class-zn-admin-sorteos.php';
	ZN_Admin_Campos::iniciar();
	ZN_Admin_Negocios::iniciar();
	ZN_Admin_Eventos::iniciar();
	ZN_Admin_Sorteos::iniciar();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-zn-cli.php';
	WP_CLI::add_command( 'zona-nautico', 'ZN_CLI' );
}

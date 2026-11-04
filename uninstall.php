<?php
/**
 * Al borrar el plugin desde wp-admin (Plugins › Borrar), WordPress ejecuta este archivo sin cargar el plugin.
 * Deja WordPress limpio (R68): tablas, opciones, avisos temporales, roles de la app y capacidades. El contenido y las
 * cuentas se quedan (ver ZN_Instalacion).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Solo las piezas con los nombres de lo que hay que borrar.
require_once __DIR__ . '/includes/class-zn-roles.php';
require_once __DIR__ . '/includes/class-zn-tokens.php';
require_once __DIR__ . '/includes/class-zn-limite.php';
require_once __DIR__ . '/includes/class-zn-favoritos.php';
require_once __DIR__ . '/includes/class-zn-participaciones.php';
require_once __DIR__ . '/includes/class-zn-instalacion.php';

ZN_Instalacion::desinstalar();

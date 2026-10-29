<?php
// Comprobaciones de la ficha de wp-admin. Uso: wp eval-file tests/admin-negocios.php
require_once WP_PLUGIN_DIR . '/zona-nautico/includes/class-zn-admin-negocios.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

$e = array();
$l = ZN_Admin_Negocios::limpiar(
	array(
		'business_category_id' => '3', 'address' => '  Rúa X 1  ', 'country' => 'es', 'phone' => '<b>600</b>',
		'email' => 'a@b.es', 'web' => 'ejemplo.com', 'schedule' => "lunes\t10:00\r\nmartes: <script>x</script>cerrado",
		'latitude' => '42,2388906', 'longitude' => '-8.7259422', 'photo_0' => 'https://x.es/a.jpg', 'photo_1' => 'javascript:alert(1)',
	),
	$e
);
$ok( 'sin avisos con datos buenos', ! $e );
$ok( 'categoría válida', 3 === $l['business_category_id'] );
$ok( 'recorta espacios', 'Rúa X 1' === $l['address'] );
$ok( 'país en mayúsculas', 'ES' === $l['country'] );
$ok( 'quita HTML', '600' === $l['phone'] );
$ok( 'web sin protocolo → https', 'https://ejemplo.com' === $l['web'] );
$ok( 'horario: tabulador → «: » y sin <script>', "lunes: 10:00\nmartes: cerrado" === str_replace( "\r", '', $l['schedule'] ) );
$ok( 'coma decimal en latitud', '42.2388906' === $l['latitude'] );
$ok( 'foto https se guarda', 'https://x.es/a.jpg' === $l['photo_0'] );
$ok( 'foto javascript: se descarta', null === $l['photo_1'] );
$ok( 'vacíos → borrar', null === $l['postcode'] && null === $l['photo_2'] );

$e = array();
$l = ZN_Admin_Negocios::limpiar( array( 'business_category_id' => '99', 'email' => 'no-es-email', 'web' => 'no es web', 'latitude' => '200' ), $e );
$ok( 'categoría inventada no se guarda', ! array_key_exists( 'business_category_id', $l ) );
$ok( 'email malo no se guarda y avisa', ! array_key_exists( 'email', $l ) );
$ok( 'web mala no se guarda', ! array_key_exists( 'web', $l ) );
$ok( 'latitud fuera de rango no se guarda', ! array_key_exists( 'latitude', $l ) );
$ok( 'tres avisos o más', count( $e ) >= 3 );
$ok( 'la puntuación nunca sale del formulario', ! array_key_exists( 'score', ZN_Admin_Negocios::limpiar( array( 'score' => '5' ), $e ) ) );

// Las casillas se pintan sin errores con un negocio real de prueba.
$negocio = get_posts( array( 'post_type' => ZN_Negocios::TIPO, 'numberposts' => 1 ) )[0];
ob_start();
ZN_Admin_Negocios::caja_datos( $negocio );
ZN_Admin_Negocios::caja_fotos( $negocio );
ZN_Admin_Negocios::caja_puntuacion( $negocio );
$html = ob_get_clean();
$ok( 'la ficha se pinta con sus datos', false !== strpos( $html, 'name="zn[address]"' ) && false !== strpos( $html, 'Elegir foto 3' ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

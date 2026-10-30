<?php
// Comprobaciones de la ficha de wp-admin. Uso: wp eval-file tests/admin-negocios.php
foreach ( array( 'campos', 'negocios', 'eventos', 'sorteos' ) as $f ) {
	require_once WP_PLUGIN_DIR . "/zona-nautico/includes/class-zn-admin-$f.php";
}

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
ZN_Admin_Campos::caja_fotos( $negocio );
ZN_Admin_Negocios::caja_puntuacion( $negocio );
$html = ob_get_clean();
$ok( 'la ficha se pinta con sus datos', false !== strpos( $html, 'name="zn[address]"' ) && false !== strpos( $html, 'Elegir foto 3' ) );

// --- Novedades ---
// Colgar una novedad de un negocio exige poder editar ese negocio (R3): se prueba como administrador.
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0] );
$taberna = get_posts( array( 'post_type' => ZN_Negocios::TIPO, 'numberposts' => 1, 'fields' => 'ids' ) )[0];
$e = array();
$l = ZN_Admin_Eventos::limpiar(
	array(
		'event_category_id' => '2', 'business_id' => (string) $taberna, 'start_date' => '2026-11-02T10:30', 'end_date' => '2026-11-02T12:00',
		'latitude' => '', 'video_0' => 'https://x.es/v.mp4', 'video_1' => 'javascript:x', 'photo_0' => 'https://x.es/f.jpg',
	),
	$e
);
$ok( 'novedad: sin avisos con datos buenos', ! $e );
$ok( 'novedad: tipo válido', 2 === $l['event_category_id'] );
$ok( 'novedad: negocio existente', $taberna === $l['business_id'] );
$ok( 'novedad: fechas al formato del Laravel', '2026-11-02 10:30:00' === $l['start_date'] && '2026-11-02 12:00:00' === $l['end_date'] );
$ok( 'novedad: vídeo https sí, javascript: no', 'https://x.es/v.mp4' === $l['video_0'] && null === $l['video_1'] );
$ok( 'novedad: foto', 'https://x.es/f.jpg' === $l['photo_0'] );
wp_set_current_user( 0 );
$sin_permiso = array();
$ok( 'novedad: sin permiso sobre el negocio no se cuelga de él', ! array_key_exists( 'business_id', ZN_Admin_Eventos::limpiar( array( 'business_id' => (string) $taberna ), $sin_permiso ) ) && $sin_permiso );
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0] );

$e = array();
$l = ZN_Admin_Eventos::limpiar( array( 'event_category_id' => '9', 'business_id' => '999999', 'start_date' => '2026-13-40T99:00' ), $e );
$ok( 'novedad: tipo inventado no se guarda', ! array_key_exists( 'event_category_id', $l ) );
$ok( 'novedad: negocio inexistente no se guarda', ! array_key_exists( 'business_id', $l ) );
$ok( 'novedad: fecha imposible no se guarda', ! array_key_exists( 'start_date', $l ) );

$e = array();
$l = ZN_Admin_Eventos::limpiar( array( 'start_date' => '2026-11-02T10:30', 'end_date' => '2026-11-01T10:30' ), $e );
$ok( 'novedad: fin antes que inicio no se guarda y avisa', ! array_key_exists( 'end_date', $l ) && $e );

$e = array();
$l = ZN_Admin_Eventos::limpiar( array(), $e );
$ok( 'novedad: todo vacío → borrar, sin avisos', null === $l['start_date'] && null === $l['business_id'] && ! $e );

$novedad = get_posts( array( 'post_type' => ZN_Eventos::TIPO, 'numberposts' => 1 ) )[0];
ob_start();
ZN_Admin_Eventos::caja_datos( $novedad );
ZN_Admin_Campos::caja_videos( $novedad );
$html = ob_get_clean();
$ok( 'novedad: la ficha se pinta con negocios y fechas', false !== strpos( $html, 'Taberna O Peirao' ) && false !== strpos( $html, 'type="datetime-local"' ) );

// --- Sorteos ---
$e = array();
$l = ZN_Admin_Sorteos::limpiar( array( 'start_date' => '2026-11-01T00:00', 'end_date' => '2026-11-30T23:59', 'winners' => '3', 'legal_url' => 'zonanautico.com/bases' ), $e );
$ok( 'sorteo: sin avisos con datos buenos', ! $e );
$ok( 'sorteo: fechas y ganadores', '2026-11-30 23:59:00' === $l['end_date'] && 3 === $l['winners'] );
$ok( 'sorteo: bases sin protocolo → https', 'https://zonanautico.com/bases' === $l['legal_url'] );

$e = array();
$l = ZN_Admin_Sorteos::limpiar( array( 'winners' => '0', 'legal_url' => 'javascript:alert(1)' ), $e );
$ok( 'sorteo: 0 ganadores no se guarda', ! array_key_exists( 'winners', $l ) );
$ok( 'sorteo: bases peligrosas no se guardan', ! array_key_exists( 'legal_url', $l ) );
$ok( 'sorteo: avisa si faltan fechas', (bool) array_filter( $e, static fn( $m ) => false !== strpos( $m, 'Faltan fechas' ) ) );

$sorteo = get_posts( array( 'post_type' => ZN_Sorteos::TIPO, 'numberposts' => 1 ) )[0];
ob_start();
ZN_Admin_Sorteos::caja_datos( $sorteo );
ZN_Admin_Sorteos::caja_estado( $sorteo );
$html = ob_get_clean();
$ok( 'sorteo: la ficha se pinta con ganadores y estado', false !== strpos( $html, 'name="zn[winners]"' ) && false !== strpos( $html, 'participar' ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

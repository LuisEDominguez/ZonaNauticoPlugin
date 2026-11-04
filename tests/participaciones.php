<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Participaciones en sorteos (decisiones de Luis del 04-11): tabla {prefijo}zn_participaciones (una por persona y
// sorteo); POST /draw-participation (multipart: draw o drawId, ticket y adult); GET /draw-participation (lo mío, o
// las de un sorteo para quien lo organiza y la administración, R43); GET /draw-participation/{id}/ticket (privado);
// DELETE; PATCH {winner, delivered}; GET /draw-participation/export/{drawId}?format=csv (nombre y correo, R45).
// Fotos fuera de la biblioteca de medios y sin EXIF. Borrado: entrega + 30 días, o fin + 90 sin entrega. RGPD (R65,
// decisión 7). Ganadores en /draw/{id}?includesWinners=1, solo con el nombre de pila.
// Uso: wp eval-file tests/participaciones.php. Crea cuentas «…-temporal-participa@ejemplo.invalid», sorteos y fotos
// de prueba, y lo borra todo. Los correos se recogen y no salen.

require_once ABSPATH . 'wp-admin/includes/user.php';

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

global $wpdb;
$servidor = $_SERVER;
$cuentas  = array();
$emails   = array();
$entradas = array();
$temporales = array();
$cuenta   = static function ( string $rol_wp = 'zn_usuario', string $nombre = 'Temporal' ) use ( &$cuentas, &$emails ) {
	$login = str_replace( 'zn_', '', $rol_wp ) . '-' . count( $cuentas ) . '-' . wp_rand( 1000, 9999 ) . '-temporal-participa';
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => "$login@ejemplo.invalid", 'user_pass' => wp_generate_password( 24 ), 'role' => $rol_wp, 'first_name' => $nombre ) );
	update_user_meta( $id, 'zn_verificado', '1' );
	$cuentas[] = $id;
	$emails[]  = "$login@ejemplo.invalid";
	return $id;
};
$madrid  = static fn( string $cuando ) => ( new DateTimeImmutable( $cuando, new DateTimeZone( 'Europe/Madrid' ) ) )->format( 'Y-m-d H:i:s' );
$sorteo  = static function ( int $dueno, string $inicio = '-1 day', string $fin = '+7 days' ) use ( &$entradas, $madrid ) {
	$id         = ZN_Esquema::guardar( 'sorteo', null, array( 'post_title' => 'Sorteo temporal-participa ' . count( $entradas ), 'user_id' => $dueno, 'winners' => 1, 'start_date' => $madrid( $inicio ), 'end_date' => $madrid( $fin ) ), array( 'post_status' => 'publish', 'post_author' => 0 ) );
	$entradas[] = $id;
	return $id;
};
// Foto JPEG de prueba con un bloque EXIF que lleva una marca de «GPS» (para ver que se quita).
$foto = static function ( bool $con_exif = true ) use ( &$temporales ): string {
	$img = imagecreatetruecolor( 40, 30 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 20, 120, 200 ) );
	ob_start();
	imagejpeg( $img, null, 90 );
	$jpeg = (string) ob_get_clean();
	if ( $con_exif ) {
		$carga = "Exif\0\0" . "MM\0*\0\0\0\x08" . str_repeat( "\0", 4 ) . 'GPSInfo-ZN-PRUEBA 42.2388906 -8.7259422';
		$jpeg  = substr( $jpeg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $carga ) + 2 ) . $carga . substr( $jpeg, 2 );
	}
	$ruta = wp_tempnam( 'ticket.jpg' );
	file_put_contents( $ruta, $jpeg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$temporales[] = $ruta;
	return $ruta;
};
$archivo = static fn( string $ruta, string $nombre = 'ticket.jpg', string $tipo = 'image/jpeg' ) => array( 'name' => $nombre, 'type' => $tipo, 'tmp_name' => $ruta, 'error' => 0, 'size' => filesize( $ruta ) );
// Llamada por dentro como $quien (0 = sin sesión), pasando por rest_post_dispatch como al servir.
$llamar = static function ( string $metodo, string $ruta, int $quien, array $campos = array(), array $archivos = array(), array $consulta = array(), string $ip = '192.0.2.98' ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	wp_set_current_user( $quien );
	$peticion = new WP_REST_Request( $metodo, '/' . ZN_NAMESPACE . $ruta );
	$peticion->set_query_params( $consulta );
	if ( 'PATCH' === $metodo ) {
		$peticion->set_header( 'Content-Type', 'application/json' );
		$peticion->set_body( wp_json_encode( $campos ) );
	} else {
		$peticion->set_body_params( $campos );
	}
	$peticion->set_file_params( $archivos );
	$respuesta = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $peticion ) ), rest_get_server(), $peticion );
	wp_set_current_user( 0 );
	return array( $respuesta->get_status(), $respuesta->get_data(), $respuesta->get_headers() );
};
$filas = static fn( array $donde ) => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Participaciones::tabla() . ' WHERE ' . implode( ' AND ', array_map( static fn( $c, $v ) => $wpdb->prepare( "$c = %d", $v ), array_keys( $donde ), $donde ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$correos = array();
add_filter(
	'pre_wp_mail',
	static function ( $resultado, $correo ) use ( &$correos ) {
		$correos[] = $correo;
		return true;
	},
	PHP_INT_MAX,
	2
);

try {
	// --- La tabla, las rutas y la carpeta privada ---
	$ok( 'existe la tabla {prefijo}zn_participaciones', ZN_Participaciones::tabla() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', ZN_Participaciones::tabla() ) ) );
	$ok( 'R68: desinstalar borra la tabla', in_array( ZN_Participaciones::tabla(), ZN_Instalacion::tablas(), true ) );
	$rutas = rest_get_server()->get_routes( ZN_NAMESPACE );
	foreach ( array( '/draw-participation', '/draw-participation/(?P<id>\d+)', '/draw-participation/(?P<id>\d+)/ticket', '/draw-participation/export/(?P<drawId>\d+)' ) as $ruta ) {
		$ok( "existe $ruta", isset( $rutas[ '/' . ZN_NAMESPACE . $ruta ] ) );
	}
	// R134: la carpeta solo vale si se llama zn-privado*, no es la de WordPress ni uploads, no está vacía y no es un
	// enlace simbólico. Si no, ni se usa ni se borra.
	$ajena = wp_normalize_path( get_temp_dir() ) . 'otra-carpeta-' . wp_rand( 1000, 9999 );
	wp_mkdir_p( $ajena );
	file_put_contents( "$ajena/no-borrar.txt", 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	foreach ( array( 'una carpeta que no se llama zn-privado*' => $ajena, 'la carpeta de WordPress' => ABSPATH, 'uploads' => wp_get_upload_dir()['basedir'], 'vacía' => '' ) as $caso => $mala ) {
		$filtro = static fn() => $mala;
		add_filter( 'zn_carpeta_tickets', $filtro );
		$valida = ZN_Participaciones::ruta_valida( $mala );
		$usada  = ZN_Participaciones::carpeta();
		$borra  = ZN_Participaciones::borrar_carpeta();
		remove_filter( 'zn_carpeta_tickets', $filtro );
		$ok( "R134: $caso: no vale, no se usa (sin carpeta: '') y borrar_carpeta() no borra nada", ! $valida && '' === $usada && false === $borra );
	}
	$ok( 'R134: lo que había en la carpeta ajena sigue ahí', is_file( "$ajena/no-borrar.txt" ) );
	unlink( "$ajena/no-borrar.txt" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	rmdir( $ajena ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	$enlace = wp_normalize_path( get_temp_dir() ) . 'zn-privado-enlace-' . wp_rand( 1000, 9999 );
	if ( @symlink( wp_normalize_path( get_temp_dir() ), $enlace ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$ok( 'R134: un enlace simbólico (aunque se llame zn-privado*) no vale', ! ZN_Participaciones::ruta_valida( $enlace ) );
		@unlink( $enlace ); // phpcs:ignore
	} else {
		echo "aviso Windows sin permiso para crear enlaces simbólicos: la comprobación de is_link() no se puede probar aquí\n";
	}
	$carpeta = ZN_Participaciones::carpeta();
	$ok( 'R134: la carpeta de verdad sí vale', ZN_Participaciones::ruta_valida( $carpeta ) );
	// R131: sin carpeta fuera de la web no se guarda ningún ticket (no hay plan B en uploads).
	$ok( 'R131: la carpeta está fuera de la web (no en uploads)', 0 !== strpos( wp_normalize_path( $carpeta ), wp_normalize_path( ABSPATH ) ) );
	$sin_carpeta = static fn() => wp_get_upload_dir()['basedir'] . '/zn-privado';
	add_filter( 'zn_carpeta_tickets', $sin_carpeta );
	$ok( 'R131: dentro de uploads ya no vale', '' === ZN_Participaciones::carpeta() );
	$ok( 'R131: y wp-admin lo avisa', '' !== ZN_Participaciones::aviso_carpeta() );
	$probar_sin = $cuenta( 'zn_usuario', 'Sin carpeta' );
	$sorteo_sin = $sorteo( $cuenta( 'zn_comercio', 'Org sin carpeta' ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw-participation', $probar_sin, array( 'draw' => $sorteo_sin, 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ) );
	remove_filter( 'zn_carpeta_tickets', $sin_carpeta );
	$ok( 'R131: sin carpeta privada, POST da un error claro (503) y no guarda nada', 503 === $estado && false !== stripos( (string) ( $cuerpo['message'] ?? '' ), 'ticket' ) && 0 === $filas( array( 'user_id' => $probar_sin ) ) );
	$ok( 'R131: con la carpeta bien, wp-admin no avisa de nada', '' === ZN_Participaciones::aviso_carpeta() );
	// R137: al desactivar el plugin se quita la tarea diaria.
	$principal = plugin_basename( WP_PLUGIN_DIR . '/zona-nautico/zona-nautico.php' );
	$ok( 'R137: hay un gancho de desactivación que quita la tarea', false !== has_action( "deactivate_$principal", array( 'ZN_Participaciones', 'desprogramar' ) ) || false !== has_action( 'deactivate_' . plugin_basename( dirname( __DIR__ ) . '/zona-nautico.php' ), array( 'ZN_Participaciones', 'desprogramar' ) ) );
	$ok( 'las fotos van fuera de la carpeta pública de WordPress (o, si no se puede, protegidas)', is_dir( $carpeta ) && ( 0 !== strpos( wp_normalize_path( $carpeta ), wp_normalize_path( ABSPATH ) ) || ( is_file( "$carpeta/.htaccess" ) && is_file( "$carpeta/index.php" ) ) ) );
	$ok( 'la carpeta no está dentro de la biblioteca de medios pública (uploads sin proteger)', 0 !== strpos( wp_normalize_path( $carpeta ), wp_normalize_path( wp_get_upload_dir()['basedir'] ) ) || is_file( "$carpeta/.htaccess" ) );

	// --- Participar ---
	$comercio = $cuenta( 'zn_comercio', 'Comercio' );
	$ana      = $cuenta( 'zn_usuario', 'Ana María' );
	$abierto  = $sorteo( $comercio );
	$cerrado  = $sorteo( $comercio, '-10 days', '-1 day' );
	list( $estado ) = $llamar( 'POST', '/draw-participation', 0, array( 'draw' => $abierto, 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ) );
	$ok( 'sin sesión: 401', 401 === $estado );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw-participation', $ana, array( 'draw' => $abierto ), array( 'ticket' => $archivo( $foto() ) ) );
	$ok( 'sin la casilla «soy mayor de 18»: 422 en adult', 422 === $estado && isset( $cuerpo['errors']['adult'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw-participation', $ana, array( 'draw' => $abierto, 'adult' => '0' ), array( 'ticket' => $archivo( $foto() ) ) );
	$ok( 'adult = 0: 422', 422 === $estado && isset( $cuerpo['errors']['adult'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw-participation', $ana, array( 'draw' => $abierto, 'adult' => '1' ) );
	$ok( 'sin foto del ticket: 422 en ticket', 422 === $estado && isset( $cuerpo['errors']['ticket'] ) );
	$texto = wp_tempnam( 'no.jpg' );
	file_put_contents( $texto, '<?php echo 1;' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$temporales[] = $texto;
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw-participation', $ana, array( 'draw' => $abierto, 'adult' => '1' ), array( 'ticket' => $archivo( $texto ) ) );
	$ok( 'un archivo que no es una imagen (aunque se llame .jpg): 422 en ticket', 422 === $estado && isset( $cuerpo['errors']['ticket'] ) );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw-participation', $ana, array( 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ) );
	$ok( 'sin draw ni drawId: 422 en draw', 422 === $estado && isset( $cuerpo['errors']['draw'] ) );
	list( $estado ) = $llamar( 'POST', '/draw-participation', $ana, array( 'draw' => $cerrado, 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ) );
	$ok( 'sorteo cerrado: 422', 422 === $estado );
	list( $estado ) = $llamar( 'POST', '/draw-participation', $ana, array( 'draw' => 999999999, 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ) );
	$ok( 'sorteo que no existe: 404', 404 === $estado );
	// R132: un PNG que dice medir 20000 × 20000 (400 MP). Solo la cabecera: nunca se llega a decodificar.
	$ihdr  = pack( 'NN', 20000, 20000 ) . "\x08\x06\x00\x00\x00";
	$png   = "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) ) . pack( 'N', 0 ) . 'IEND' . pack( 'N', crc32( 'IEND' ) );
	$gigante = wp_tempnam( 'gigante.png' );
	file_put_contents( $gigante, $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$temporales[] = $gigante;
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw-participation', $ana, array( 'draw' => $abierto, 'adult' => '1' ), array( 'ticket' => $archivo( $gigante, 'gigante.png', 'image/png' ) ) );
	$ok( 'R132: imagen de más de 40 megapíxeles: 422 en ticket (no 500) y nada guardado', 422 === $estado && isset( $cuerpo['errors']['ticket'] ) && 0 === $filas( array( 'user_id' => $ana ) ) );
	list( $estado ) = $llamar( 'POST', '/draw-participation', $ana, array( 'draw' => 999999999, 'adult' => '1' ), array( 'ticket' => $archivo( $gigante, 'gigante.png', 'image/png' ) ) );
	$ok( 'R132: el sorteo se mira antes de abrir la imagen: con un sorteo que no existe, 404', 404 === $estado );
	list( $estado ) = $llamar( 'POST', '/draw-participation', $ana, array( 'draw' => $cerrado, 'adult' => '1' ), array( 'ticket' => $archivo( $gigante, 'gigante.png', 'image/png' ) ) );
	$ok( 'R132: … y con uno cerrado, 422 en draw', 422 === $estado );
	$ok( 'nada se ha guardado todavía', 0 === $filas( array( 'user_id' => $ana ) ) );
	$correos = array();
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw-participation', $ana, array( 'drawId' => $abierto, 'adult' => 'true', 'user' => $comercio ), array( 'ticket' => $archivo( $foto() ) ) );
	$dato = $cuerpo['data'] ?? array();
	$ok( 'POST bueno (con drawId): 201 con _id, date, user, draw y ticket', 201 === $estado && array( '_id', 'date', 'user', 'draw', 'ticket', 'winner', 'delivered' ) === array_keys( $dato ) );
	$ok( 'H21: el «user» que manda la app se ignora: es la cuenta del token', (string) $ana === ( $dato['user']['_id'] ?? null ) && 'Ana' === ( $dato['user']['name'] ?? null ) );
	$ok( 'ticket es la ruta protegida, nunca la dirección del archivo', isset( $dato['ticket'] ) && false !== strpos( $dato['ticket'], '/draw-participation/' . $dato['_id'] . '/ticket' ) && false === strpos( $dato['ticket'], 'wp-content' ) );
	$id_ana = (int) $dato['_id'];
	$fila   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE id = %d', $id_ana ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$guardada = $fila ? ZN_Participaciones::ruta_ticket( $fila ) : '';
	$bytes    = $guardada && is_file( $guardada ) ? (string) file_get_contents( $guardada ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$ok( 'la foto se guarda en la carpeta privada, con nombre al azar', $guardada && 0 === strpos( wp_normalize_path( $guardada ), wp_normalize_path( $carpeta ) ) && false === strpos( basename( $guardada ), 'ticket' ) );
	$ok( 'decisión 2: la foto guardada no lleva EXIF (ni la marca de GPS)', '' !== $bytes && false === strpos( $bytes, "Exif\0\0" ) && false === strpos( $bytes, 'GPSInfo-ZN-PRUEBA' ) && false !== @imagecreatefromstring( $bytes ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$ok( 'decisión 6: se guarda cuándo marcó «soy mayor de 18»', ! empty( $fila['adulto_en'] ) );
	$ok( 'no se ha mandado ningún correo al participar', ! $correos );
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw-participation', $ana, array( 'draw' => $abierto, 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ) );
	$ok( 'decisión a: una por persona y sorteo: la segunda, 422 en draw', 422 === $estado && isset( $cuerpo['errors']['draw'] ) && 1 === $filas( array( 'user_id' => $ana ) ) );

	// --- R144: una foto grande y girada (EXIF Orientation = 6) se escala ANTES de girarla ---
	// 6000 × 3000 (18 MP): decodificada ocupa unos 72 MB. Girarla entera haría otra copia igual (~144 MB juntas);
	// escalando primero, la segunda copia es de 2000 × 1000 (~8 MB).
	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		$grande = imagecreatetruecolor( 6000, 3000 );
		ob_start();
		imagejpeg( $grande, null, 60 );
		$jpeg_grande = (string) ob_get_clean();
		imagedestroy( $grande );
		unset( $grande );
		// APP1 con un IFD de una entrada: Orientation (0x0112), SHORT, 1 valor = 6 (girar 90° a la derecha).
		$tiff        = "MM\0*\0\0\0\x08" . pack( 'n', 1 ) . pack( 'nnNn', 0x0112, 3, 1, 6 ) . "\0\0" . pack( 'N', 0 );
		$carga       = "Exif\0\0" . $tiff;
		$jpeg_grande = substr( $jpeg_grande, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $carga ) + 2 ) . $carga . substr( $jpeg_grande, 2 );
		$ruta_grande = wp_tempnam( 'grande.jpg' );
		file_put_contents( $ruta_grande, $jpeg_grande ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		unset( $jpeg_grande );
		$temporales[] = $ruta_grande;
		$giro_prueba  = $cuenta( 'zn_usuario', 'Giro' );
		$sorteo_giro  = $sorteo( $cuenta( 'zn_comercio', 'Org giro' ) );
		gc_collect_cycles();
		$base_memoria = memory_get_usage();
		memory_reset_peak_usage();
		list( $estado, $cuerpo ) = $llamar( 'POST', '/draw-participation', $giro_prueba, array( 'draw' => $sorteo_giro, 'adult' => '1' ), array( 'ticket' => $archivo( $ruta_grande ) ) );
		$pico = ( memory_get_peak_usage() - $base_memoria ) / MB_IN_BYTES;
		$f_giro = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE id = %d', (int) ( $cuerpo['data']['_id'] ?? 0 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$medidas = $f_giro ? @getimagesize( ZN_Participaciones::ruta_ticket( $f_giro ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$ok( 'R144: la foto girada se guarda bien: 1000 × 2000 (escalada a 2000 y en vertical)', 201 === $estado && $medidas && 1000 === $medidas[0] && 2000 === $medidas[1] );
		$ok( sprintf( 'R144: memoria de más al procesarla: %d MB (escalar antes de girar: menos de 110 MB)', (int) $pico ), $pico < 110 );
	} else {
		echo "aviso sin memory_reset_peak_usage (PHP < 8.2): no se mide la memoria de R144\n";
	}

	// --- Ver: lo mío; las del sorteo, solo quien organiza y la administración (R43) ---
	$luis   = $cuenta( 'zn_usuario', 'Luis Pérez' );
	$admin  = $cuenta( 'zn_administrador', 'Admin' );
	$otro_c = $cuenta( 'zn_comercio', 'Otro comercio' );
	list( , $cuerpo ) = $llamar( 'POST', '/draw-participation', $luis, array( 'draw' => $abierto, 'adult' => '1' ), array( 'ticket' => $archivo( $foto( false ), 'ticket.jpg' ) ) );
	$id_luis = (int) ( $cuerpo['data']['_id'] ?? 0 );
	list( $estado, $cuerpo ) = $llamar( 'GET', '/draw-participation', $ana );
	$ok( 'GET sin draw: solo las mías', 200 === $estado && array( (string) $id_ana ) === array_column( $cuerpo['data'] ?? array(), '_id' ) );
	list( $estado ) = $llamar( 'GET', '/draw-participation', 0 );
	$ok( 'GET sin sesión: 401 (no es público como en el Laravel, H1)', 401 === $estado );
	list( $estado, $cuerpo ) = $llamar( 'GET', '/draw-participation', $ana, array(), array(), array( 'draw' => $abierto ) );
	$ok( 'GET ?draw= de una participante: solo la suya, no las de los demás', 200 === $estado && array( (string) $id_ana ) === array_column( $cuerpo['data'] ?? array(), '_id' ) );
	list( $estado, $cuerpo ) = $llamar( 'GET', '/draw-participation', $comercio, array(), array(), array( 'draw' => $abierto ) );
	$nombres = array_column( array_column( $cuerpo['data'] ?? array(), 'user' ), 'name' );
	$ok( 'R43: quien organiza ve todas las de su sorteo', 200 === $estado && 2 === count( $cuerpo['data'] ?? array() ) );
	$ok( 'decisión 3: y con el nombre de cada participante (el de pila)', array( 'Ana', 'Luis' ) === $nombres || array( 'Luis', 'Ana' ) === $nombres );
	list( $estado, $cuerpo ) = $llamar( 'GET', '/draw-participation', $otro_c, array(), array(), array( 'draw' => $abierto ) );
	$ok( 'otro comercio no ve las de un sorteo ajeno: 401', 401 === $estado );
	list( $estado, $cuerpo ) = $llamar( 'GET', '/draw-participation', $admin, array(), array(), array( 'draw' => $abierto ) );
	$ok( 'la administración, sí', 200 === $estado && 2 === count( $cuerpo['data'] ?? array() ) );

	// --- La foto, solo por la ruta protegida ---
	list( $estado, $cuerpo, $cabeceras ) = $llamar( 'GET', "/draw-participation/$id_ana/ticket", $ana );
	$ok( 'GET /ticket de la propia participante: 200 con la foto (image/jpeg) y sin caché', 200 === $estado && 'image/jpeg' === ( $cabeceras['Content-Type'] ?? null ) && false !== strpos( (string) ( $cabeceras['Cache-Control'] ?? '' ), 'no-store' ) && ZN_Participaciones::es_archivo( $cuerpo ) );
	$ok( 'quien organiza también la ve (decisión 3)', 200 === $llamar( 'GET', "/draw-participation/$id_ana/ticket", $comercio )[0] );
	$ok( 'otra participante no: 401', 401 === $llamar( 'GET', "/draw-participation/$id_ana/ticket", $luis )[0] );
	$ok( 'sin sesión: 401', 401 === $llamar( 'GET', "/draw-participation/$id_ana/ticket", 0 )[0] );
	$ok( 'por la web no se llega al archivo: no está bajo la dirección del sitio', false === strpos( wp_normalize_path( $guardada ), wp_normalize_path( ABSPATH ) ) || 403 <= (int) wp_remote_retrieve_response_code( wp_remote_get( str_replace( wp_normalize_path( ABSPATH ), home_url( '/' ), wp_normalize_path( $guardada ) ), array( 'timeout' => 30 ) ) ) );

	// --- Exportar CSV: nombre y correo (R45, decisión 5) ---
	list( $estado, $cuerpo, $cabeceras ) = $llamar( 'GET', "/draw-participation/export/$abierto", $comercio, array(), array(), array( 'format' => 'csv' ) );
	$csv = isset( $cuerpo['__zn_archivo'] ) ? (string) file_get_contents( $cuerpo['__zn_archivo'] ) : (string) ( $cuerpo['__zn_texto'] ?? '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$ok( 'export: 200, text/csv, con nombre y correo de las dos', 200 === $estado && 0 === strpos( (string) ( $cabeceras['Content-Type'] ?? '' ), 'text/csv' ) && false !== strpos( $csv, get_userdata( $ana )->user_email ) && false !== strpos( $csv, get_userdata( $luis )->user_email ) );
	$ok( 'export: sin teléfono ni fotos', false === stripos( $csv, 'tel' ) && false === stripos( $csv, 'ticket' ) && false === strpos( $csv, '.jpg' ) );
	$ok( 'export de otro comercio: 401', 401 === $llamar( 'GET', "/draw-participation/export/$abierto", $otro_c, array(), array(), array( 'format' => 'csv' ) )[0] );
	$ok( 'export de una participante: 401', 401 === $llamar( 'GET', "/draw-participation/export/$abierto", $ana, array(), array(), array( 'format' => 'csv' ) )[0] );
	$ok( 'formato que no es csv: 422', 422 === $llamar( 'GET', "/draw-participation/export/$abierto", $comercio, array(), array(), array( 'format' => 'pdf' ) )[0] );

	// --- Ganador y entrega (decisiones 4, c y d): a mano, quien organiza o la administración ---
	$ok( 'una participante no puede marcarse ganadora: 401', 401 === $llamar( 'PATCH', "/draw-participation/$id_ana", $ana, array( 'winner' => true ) )[0] );
	$ok( 'otro comercio tampoco: 401', 401 === $llamar( 'PATCH', "/draw-participation/$id_ana", $otro_c, array( 'winner' => true ) )[0] );
	$ok( 'entregar sin ser ganadora: 422', 422 === $llamar( 'PATCH', "/draw-participation/$id_ana", $comercio, array( 'delivered' => true ) )[0] );
	$correos = array();
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/draw-participation/$id_ana", $comercio, array( 'winner' => true ) );
	$ok( 'quien organiza marca ganadora: 200 y winner true', 200 === $estado && true === ( $cuerpo['data']['winner'] ?? null ) );
	$aviso = $correos[0] ?? null;
	$ok( 'decisión 4: a la ganadora le llega un aviso privado a su correo', $aviso && array( strtolower( get_userdata( $ana )->user_email ) ) === ZN_Puertas::destinatarios( $aviso ) && false !== stripos( (string) $aviso['subject'], 'ganado' ) );
	list( , $cuerpo ) = $llamar( 'GET', "/draw/$abierto", 0, array(), array(), array( 'includesWinners' => 1 ) );
	$ok( 'decisión 4: /draw/{id}?includesWinners=1 da los ganadores solo con el nombre de pila', array( 'Ana' ) === ( $cuerpo['data']['winnerNames'] ?? null ) );
	list( , $cuerpo ) = $llamar( 'GET', "/draw/$abierto", 0 );
	$ok( 'sin includesWinners, la ficha del sorteo no cambia', ! array_key_exists( 'winnerNames', $cuerpo['data'] ?? array( 'winnerNames' => 1 ) ) );
	list( $estado, $cuerpo ) = $llamar( 'PATCH', "/draw-participation/$id_ana", $admin, array( 'delivered' => true ) );
	$ok( 'la administración marca la entrega: 200 y delivered true', 200 === $estado && true === ( $cuerpo['data']['delivered'] ?? null ) );

	// R140: quien organiza no participa en su propio sorteo.
	list( $estado, $cuerpo ) = $llamar( 'POST', '/draw-participation', $comercio, array( 'draw' => $abierto, 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ) );
	$ok( 'R140: quien organiza el sorteo no puede participar en él: 422 en draw', 422 === $estado && isset( $cuerpo['errors']['draw'] ) && 0 === $filas( array( 'user_id' => $comercio ) ) );

	// R139: el PATCH cuenta en el límite de escrituras (no se puede mandar el aviso de ganador sin fin).
	$organiza = $cuenta( 'zn_comercio', 'Organiza' );
	$su_sorteo = $sorteo( $organiza );
	$marta     = $cuenta( 'zn_usuario', 'Marta' );
	list( , $cuerpo ) = $llamar( 'POST', '/draw-participation', $marta, array( 'draw' => $su_sorteo, 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ) );
	$id_marta = (int) ( $cuerpo['data']['_id'] ?? 0 );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.98';
	wp_set_current_user( $organiza );
	for ( $i = 0; $i < ZN_Limite::REGLAS['escritura']['cuenta'][0]; $i++ ) {
		ZN_API::limite_de_escritura();
	}
	wp_set_current_user( 0 );
	$correos = array();
	list( $estado ) = $llamar( 'PATCH', "/draw-participation/$id_marta", $organiza, array( 'winner' => true ) );
	$ok( 'R139: pasado el límite de escrituras, PATCH 429 y sin aviso', 429 === $estado && ! $correos );

	// R135: con ?_envelope, /ticket no enseña la ruta del archivo en el servidor (por HTTP de verdad).
	$t_ana = ZN_Tokens::emitir( $ana );
	$sobre = wp_remote_get( rest_url( ZN_NAMESPACE . "/draw-participation/$id_ana/ticket" ) . '?_envelope=1', array( 'timeout' => 60, 'headers' => array( 'Authorization' => "Bearer $t_ana" ) ) );
	$cuerpo_sobre = is_wp_error( $sobre ) ? '' : (string) wp_remote_retrieve_body( $sobre );
	$ok( 'R135: con ?_envelope no sale la ruta del archivo', '' !== $cuerpo_sobre && false === strpos( $cuerpo_sobre, 'zn-privado' ) && false === strpos( $cuerpo_sobre, '__zn_archivo' ) );
	$foto_http = wp_remote_get( rest_url( ZN_NAMESPACE . "/draw-participation/$id_ana/ticket" ), array( 'timeout' => 60, 'headers' => array( 'Authorization' => "Bearer $t_ana" ) ) );
	$ok( 'HTTP: /ticket con su token da la foto (image/jpeg)', ! is_wp_error( $foto_http ) && 200 === wp_remote_retrieve_response_code( $foto_http ) && 0 === strpos( (string) wp_remote_retrieve_header( $foto_http, 'content-type' ), 'image/jpeg' ) && false !== @imagecreatefromstring( (string) wp_remote_retrieve_body( $foto_http ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	// --- Retirarse ---
	$eva    = $cuenta( 'zn_usuario', 'Eva' );
	list( , $cuerpo ) = $llamar( 'POST', '/draw-participation', $eva, array( 'draw' => $abierto, 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ) );
	$id_eva = (int) ( $cuerpo['data']['_id'] ?? 0 );
	$fila_eva = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE id = %d', $id_eva ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$foto_eva = $fila_eva ? ZN_Participaciones::ruta_ticket( $fila_eva ) : '';
	$ok( 'otra no puede borrar la de Eva: 401', 401 === $llamar( 'DELETE', "/draw-participation/$id_eva", $luis )[0] );
	list( $estado, $cuerpo ) = $llamar( 'DELETE', "/draw-participation/$id_eva", $eva );
	$ok( 'Eva se retira mientras está abierto: 200, y se borran la fila y la foto', 200 === $estado && array( 'status' => 'success' ) === $cuerpo && 0 === $filas( array( 'id' => $id_eva ) ) && $foto_eva && ! is_file( $foto_eva ) );

	// --- Límite de escrituras (R94) ---
	$pepa = $cuenta( 'zn_usuario', 'Pepa' );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.99';
	wp_set_current_user( $pepa );
	for ( $i = 0; $i < ZN_Limite::REGLAS['escritura']['cuenta'][0]; $i++ ) {
		ZN_API::limite_de_escritura();
	}
	wp_set_current_user( 0 );
	list( $estado ) = $llamar( 'POST', '/draw-participation', $pepa, array( 'draw' => $abierto, 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ), array(), '192.0.2.99' );
	$ok( 'pasado el límite de escrituras: 429 y nada guardado', 429 === $estado && 0 === $filas( array( 'user_id' => $pepa ) ) );

	// --- Borrado automático (decisiones 1 y b) ---
	$ok( 'hay una tarea diaria de limpieza', false !== wp_next_scheduled( ZN_Participaciones::TAREA ) );
	$foto_luis = ZN_Participaciones::ruta_ticket( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE id = %d', $id_luis ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	// R138: el sorteo acaba dentro de 7 días y se entregó hoy: los 30 días cuentan desde el fin (lo más tarde).
	ZN_Participaciones::limpiar( time() + 31 * DAY_IN_SECONDS );
	$ok( 'R138: entregado con el sorteo aún abierto: a los 31 días de la entrega no se borra (cuenta desde el fin)', 1 === $filas( array( 'id' => $id_luis ) ) && is_file( $guardada ) );
	ZN_Participaciones::limpiar( time() + 36 * DAY_IN_SECONDS );
	$ok( 'a los 29 días del fin aún no se borra nada', 1 === $filas( array( 'id' => $id_luis ) ) && is_file( $guardada ) );
	ZN_Participaciones::limpiar( time() + 38 * DAY_IN_SECONDS );
	$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE id = %d', $id_ana ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$ok( 'decisión 1: fin y entrega + 30 días: se borran todas las fotos del sorteo', ! is_file( $guardada ) && ! is_file( $foto_luis ) );
	$ok( 'y las participaciones de quien no ganó', 0 === $filas( array( 'id' => $id_luis ) ) );
	$ok( 'a la ganadora se le quitan nombre, correo y foto (solo queda que ganó)', $fila && '' === (string) $fila['nombre'] && '' === (string) $fila['correo'] && '' === (string) $fila['ticket'] );
	$sin_entrega = $sorteo( $comercio, '-120 days', '-91 days' );
	$wpdb->insert( ZN_Participaciones::tabla(), array( 'draw_id' => $sin_entrega, 'user_id' => $luis, 'ticket' => '', 'adulto_en' => current_time( 'mysql', true ), 'date' => current_time( 'mysql', true ) ) );
	$sin_entrega_id = (int) $wpdb->insert_id;
	$reciente = $sorteo( $comercio, '-60 days', '-89 days' );
	$wpdb->insert( ZN_Participaciones::tabla(), array( 'draw_id' => $reciente, 'user_id' => $luis, 'ticket' => '', 'adulto_en' => current_time( 'mysql', true ), 'date' => current_time( 'mysql', true ) ) );
	$reciente_id = (int) $wpdb->insert_id;
	// R133: un ganador sin premio entregado a los 90 días del fin: también se borran su nombre y su correo.
	$wpdb->insert( ZN_Participaciones::tabla(), array( 'draw_id' => $sin_entrega, 'user_id' => $ana, 'ticket' => '', 'adulto_en' => current_time( 'mysql', true ), 'date' => current_time( 'mysql', true ), 'ganador' => 1, 'nombre' => 'Ana María', 'correo' => 'ana-temporal-participa@ejemplo.invalid' ) );
	$ganador_viejo = (int) $wpdb->insert_id;
	// R136: una foto que se ha quedado sin fila (por un unlink que falló o un cambio de carpeta) se barre.
	$huerfana = ZN_Participaciones::carpeta() . '/' . str_repeat( 'a', 32 ) . '.jpg';
	file_put_contents( $huerfana, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	touch( $huerfana, time() - 2 * DAY_IN_SECONDS );
	ZN_Participaciones::limpiar();
	$ok( 'decisión b: sin entrega, a los 90 días del fin se borra', 0 === $filas( array( 'id' => $sin_entrega_id ) ) );
	$ok( '… pero a los 89, todavía no', 1 === $filas( array( 'id' => $reciente_id ) ) );
	$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE id = %d', $ganador_viejo ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$ok( 'R133: mandan los 90 días: al ganador sin premio se le borran también nombre y correo', ! $fila || ( '' === (string) $fila['nombre'] && '' === (string) $fila['correo'] ) );
	$ok( 'R136: la tarea barre las fotos que no tienen fila', ! is_file( $huerfana ) );

	// --- RGPD (R65, decisión 7) ---
	$abierto2 = $sorteo( $comercio );
	$xoan     = $cuenta( 'zn_usuario', 'Xoán' );
	list( , $cuerpo ) = $llamar( 'POST', '/draw-participation', $xoan, array( 'draw' => $abierto2, 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ) );
	$id_xoan = (int) ( $cuerpo['data']['_id'] ?? 0 );
	$exportar = apply_filters( 'wp_privacy_personal_data_exporters', array() )['zona-nautico']['callback'];
	$export   = call_user_func( $exportar, get_userdata( $xoan )->user_email, 1 );
	$todo     = wp_json_encode( $export, JSON_UNESCAPED_UNICODE );
	$ok( 'exportar datos personales incluye sus participaciones, sin la foto', in_array( 'zn-participaciones', array_column( $export['data'] ?? array(), 'group_id' ), true ) && false !== strpos( $todo, get_the_title( $abierto2 ) ) && false === strpos( $todo, '.jpg' ) );
	$llamar( 'PATCH', "/draw-participation/$id_xoan", $comercio, array( 'winner' => true ) );
	$borrar = apply_filters( 'wp_privacy_personal_data_erasers', array() )['zona-nautico']['callback'];
	call_user_func( $borrar, get_userdata( $xoan )->user_email, 1 );
	$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE id = %d', $id_xoan ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$ok( 'decisión 7: borrar datos personales de un ganador sin premio entregado guarda su nombre y correo (sin foto)', $fila && '' !== (string) $fila['correo'] && '' === (string) $fila['ticket'] );
	wp_delete_user( $xoan );
	$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE id = %d', $id_xoan ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$ok( 'decisión 7: al borrar su cuenta, también se guardan nombre y correo hasta la entrega', $fila && '' !== (string) $fila['correo'] );
	$llamar( 'PATCH', "/draw-participation/$id_xoan", $comercio, array( 'delivered' => true ) );
	$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE id = %d', $id_xoan ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$ok( 'al entregar el premio de una cuenta ya borrada, sus datos se van', ! $fila || '' === (string) $fila['correo'] );
	$teo = $cuenta( 'zn_usuario', 'Teo' );
	$llamar( 'POST', '/draw-participation', $teo, array( 'draw' => $abierto2, 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ) );
	wp_delete_user( $teo );
	$ok( 'R65: borrar la cuenta de quien no ha ganado borra su participación', 0 === $filas( array( 'user_id' => $teo ) ) );
	$uxia = $cuenta( 'zn_usuario', 'Uxía' );
	list( , $cuerpo ) = $llamar( 'POST', '/draw-participation', $uxia, array( 'draw' => $abierto2, 'adult' => '1' ), array( 'ticket' => $archivo( $foto() ) ) );
	$foto_uxia = ZN_Participaciones::ruta_ticket( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE id = %d', (int) ( $cuerpo['data']['_id'] ?? 0 ) ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	wp_delete_post( $abierto2, true );
	$ok( 'R65: borrar el sorteo borra sus participaciones y fotos', 0 === $filas( array( 'draw_id' => $abierto2, 'user_id' => $uxia ) ) && ! is_file( $foto_uxia ) );
} finally {
	$claves = array();
	foreach ( array( '127.0.0.1', '::1', '192.0.2.98', '192.0.2.99' ) as $ip ) {
		$_SERVER['REMOTE_ADDR'] = $ip;
		foreach ( $emails as $direccion ) {
			$claves = array_merge( $claves, array_keys( ZN_Limite::claves( 'escritura', $direccion ) ) );
		}
	}
	$_SERVER = $servidor;
	foreach ( $entradas as $entrada ) {
		if ( is_int( $entrada ) && get_post( $entrada ) ) {
			wp_delete_post( $entrada, true );
		}
	}
	foreach ( $cuentas as $id ) {
		if ( get_userdata( $id ) ) {
			wp_delete_user( $id );
		}
	}
	if ( $entradas ) {
		$filas_restantes = $wpdb->get_results( 'SELECT * FROM ' . ZN_Participaciones::tabla() . ' WHERE draw_id IN (' . implode( ',', array_map( 'intval', $entradas ) ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $filas_restantes as $f ) {
			ZN_Participaciones::borrar_fila( $f );
		}
	}
	if ( $cuentas ) {
		$wpdb->query( 'DELETE FROM ' . ZN_Tokens::tabla() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $cuentas ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	foreach ( array_chunk( array_unique( $claves ), 100 ) as $trozo ) {
		$wpdb->query( 'DELETE FROM ' . ZN_Limite::tabla() . " WHERE clave IN ('" . implode( "','", array_map( 'esc_sql', $trozo ) ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	foreach ( $temporales as $t ) {
		if ( is_file( $t ) ) {
			unlink( $t ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
}
$quedan  = array_filter( $cuentas, static fn( $id ) => false !== get_userdata( $id ) );
$sueltas = $entradas ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZN_Participaciones::tabla() . ' WHERE draw_id IN (' . implode( ',', array_map( 'intval', $entradas ) ) . ')' ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$ok( sprintf( 'limpieza: borradas %d cuentas, %d sorteos, sus participaciones y fotos', count( $cuentas ), count( $entradas ) ), ! $quedan && 0 === $sueltas );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

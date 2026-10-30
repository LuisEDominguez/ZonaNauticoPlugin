<?php
// Solo con WP-CLI (wp eval-file): abierto desde la web no hace nada (R55).
defined( 'WP_CLI' ) || exit;
// Crea datos temporales: solo en un WordPress local (R61).
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba crea datos: solo en un WordPress local (WP_ENVIRONMENT_TYPE = local).' );
}
// Comprobaciones de reseñas y preguntas por dentro. Uso: wp eval-file tests/comentarios.php
// Crea una reseña temporal en «Náutica Cíes (prueba)» y la borra al terminar.

$fallos = 0;
$ok     = function ( $nombre, $condicion ) use ( &$fallos ) {
	echo ( $condicion ? 'ok    ' : 'FALLO ' ) . $nombre . "\n";
	$fallos += $condicion ? 0 : 1;
};

$nautica = get_posts( array( 'post_type' => ZN_Negocios::TIPO, 'title' => 'Náutica Cíes (prueba)', 'numberposts' => 1, 'fields' => 'ids' ) )[0] ?? 0;
if ( ! $nautica ) {
	WP_CLI::error( 'Falta «Náutica Cíes (prueba)»: wp zona-nautico datos-prueba' );
}
$puntos = static fn() => (float) get_post_meta( $nautica, 'score', true );

// --- Comentarios nativos cerrados ---
$ok( 'no se puede comentar un negocio por el formulario de WordPress', ! comments_open( $nautica ) );
$ok( 'ni enviarle pings', ! pings_open( $nautica ) );
$ok( 'la ruta /wp/v2/comments no existe', ! array_key_exists( '/wp/v2/comments', rest_get_server()->get_routes() ) );
$ok( 'fuera de wp-admin no salen en consultas normales de comentarios', ! array_filter(
	get_comments( array( 'status' => 'approve' ) ),
	static fn( $c ) => in_array( $c->comment_type, array( ZN_Comentarios::RESENA, ZN_Comentarios::PREGUNTA ), true )
) );

// --- Puntuación = media de las reseñas aprobadas ---
$ok( 'Náutica empieza con 3', 3.0 === $puntos() );
$temporal = wp_insert_comment(
	array(
		'comment_post_ID'  => $nautica,
		'comment_type'     => ZN_Comentarios::RESENA,
		'comment_author'   => 'Temporal Prueba',
		'comment_approved' => 1,
		'comment_meta'     => array( 'score' => 1 ),
	)
);
$ok( 'una reseña nueva de 1 baja la media a 2', 2.0 === $puntos() );
wp_set_comment_status( $temporal, 'hold' );
$ok( 'al quitarle la aprobación deja de contar', 3.0 === $puntos() );
wp_set_comment_status( $temporal, 'approve' );
update_comment_meta( $temporal, 'score', 2 );
do_action( 'edit_comment', $temporal, array() );
$ok( 'al editarla se recalcula (media 2,5)', 2.5 === $puntos() );
wp_trash_comment( $temporal );
$ok( 'a la papelera: deja de contar', 3.0 === $puntos() );
wp_delete_comment( $temporal, true );
$ok( 'borrada del todo: sigue en 3', 3.0 === $puntos() );

// --- Fichas: lo no aprobado no se ve ---
$pendientes = get_comments( array( 'type__in' => array( ZN_Comentarios::RESENA, ZN_Comentarios::PREGUNTA ), 'status' => 'hold' ) );
$ok( 'hay una reseña y una pregunta sin aprobar de prueba', 2 === count( $pendientes ) );
foreach ( $pendientes as $c ) {
	$ok( "la ficha de la {$c->comment_type} sin aprobar da 404", null === ZN_API::comentario( (int) $c->comment_ID, $c->comment_type ) );
}

// --- Usuario público: nunca el correo ---
$dueno = ZN_API::usuario_publico( (int) get_post_field( 'post_author', get_posts( array( 'post_type' => ZN_Negocios::TIPO, 'title' => 'Taberna O Peirao (prueba)', 'numberposts' => 1, 'fields' => 'ids' ) )[0] ) );
$ok( 'usuario público: solo _id, name, role y avatar', array( '_id', 'name', 'role', 'avatar' ) === array_keys( $dueno ) );
$ok( 'sin cuenta ni nombre no hay usuario', null === ZN_API::usuario_publico( 0 ) );

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";

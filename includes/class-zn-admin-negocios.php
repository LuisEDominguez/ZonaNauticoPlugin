<?php
/**
 * Ficha del negocio en wp-admin: casillas para los campos extra del tipo `negocio`.
 * La puntuación no se edita aquí: la calcularán las reseñas (H14 del Laravel).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Admin_Negocios {

	// Mismos ids que la API Laravel; nombres provisionales del export de Adalo (src/data/provisional de la app).
	const CATEGORIAS = array(
		2 => 'Comercio',
		4 => 'Alimentación',
		6 => 'Servicios',
		1 => 'Cafeterías, café-bar, terrazas',
		3 => 'Restauración',
		5 => 'Hospedaje',
		7 => 'Ocio nocturno',
	);

	// Casillas de texto de una línea: campo => etiqueta.
	const TEXTOS = array(
		'address'  => 'Dirección',
		'postcode' => 'Código postal',
		'city'     => 'Ciudad',
		'state'    => 'Provincia',
		'country'  => 'País (2 letras, p. ej. ES)',
		'phone'    => 'Teléfono',
	);

	public static function iniciar(): void {
		ZN_Admin_Campos::usar_fotos( ZN_Negocios::TIPO );
		add_action( 'add_meta_boxes_' . ZN_Negocios::TIPO, array( __CLASS__, 'anadir_cajas' ) );
		add_action( 'save_post_' . ZN_Negocios::TIPO, array( __CLASS__, 'guardar' ) );
	}

	public static function anadir_cajas(): void {
		add_meta_box( 'zn-datos-negocio', 'Datos del negocio', array( __CLASS__, 'caja_datos' ), ZN_Negocios::TIPO, 'normal', 'high' );
		add_meta_box( 'zn-fotos-negocio', 'Fotos (hasta 3)', array( 'ZN_Admin_Campos', 'caja_fotos' ), ZN_Negocios::TIPO, 'normal', 'default' );
		add_meta_box( 'zn-puntuacion-negocio', 'Puntuación', array( __CLASS__, 'caja_puntuacion' ), ZN_Negocios::TIPO, 'side', 'default' );
	}

	public static function caja_datos( WP_Post $entrada ): void {
		ZN_Admin_Campos::sello();
		$id        = $entrada->ID;
		$categoria = (int) ZN_Admin_Campos::valor( $id, 'business_category_id' );
		?>
		<p class="description">El nombre va en el título y la descripción en el cuadro grande de arriba.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="zn-categoria">Categoría</label></th>
				<td>
					<select id="zn-categoria" name="zn[business_category_id]">
						<option value="">— Sin categoría —</option>
						<?php foreach ( self::CATEGORIAS as $valor => $nombre ) : ?>
							<option value="<?php echo esc_attr( $valor ); ?>" <?php selected( $categoria, $valor ); ?>><?php echo esc_html( $nombre ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php foreach ( self::TEXTOS as $campo => $etiqueta ) : ?>
				<tr>
					<th><label for="zn-<?php echo esc_attr( $campo ); ?>"><?php echo esc_html( $etiqueta ); ?></label></th>
					<td><input type="text" class="regular-text" id="zn-<?php echo esc_attr( $campo ); ?>" name="zn[<?php echo esc_attr( $campo ); ?>]" value="<?php echo esc_attr( ZN_Admin_Campos::valor( $id, $campo ) ); ?>"></td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th><label for="zn-email">Email de contacto</label></th>
				<td><input type="email" class="regular-text" id="zn-email" name="zn[email]" value="<?php echo esc_attr( ZN_Admin_Campos::valor( $id, 'email' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="zn-web">Web</label></th>
				<td><input type="url" class="regular-text" id="zn-web" name="zn[web]" placeholder="https://" value="<?php echo esc_attr( ZN_Admin_Campos::valor( $id, 'web' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="zn-schedule">Horario</label></th>
				<td>
					<textarea class="large-text" rows="7" id="zn-schedule" name="zn[schedule]"><?php echo esc_textarea( ZN_Admin_Campos::valor( $id, 'schedule' ) ); ?></textarea>
					<p class="description">Un día por línea, por ejemplo: «lunes: 10:00–14:00, 16:30–20:30».</p>
				</td>
			</tr>
			<?php ZN_Admin_Campos::fila_coordenadas( $id ); ?>
		</table>
		<?php
	}

	public static function caja_puntuacion( WP_Post $entrada ): void {
		$puntos = ZN_Admin_Campos::valor( $entrada->ID, 'score' );
		printf(
			'<p><strong>%s</strong> de 5</p><p class="description">Se calcula sola con las reseñas; no se puede cambiar a mano.</p>',
			esc_html( '' === $puntos ? '0' : $puntos )
		);
	}

	public static function guardar( int $id ): void {
		$datos = ZN_Admin_Campos::datos_enviados( $id );
		if ( null === $datos ) {
			return;
		}
		$errores = array();
		ZN_Admin_Campos::guardar( $id, self::limpiar( $datos, $errores ), $errores );
	}

	/**
	 * Valida y limpia lo que llega del formulario. Devuelve campo => valor (null = borrar).
	 * Un valor no válido no se guarda (se conserva el anterior) y se añade a $errores.
	 */
	public static function limpiar( array $datos, array &$errores ): array {
		$limpio = array();
		$texto  = static fn( $clave ) => ZN_Admin_Campos::texto( $datos, $clave );

		foreach ( array_keys( self::TEXTOS ) as $campo ) {
			$limpio[ $campo ] = '' === $texto( $campo ) ? null : sanitize_text_field( $texto( $campo ) );
		}
		if ( null !== $limpio['country'] ) {
			$limpio['country'] = strtoupper( substr( $limpio['country'], 0, 2 ) );
		}

		$categoria = $texto( 'business_category_id' );
		if ( '' === $categoria ) {
			$limpio['business_category_id'] = null;
		} elseif ( isset( self::CATEGORIAS[ (int) $categoria ] ) ) {
			$limpio['business_category_id'] = (int) $categoria;
		}

		$email = $texto( 'email' );
		if ( '' === $email ) {
			$limpio['email'] = null;
		} elseif ( is_email( $email ) ) {
			$limpio['email'] = sanitize_email( $email );
		} else {
			$errores[] = 'El email no es válido; no se ha cambiado.';
		}

		$web = $texto( 'web' );
		if ( '' === $web ) {
			$limpio['web'] = null;
		} else {
			$web = preg_match( '#^https?://#i', $web ) ? $web : 'https://' . $web;
			$url = esc_url_raw( $web, array( 'http', 'https' ) );
			if ( $url && wp_http_validate_url( $url ) ) {
				$limpio['web'] = $url;
			} else {
				$errores[] = 'La web no es válida; no se ha cambiado.';
			}
		}

		// Los horarios del Laravel separan día y horas con tabulador; sanitize_textarea_field lo convertiría en espacio.
		$horario            = str_replace( "\t", ': ', $texto( 'schedule' ) );
		$limpio['schedule'] = '' === $horario ? null : sanitize_textarea_field( $horario );

		ZN_Admin_Campos::limpiar_coordenadas( $datos, $limpio, $errores );
		ZN_Admin_Campos::limpiar_urls( $datos, 'photo', $limpio );

		return $limpio;
	}
}

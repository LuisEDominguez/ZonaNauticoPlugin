<?php
/**
 * Ficha del negocio en wp-admin: casillas para los campos extra del tipo `negocio`.
 * La puntuación no se edita aquí: la calcularán las reseñas (H14 del Laravel).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Admin_Negocios {

	const NONCE = 'zn_guardar_negocio';

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
		add_action( 'add_meta_boxes_' . ZN_Negocios::TIPO, array( __CLASS__, 'anadir_cajas' ) );
		add_action( 'save_post_' . ZN_Negocios::TIPO, array( __CLASS__, 'guardar' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'cargar_scripts' ) );
		add_action( 'admin_notices', array( __CLASS__, 'avisos' ) );
	}

	public static function anadir_cajas(): void {
		add_meta_box( 'zn-datos-negocio', 'Datos del negocio', array( __CLASS__, 'caja_datos' ), ZN_Negocios::TIPO, 'normal', 'high' );
		add_meta_box( 'zn-fotos-negocio', 'Fotos (hasta 3)', array( __CLASS__, 'caja_fotos' ), ZN_Negocios::TIPO, 'normal', 'default' );
		add_meta_box( 'zn-puntuacion-negocio', 'Puntuación', array( __CLASS__, 'caja_puntuacion' ), ZN_Negocios::TIPO, 'side', 'default' );
	}

	public static function cargar_scripts( string $pantalla ): void {
		$actual = get_current_screen();
		if ( ! $actual || ZN_Negocios::TIPO !== $actual->post_type || ! in_array( $pantalla, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'zn-admin-negocios', plugins_url( 'assets/admin-negocios.js', __DIR__ ), array( 'jquery' ), ZN_VERSION, true );
	}

	private static function valor( int $id, string $campo ): string {
		return (string) get_post_meta( $id, $campo, true );
	}

	public static function caja_datos( WP_Post $entrada ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		$id        = $entrada->ID;
		$categoria = (int) self::valor( $id, 'business_category_id' );
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
					<td><input type="text" class="regular-text" id="zn-<?php echo esc_attr( $campo ); ?>" name="zn[<?php echo esc_attr( $campo ); ?>]" value="<?php echo esc_attr( self::valor( $id, $campo ) ); ?>"></td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th><label for="zn-email">Email de contacto</label></th>
				<td><input type="email" class="regular-text" id="zn-email" name="zn[email]" value="<?php echo esc_attr( self::valor( $id, 'email' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="zn-web">Web</label></th>
				<td><input type="url" class="regular-text" id="zn-web" name="zn[web]" placeholder="https://" value="<?php echo esc_attr( self::valor( $id, 'web' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="zn-schedule">Horario</label></th>
				<td>
					<textarea class="large-text" rows="7" id="zn-schedule" name="zn[schedule]"><?php echo esc_textarea( self::valor( $id, 'schedule' ) ); ?></textarea>
					<p class="description">Un día por línea, por ejemplo: «lunes: 10:00–14:00, 16:30–20:30».</p>
				</td>
			</tr>
			<tr>
				<th>Ubicación en el mapa</th>
				<td>
					<label>Latitud <input type="text" inputmode="decimal" size="12" name="zn[latitude]" value="<?php echo esc_attr( self::valor( $id, 'latitude' ) ); ?>"></label>
					&nbsp;
					<label>Longitud <input type="text" inputmode="decimal" size="12" name="zn[longitude]" value="<?php echo esc_attr( self::valor( $id, 'longitude' ) ); ?>"></label>
					<p class="description">En Google Maps: clic derecho sobre el local y pulsa los números que aparecen arriba (se copian los dos). Ejemplo: 42.2388906, -8.7259422</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function caja_fotos( WP_Post $entrada ): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$url = self::valor( $entrada->ID, "photo_{$i}" );
			?>
			<div class="zn-foto" style="display:inline-block;vertical-align:top;width:200px;margin:0 16px 16px 0">
				<img src="<?php echo esc_url( $url ); ?>" alt="" style="width:200px;height:140px;object-fit:cover;background:#f0f0f1;<?php echo $url ? '' : 'display:none;'; ?>">
				<input type="hidden" name="zn[photo_<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $url ); ?>">
				<p>
					<button type="button" class="button zn-elegir-foto">Elegir foto <?php echo (int) $i + 1; ?></button>
					<button type="button" class="button-link zn-quitar-foto" <?php echo $url ? '' : 'style="display:none"'; ?>>Quitar</button>
				</p>
			</div>
			<?php
		}
	}

	public static function caja_puntuacion( WP_Post $entrada ): void {
		$puntos = self::valor( $entrada->ID, 'score' );
		printf(
			'<p><strong>%s</strong> de 5</p><p class="description">Se calcula sola con las reseñas; no se puede cambiar a mano.</p>',
			esc_html( '' === $puntos ? '0' : $puntos )
		);
	}

	public static function guardar( int $id, WP_Post $entrada ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}

		$datos   = isset( $_POST['zn'] ) && is_array( $_POST['zn'] ) ? wp_unslash( $_POST['zn'] ) : array();
		$errores = array();

		foreach ( self::limpiar( $datos, $errores ) as $campo => $valor ) {
			if ( null === $valor ) {
				delete_post_meta( $id, $campo );
			} else {
				update_post_meta( $id, $campo, $valor );
			}
		}

		if ( $errores ) {
			set_transient( 'zn_avisos_' . get_current_user_id(), $errores, 60 );
		}
	}

	/**
	 * Valida y limpia lo que llega del formulario. Devuelve campo => valor (null = borrar).
	 * Un valor no válido no se guarda (se conserva el anterior) y se añade a $errores.
	 */
	public static function limpiar( array $datos, array &$errores ): array {
		$limpio = array();
		$texto  = static fn( $clave ) => isset( $datos[ $clave ] ) && is_string( $datos[ $clave ] ) ? trim( $datos[ $clave ] ) : '';

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

		foreach ( array( 'latitude' => 90, 'longitude' => 180 ) as $campo => $limite ) {
			$numero = str_replace( ',', '.', $texto( $campo ) );
			if ( '' === $numero ) {
				$limpio[ $campo ] = null;
			} elseif ( is_numeric( $numero ) && abs( (float) $numero ) <= $limite ) {
				$limpio[ $campo ] = (string) round( (float) $numero, 7 );
			} else {
				$errores[] = ( 'latitude' === $campo ? 'La latitud' : 'La longitud' ) . ' no es válida; no se ha cambiado.';
			}
		}

		for ( $i = 0; $i < 3; $i++ ) {
			$foto                   = esc_url_raw( $texto( "photo_{$i}" ), array( 'http', 'https' ) );
			$limpio[ "photo_{$i}" ] = '' === $foto ? null : $foto;
		}

		return $limpio;
	}

	public static function avisos(): void {
		$clave   = 'zn_avisos_' . get_current_user_id();
		$errores = get_transient( $clave );
		if ( ! $errores ) {
			return;
		}
		delete_transient( $clave );
		echo '<div class="notice notice-warning is-dismissible"><p>' . implode( '<br>', array_map( 'esc_html', $errores ) ) . '</p></div>';
	}
}

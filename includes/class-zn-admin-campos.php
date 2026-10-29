<?php
/**
 * Piezas comunes de las fichas de wp-admin: sello de seguridad, fotos, coordenadas y avisos.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Admin_Campos {

	const NONCE = 'zn_guardar_ficha';

	/** Tipos de entrada que usan la caja de fotos (para cargar la biblioteca de medios). */
	private static array $tipos_con_fotos = array();

	public static function iniciar(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'cargar_scripts' ) );
		add_action( 'admin_notices', array( __CLASS__, 'avisos' ) );
	}

	public static function usar_fotos( string $tipo ): void {
		self::$tipos_con_fotos[] = $tipo;
	}

	public static function cargar_scripts( string $pantalla ): void {
		$actual = get_current_screen();
		if ( ! $actual || ! in_array( $actual->post_type, self::$tipos_con_fotos, true ) || ! in_array( $pantalla, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'zn-admin-fotos', plugins_url( 'assets/admin-fotos.js', __DIR__ ), array( 'jquery' ), ZN_VERSION, true );
	}

	public static function valor( int $id, string $campo ): string {
		return (string) get_post_meta( $id, $campo, true );
	}

	public static function sello(): void {
		wp_nonce_field( self::NONCE, self::NONCE );
	}

	/** Lo enviado en zn[...] si el guardado es legítimo (sello válido, no autoguardado, con permiso); si no, null. */
	public static function datos_enviados( int $id ): ?array {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return null;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return null;
		}
		return isset( $_POST['zn'] ) && is_array( $_POST['zn'] ) ? wp_unslash( $_POST['zn'] ) : array();
	}

	/** Guarda campo => valor (null = borrar) y deja los avisos para la siguiente pantalla. */
	public static function guardar( int $id, array $limpio, array $errores ): void {
		foreach ( $limpio as $campo => $valor ) {
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

	public static function texto( array $datos, string $clave ): string {
		return isset( $datos[ $clave ] ) && is_string( $datos[ $clave ] ) ? trim( $datos[ $clave ] ) : '';
	}

	public static function fila_coordenadas( int $id ): void {
		?>
		<tr>
			<th>Ubicación en el mapa</th>
			<td>
				<label>Latitud <input type="text" inputmode="decimal" size="12" name="zn[latitude]" value="<?php echo esc_attr( self::valor( $id, 'latitude' ) ); ?>"></label>
				&nbsp;
				<label>Longitud <input type="text" inputmode="decimal" size="12" name="zn[longitude]" value="<?php echo esc_attr( self::valor( $id, 'longitude' ) ); ?>"></label>
				<p class="description">En Google Maps: clic derecho sobre el sitio y pulsa los números que aparecen arriba (se copian los dos). Ejemplo: 42.2388906, -8.7259422</p>
			</td>
		</tr>
		<?php
	}

	public static function limpiar_coordenadas( array $datos, array &$limpio, array &$errores ): void {
		foreach ( array( 'latitude' => 90, 'longitude' => 180 ) as $campo => $limite ) {
			$numero = str_replace( ',', '.', self::texto( $datos, $campo ) );
			if ( '' === $numero ) {
				$limpio[ $campo ] = null;
			} elseif ( is_numeric( $numero ) && abs( (float) $numero ) <= $limite ) {
				$limpio[ $campo ] = (string) round( (float) $numero, 7 );
			} else {
				$errores[] = ( 'latitude' === $campo ? 'La latitud' : 'La longitud' ) . ' no es válida; no se ha cambiado.';
			}
		}
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

	/** Fotos y vídeos: solo direcciones http(s); cualquier otra cosa se descarta. */
	public static function limpiar_urls( array $datos, string $prefijo, array &$limpio ): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$url                        = esc_url_raw( self::texto( $datos, "{$prefijo}_{$i}" ), array( 'http', 'https' ) );
			$limpio[ "{$prefijo}_{$i}" ] = '' === $url ? null : $url;
		}
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

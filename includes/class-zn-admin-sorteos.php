<?php
/**
 * Ficha del sorteo en wp-admin: fechas, número de ganadores, bases legales, fotos y vídeos.
 * En el Laravel fechas y ganadores son obligatorios; aquí, si faltan, se avisa al guardar.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Admin_Sorteos {

	public static function iniciar(): void {
		ZN_Admin_Campos::usar_fotos( ZN_Sorteos::TIPO );
		add_action( 'add_meta_boxes_' . ZN_Sorteos::TIPO, array( __CLASS__, 'anadir_cajas' ) );
		add_action( 'save_post_' . ZN_Sorteos::TIPO, array( __CLASS__, 'guardar' ) );
	}

	public static function anadir_cajas(): void {
		add_meta_box( 'zn-datos-sorteo', 'Datos del sorteo', array( __CLASS__, 'caja_datos' ), ZN_Sorteos::TIPO, 'normal', 'high' );
		add_meta_box( 'zn-fotos-sorteo', 'Fotos (hasta 3)', array( 'ZN_Admin_Campos', 'caja_fotos' ), ZN_Sorteos::TIPO, 'normal', 'default' );
		add_meta_box( 'zn-videos-sorteo', 'Vídeos (opcional)', array( 'ZN_Admin_Campos', 'caja_videos' ), ZN_Sorteos::TIPO, 'normal', 'low' );
		add_meta_box( 'zn-estado-sorteo', 'Estado en la app', array( __CLASS__, 'caja_estado' ), ZN_Sorteos::TIPO, 'side', 'default' );
	}

	public static function caja_datos( WP_Post $entrada ): void {
		ZN_Admin_Campos::sello();
		$id        = $entrada->ID;
		$ganadores = ZN_Admin_Campos::valor( $id, 'winners' );
		?>
		<p class="description">El título va arriba y las condiciones en el cuadro grande.</p>
		<table class="form-table" role="presentation">
			<?php ZN_Admin_Campos::filas_fechas( $id, 'Obligatorias. Mientras la fecha de hoy esté entre las dos, la app deja participar. Hora de España.' ); ?>
			<tr>
				<th><label for="zn-ganadores">Ganadores</label></th>
				<td><input type="number" min="1" step="1" class="small-text" id="zn-ganadores" name="zn[winners]" value="<?php echo esc_attr( '' === $ganadores ? '1' : $ganadores ); ?>"></td>
			</tr>
			<tr>
				<th><label for="zn-bases">Bases legales</label></th>
				<td><input type="url" class="regular-text" id="zn-bases" name="zn[legal_url]" placeholder="https://" value="<?php echo esc_attr( ZN_Admin_Campos::valor( $id, 'legal_url' ) ); ?>"></td>
			</tr>
		</table>
		<?php
	}

	public static function caja_estado( WP_Post $entrada ): void {
		$sorteo = ZN_API_Sorteos::formatear( $entrada );
		if ( 'publish' !== $entrada->post_status ) {
			$estado = 'No se ve en la app: aún no está publicado.';
		} elseif ( $sorteo['controlParticipar'] ) {
			$estado = 'Abierto: se ve y se puede participar.';
		} elseif ( $sorteo['controlVisibilidad'] ) {
			$estado = 'Se ve, pero todavía no se puede participar (no ha empezado o faltan fechas).';
		} else {
			$estado = 'Terminado: ya no se puede participar.';
		}
		echo '<p>' . esc_html( $estado ) . '</p><p class="description">Se calcula solo con las fechas.</p>';
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

		ZN_Admin_Campos::limpiar_fechas( $datos, $limpio, $errores );
		if ( ( array_key_exists( 'start_date', $limpio ) && null === $limpio['start_date'] ) || ( array_key_exists( 'end_date', $limpio ) && null === $limpio['end_date'] ) ) {
			$errores[] = 'Faltan fechas: sin inicio y fin el sorteo no se abrirá en la app.';
		}

		$ganadores = ZN_Admin_Campos::texto( $datos, 'winners' );
		if ( ctype_digit( $ganadores ) && (int) $ganadores >= 1 ) {
			$limpio['winners'] = (int) $ganadores;
		} else {
			$errores[] = 'El número de ganadores tiene que ser 1 o más; no se ha cambiado.';
		}

		$bases = ZN_Admin_Campos::texto( $datos, 'legal_url' );
		if ( '' === $bases ) {
			$limpio['legal_url'] = null;
		} else {
			$url = esc_url_raw( preg_match( '#^https?://#i', $bases ) ? $bases : 'https://' . $bases, array( 'http', 'https' ) );
			if ( $url && wp_http_validate_url( $url ) ) {
				$limpio['legal_url'] = $url;
			} else {
				$errores[] = 'La dirección de las bases no es válida; no se ha cambiado.';
			}
		}

		ZN_Admin_Campos::limpiar_urls( $datos, 'photo', $limpio );
		ZN_Admin_Campos::limpiar_urls( $datos, 'video', $limpio );

		return $limpio;
	}
}
